<?php

namespace App\Services;

use App\DTOs\CheckInData;
use App\Jobs\ProcessAttendanceLog;
use Illuminate\Support\Facades\Redis;

/**
 * Service: Xử lý business logic của hệ thống chấm công.
 *
 * Kiến trúc luồng check-in cao điểm (8h00 sáng):
 * ┌──────────┐    ┌─────────┐    ┌───────────────┐    ┌──────────────┐
 * │ HTTP Req │───>│  Redis  │───>│ Laravel Horizon│───>│  PostgreSQL  │
 * │ (5000/m) │    │ (buffer)│    │   (workers)    │    │ (partitioned)│
 * └──────────┘    └─────────┘    └───────────────┘    └──────────────┘
 *   202 ms         <1 ms          ~50-100 workers        controlled
 *
 * Tại sao Redis làm buffer trước Queue?
 *  - Rate limiting: Mỗi nhân viên chỉ được check-in 1 lần/5 phút.
 *  - Idempotency check: Phát hiện duplicate request ngay ở tầng Redis,
 *    không cần chờ worker xử lý.
 *  - Fast response: HTTP client nhận 202 Accepted trong <10ms,
 *    không chờ DB write hoàn tất.
 */
class AttendanceService
{
    /**
     * Prefix key trong Redis để tránh conflict với các module khác.
     * Format: attendance:{action}:{employee_id}
     */
    private const REDIS_LOCK_PREFIX = 'attendance:lock:';

    private const REDIS_DEDUP_PREFIX = 'attendance:dedup:';

    /**
     * TTL của idempotency key trong Redis (giây).
     * Trong 5 phút, cùng một yêu cầu check-in sẽ bị reject là duplicate.
     */
    private const DEDUP_TTL_SECONDS = 300; // 5 phút

    /**
     * TTL của lock key: đủ để worker xử lý xong một job.
     */
    private const LOCK_TTL_SECONDS = 30;

    /**
     * Xử lý yêu cầu check-in/check-out.
     *
     * @return array{success: bool, message: string, queued: bool, idempotency_key: string}
     */
    public function handleCheckIn(CheckInData $data): array
    {
        // ── Bước 1: Tạo Idempotency Key ────────────────────────────────────
        // Key unique cho mỗi (nhân viên, action, ngày).
        // Nếu key này đã tồn tại trong Redis → request là duplicate → reject ngay.
        $dedupKey = $this->buildDedupKey($data);
        $isNew = $this->setDedupKeyIfAbsent($dedupKey);

        if (! $isNew) {
            return [
                'success' => false,
                'message' => 'Yêu cầu chấm công đã được xử lý. Vui lòng không gửi trùng.',
                'queued' => false,
                'idempotency_key' => $dedupKey,
            ];
        }

        // ── Bước 2: Lưu snapshot vào Redis Stream (audit trail tạm thời) ───
        // Redis Stream là append-only log, phù hợp cho event sourcing nhẹ.
        // Dữ liệu sẽ được giữ 1 giờ trong Redis để debugging nếu cần.
        $this->saveToRedisStream($data);

        // ── Bước 3: Dispatch Job vào Queue (Redis-backed) ───────────────────
        // Job sẽ được Horizon pick up và xử lý asynchronously.
        // onQueue('attendance'): đưa vào queue chuyên dụng, có thể scale riêng.
        ProcessAttendanceLog::dispatch($data)
            ->onQueue('attendance')
            ->delay(now()->addSeconds(2)); // Delay nhỏ để batch các request cùng lúc

        return [
            'success' => true,
            'message' => 'Chấm công đã được ghi nhận. Đang xử lý...',
            'queued' => true,
            'idempotency_key' => $dedupKey,
        ];
    }

    /**
     * Tạo idempotency key dựa trên employee_id, action, và ngày hiện tại.
     * Trong cùng một ngày, cùng một action sẽ có cùng key.
     */
    private function buildDedupKey(CheckInData $data): string
    {
        $date = now()->toDateString(); // YYYY-MM-DD

        return self::REDIS_DEDUP_PREFIX."{$data->employeeId}:{$data->action}:{$date}";
    }

    /**
     * SET key nếu chưa tồn tại (NX = Not eXists) với TTL.
     * Đây là atomic operation của Redis → thread-safe, không cần mutex.
     *
     * Redis command: SET key value NX EX ttl
     * Trả về true nếu key được set thành công (lần đầu), false nếu đã tồn tại.
     */
    private function setDedupKeyIfAbsent(string $key): bool
    {
        // phpredis client: set($key, $value, ['NX', 'EX' => $ttl])
        // Trả về TRUE nếu set thành công, FALSE nếu key đã tồn tại
        $result = Redis::connection('default')->set(
            $key,
            now()->toIso8601String(), // Lưu timestamp để debug
            'EX',                     // Expire in seconds
            self::DEDUP_TTL_SECONDS,
            'NX'                      // Only set if Not eXists
        );

        return $result !== false && $result !== null;
    }

    /**
     * Ghi sự kiện vào Redis Stream để làm audit trail tạm thời.
     * Redis Stream là cấu trúc dữ liệu log append-only, không mất dữ liệu.
     *
     * Lệnh: XADD attendance:stream * field1 val1 field2 val2
     * ID tự động (dấu *) = timestamp-sequence (e.g., 1718736000000-0)
     */
    private function saveToRedisStream(CheckInData $data): void
    {
        $streamKey = 'attendance:stream:'.now()->format('Ymd'); // Theo ngày

        Redis::connection('default')->xadd(
            $streamKey,
            '*', // Auto-generate stream ID
            $data->toArray()
        );

        // Set TTL cho stream key: giữ 25 giờ (1 ngày + buffer)
        Redis::connection('default')->expire($streamKey, 90000);
    }
}

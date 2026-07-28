<?php

namespace App\Jobs;

use App\Services\PayrollRunService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Tính lương cả kỳ ở CHẠY NỀN (queue) thay vì trong request đồng bộ — tránh
 * timeout khi số nhân viên lớn (công ty sản xuất, hàng nghìn công nhân).
 *
 * Trạng thái tiến trình lưu ở Cache (Redis) theo key statusKey($periodId):
 *   PROCESSING → DONE | FAILED. FE poll endpoint /payroll/run-status để hiển thị.
 */
class RunPayrollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Cho kỳ rất lớn — tối đa 10 phút mỗi lần chạy. */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $periodId,
        public int $tenantId,
        public ?int $legalEntityId = null,
    ) {}

    public function handle(PayrollRunService $service): void
    {
        // Worker chạy NGOÀI request → TenantContext chưa được HrmAuth set. Đặt lại
        // để engine (AttendanceSummary, các query) lọc đúng công ty.
        TenantContext::set($this->tenantId, $this->legalEntityId);

        $key = self::statusKey($this->periodId);
        try {
            $result = $service->run($this->periodId);
            Cache::put($key, [
                'status' => 'DONE',
                'processed' => $result['employees_processed'] ?? null,
                'skipped' => $result['employees_skipped'] ?? null,
                'finished_at' => now()->toIso8601String(),
            ], now()->addHours(6));
        } catch (\Throwable $e) {
            Cache::put($key, [
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'finished_at' => now()->toIso8601String(),
            ], now()->addHours(6));

            throw $e; // để queue ghi nhận vào failed_jobs
        } finally {
            TenantContext::clear();
        }
    }

    public static function statusKey(int $periodId): string
    {
        return "payroll_run_status:{$periodId}";
    }
}

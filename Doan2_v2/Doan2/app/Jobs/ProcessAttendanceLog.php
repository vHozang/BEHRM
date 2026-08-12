<?php

namespace App\Jobs;

use App\DTOs\CheckInData;
use App\Models\Attendance;
use App\Services\AttendanceChangePublisher;
use App\Repositories\Contracts\AttendanceRepositoryContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job: Xử lý dữ liệu chấm công từ Queue và ghi vào PostgreSQL.
 *
 * Luồng hoạt động:
 *  1. API Controller nhận request → validate → push Job này vào Queue (Redis).
 *  2. Laravel Horizon (supervisor worker) pick up Job này và chạy handle().
 *  3. handle() đọc dữ liệu từ payload → ghi vào attendance_logs + cập nhật attendances.
 *  4. Nếu thất bại → retry tối đa $tries lần trước khi chuyển sang failed_jobs.
 *
 * Tại sao ShouldQueue + Redis thay vì insert trực tiếp?
 *  - Khung giờ 8h00: 5000 nhân viên check-in → ~5000 concurrent INSERT/minute.
 *  - PostgreSQL connection pool (PgBouncer) chỉ xử lý được ~200-400 concurrent connections.
 *  - Queue làm "buffer": HTTP response về ngay (202 Accepted), DB insert xảy ra sau.
 *  - Worker xử lý tuần tự/parallel có kiểm soát, tránh quá tải DB.
 */
class ProcessAttendanceLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tên queue chuyên dụng cho attendance.
     * Horizon config sẽ chạy nhiều worker hơn cho queue này vào giờ cao điểm.
     */
    public string $queue = 'attendance';

    /**
     * Số lần retry tối đa trước khi Job bị fail hoàn toàn.
     * Với check-in, 3 lần là đủ (lỗi thường là tạm thời: DB overload, network).
     */
    public int $tries = 3;

    /**
     * Timeout của Job (giây). Nếu quá thời gian này → Job bị killed và retry.
     */
    public int $timeout = 30;

    /**
     * Backoff strategy: thời gian chờ (giây) giữa các lần retry.
     * Exponential backoff: 5s → 30s → 120s để tránh thundering herd.
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    /**
     * Prevent job overlap: Mỗi nhân viên chỉ có 1 job đang chạy tại một thời điểm.
     * Tránh race condition khi nhiều request check-in đến cùng lúc cho 1 nhân viên.
     */
    public function uniqueId(): string
    {
        // Key unique theo từng nhân viên + action trong cùng một khoảng 5 phút
        $timeSlot = (int) (time() / 300); // 5-minute window

        return "checkin_{$this->checkInData->employeeId}_{$this->checkInData->action}_{$timeSlot}";
    }

    /**
     * TTL của uniqueness lock (giây).
     * Sau thời gian này, nhân viên có thể submit check-in lại.
     */
    public int $uniqueFor = 300; // 5 phút

    public function __construct(
        /**
         * DTO chứa toàn bộ dữ liệu check-in.
         * Được serialize thành JSON trong Queue payload (Redis).
         * Dùng readonly để đảm bảo immutability.
         */
        private readonly CheckInData $checkInData,
    ) {}

    /**
     * Xử lý chính của Job - chạy trong background worker.
     */
    public function handle(AttendanceRepositoryContract $repository): void
    {
        $data = $this->checkInData;

        Log::channel('attendance')->info('Processing attendance log', [
            'employee_id' => $data->employeeId,
            'action' => $data->action,
            'checked_at' => $data->checkedAt,
            'attempt' => $this->attempts(),
        ]);

        // ── Wrap trong DB Transaction để đảm bảo atomicity ────────────────
        // Nếu bất kỳ bước nào fail → rollback tất cả, Job sẽ được retry.
        DB::transaction(function () use ($data, $repository) {

            // Bước 1: Lấy employee_code (cần để denormalize vào attendance_logs)
            $employeeCode = $repository->getEmployeeCode($data->employeeId);
            if (! $employeeCode) {
                // Employee không tồn tại → fail ngay, không retry (abort)
                $this->fail(new \RuntimeException("Employee #{$data->employeeId} not found."));

                return;
            }

            // Bước 2: Kiểm tra duplicate check-in trong ngày
            // Dùng SELECT FOR UPDATE để lock row, tránh race condition với concurrent workers
            $existingLog = DB::table('attendance_logs')
                ->where('employee_id', $data->employeeId)
                ->where('action', $data->action)
                ->whereBetween('checked_at', [
                    now()->startOfDay()->toIso8601String(),
                    now()->endOfDay()->toIso8601String(),
                ])
                ->where('status', 'PROCESSED')
                ->lockForUpdate()  // PG: SELECT ... FOR UPDATE
                ->first();

            if ($existingLog) {
                Log::channel('attendance')->warning('Duplicate check-in detected, skipping', [
                    'employee_id' => $data->employeeId,
                    'action' => $data->action,
                ]);

                return; // Không fail, chỉ bỏ qua - đây là idempotent behavior
            }

            // Bước 3: Ghi vào attendance_logs (append-only log table, partitioned)
            $log = $repository->createLog($data, $employeeCode);

            // Bước 4: Cập nhật bảng `attendances` (bảng tổng hợp để query dashboard)
            // Đây là bảng "materialized" từ attendance_logs, dễ query hơn
            $this->updateAttendanceSummary($data);

            Log::channel('attendance')->info('Attendance log processed successfully', [
                'log_id' => $log->id,
                'employee_id' => $data->employeeId,
            ]);
        });
    }

    /**
     * Cập nhật bảng attendances (summary table) sau khi ghi log thành công.
     * Dùng UPSERT (INSERT ... ON CONFLICT DO UPDATE) để tránh duplicate rows.
     */
    private function updateAttendanceSummary(CheckInData $data): void
    {
        $today = now()->toDateString();
        $timeString = now()->parse($data->checkedAt)->toTimeString();
        $employee = DB::table('employees')->where('id', $data->employeeId)->first(['tenant_id', 'legal_entity_id']);
        if (! $employee) {
            return;
        }

        if ($data->action === 'CHECK_IN') {
            // UPSERT: tạo mới nếu chưa có, update check_in_time nếu đã tồn tại
            // ON CONFLICT là cú pháp native PostgreSQL cho upsert hiệu quả
            DB::statement("
                INSERT INTO attendances (employee_id, work_date, check_in_time, status, tenant_id, legal_entity_id, created_at, updated_at)
                VALUES (?, ?, ?, 'ON_TIME', ?, ?, NOW(), NOW())
                ON CONFLICT (employee_id, work_date)
                DO UPDATE SET
                    check_in_time = EXCLUDED.check_in_time,
                    updated_at    = NOW()
                WHERE attendances.check_in_time IS NULL
            ", [$data->employeeId, $today, $timeString, $employee->tenant_id, $employee->legal_entity_id]);

        } elseif ($data->action === 'CHECK_OUT') {
            // Chỉ update check_out_time nếu đã có check_in_time
            DB::table('attendances')
                ->where('employee_id', $data->employeeId)
                ->where('work_date', $today)
                ->whereNotNull('check_in_time')
                ->update([
                    'check_out_time' => $timeString,
                    'updated_at' => now(),
                ]);
        }

        $attendance = Attendance::withoutTenantScope()
            ->where('employee_id', $data->employeeId)
            ->where('work_date', $today)
            ->first();
        if ($attendance) {
            app(AttendanceChangePublisher::class)->publish($attendance, 'legacy_log');
        }
    }

    /**
     * Callback khi Job fail vĩnh viễn (hết số lần retry).
     * Ghi log chi tiết để developer điều tra, alert monitoring system.
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('attendance')->error('Attendance job failed permanently', [
            'employee_id' => $this->checkInData->employeeId,
            'action' => $this->checkInData->action,
            'checked_at' => $this->checkInData->checkedAt,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // TODO: Gửi alert qua Slack/email để team xử lý thủ công
        // Slack::alert("⚠️ Attendance job failed for employee #{$this->checkInData->employeeId}");
    }
}

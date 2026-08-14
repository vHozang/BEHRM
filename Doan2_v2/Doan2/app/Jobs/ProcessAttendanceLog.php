<?php

namespace App\Jobs;

use App\DTOs\CheckInData;
use App\Models\Attendance;
use App\Repositories\Contracts\AttendanceRepositoryContract;
use App\Services\AttendanceDayLock;
use App\Services\AttendanceReconciliationService;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
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
    ) {
        $this->onQueue('attendance');
    }

    /**
     * Xử lý chính của Job - chạy trong background worker.
     */
    public function handle(
        AttendanceRepositoryContract $repository,
        AttendanceDayLock $attendanceDayLock,
        AttendanceReconciliationService $attendanceReconciliation,
    ): void {
        $data = $this->checkInData;
        $occurredAt = now()->parse($data->checkedAt);
        $employee = DB::table('employees')
            ->where('id', $data->employeeId)
            ->first(['id', 'employee_code', 'tenant_id', 'legal_entity_id']);
        if (! $employee) {
            $this->fail(new \RuntimeException("Employee #{$data->employeeId} not found."));

            return;
        }

        Log::channel('attendance')->info('Processing attendance log', [
            'employee_id' => $data->employeeId,
            'action' => $data->action,
            'checked_at' => $data->checkedAt,
            'attempt' => $this->attempts(),
        ]);

        TenantContext::set((int) $employee->tenant_id, (int) $employee->legal_entity_id);
        try {
            $attendanceDayLock->run(
                (int) $employee->tenant_id,
                (int) $employee->id,
                $occurredAt->toDateString(),
                function () use ($data, $repository, $attendanceReconciliation, $employee, $occurredAt): void {
                    if ($attendanceReconciliation->isClosedWorkDate(
                        (int) $employee->tenant_id,
                        (int) $employee->legal_entity_id,
                        $occurredAt->toDateString(),
                    )) {
                        throw new \RuntimeException('Kỳ lương chứa ngày chấm công này đã chốt, không thể sửa trực tiếp.', 409);
                    }

                    // Idempotency is based on the exact punch, not action/day;
                    // otherwise a valid second work session would be discarded.
                    $existingLog = DB::table('attendance_logs')
                        ->where('tenant_id', $employee->tenant_id)
                        ->where('employee_id', $data->employeeId)
                        ->where('action', $data->action)
                        ->whereBetween('checked_at', [
                            $occurredAt->copy()->subSecond(),
                            $occurredAt->copy()->addSecond(),
                        ])
                        ->where('status', 'PROCESSED')
                        ->lockForUpdate()
                        ->first();

                    if ($existingLog) {
                        Log::channel('attendance')->warning('Duplicate attendance punch detected, skipping', [
                            'employee_id' => $data->employeeId,
                            'action' => $data->action,
                            'checked_at' => $data->checkedAt,
                        ]);

                        return;
                    }

                    $log = $repository->createLog($data, (string) $employee->employee_code);
                    $this->updateAttendanceSummary($data, $employee, $occurredAt, $attendanceReconciliation);

                    Log::channel('attendance')->info('Attendance log processed successfully', [
                        'log_id' => $log->id,
                        'employee_id' => $data->employeeId,
                    ]);
                },
            );
        } finally {
            TenantContext::clear();
        }
    }

    /**
     * Cập nhật bảng attendances (summary table) sau khi ghi log thành công.
     * Dùng UPSERT (INSERT ... ON CONFLICT DO UPDATE) để tránh duplicate rows.
     */
    private function updateAttendanceSummary(
        CheckInData $data,
        object $employee,
        CarbonInterface $occurredAt,
        AttendanceReconciliationService $attendanceReconciliation,
    ): void {
        $today = $occurredAt->toDateString();
        $timeString = $occurredAt->toTimeString();
        $attendance = Attendance::withoutTenantScope()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $data->employeeId)
            ->where('work_date', $today)
            ->lockForUpdate()
            ->first();

        if ($data->action === 'CHECK_IN') {
            if (! $attendance) {
                $attendance = Attendance::create([
                    'tenant_id' => $employee->tenant_id,
                    'legal_entity_id' => $employee->legal_entity_id,
                    'employee_id' => $data->employeeId,
                    'work_date' => $today,
                    'check_in_time' => $timeString,
                    'status' => 'ON_TIME',
                ]);
            } elseif (! $attendance->check_in_time) {
                $attendance->update(['check_in_time' => $timeString]);
            } elseif ($attendance->check_out_time && ! $attendance->check_in_time_2) {
                $attendance->update(['check_in_time_2' => $timeString]);
            } else {
                return;
            }
        } elseif ($data->action === 'CHECK_OUT') {
            if (! $attendance) {
                return;
            }
            if ($attendance->check_in_time && ! $attendance->check_out_time) {
                $attendance->update(['check_out_time' => $timeString]);
            } elseif ($attendance->check_in_time_2 && ! $attendance->check_out_time_2) {
                $attendance->update(['check_out_time_2' => $timeString]);
            } else {
                return;
            }
        } else {
            return;
        }

        $attendanceReconciliation->reconcile($attendance->fresh(), null, true);
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

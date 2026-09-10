<?php

namespace App\Repositories;

use App\DTOs\CheckInData;
use App\Models\AttendanceLog;
use App\Repositories\Contracts\AttendanceRepositoryContract;
use Illuminate\Support\Facades\DB;

/**
 * Concrete implementation của AttendanceRepositoryContract.
 * Chứa toàn bộ logic tương tác với database (PostgreSQL).
 * Controller và Service không bao giờ dùng DB:: trực tiếp.
 */
class AttendanceRepository implements AttendanceRepositoryContract
{
    public function createLog(CheckInData $data, string $employeeCode): AttendanceLog
    {
        // Tạo record trong attendance_logs (bảng partitioned).
        // PostgreSQL tự động route record vào đúng partition dựa vào checked_at.
        $log = new AttendanceLog;
        $log->employee_id = $data->employeeId;
        $log->employee_code = $employeeCode;
        $log->action = $data->action;
        $log->source = $data->source;
        $log->device_id = $data->deviceId;
        $log->location_code = $data->locationCode;
        // Dùng castAsInet() bằng raw expression vì INET là kiểu đặc thù của PostgreSQL.
        // Eloquent không tự động cast PHP string → PG INET.
        $log->ip_address = $data->ipAddress
            ? DB::raw("'{$data->ipAddress}'::INET")
            : null;
        $log->metadata = $data->metadata ? json_encode($data->metadata) : null;
        $log->status = 'PROCESSED'; // Đã được xử lý bởi worker rồi mới insert
        $log->checked_at = $data->checkedAt;
        $log->processed_at = now();
        $log->created_at = now();
        $log->save();

        return $log;
    }

    public function markAsProcessed(int $logId, string $checkedAt): void
    {
        // QUAN TRỌNG: Luôn filter bằng cả id VÀ checked_at khi UPDATE/DELETE
        // trên partitioned table để PostgreSQL biết đúng partition cần scan.
        // Thiếu checked_at → PG phải scan tất cả partitions → rất chậm.
        DB::table('attendance_logs')
            ->where('id', $logId)
            ->where('checked_at', $checkedAt)
            ->update([
                'status' => 'PROCESSED',
                'processed_at' => now(),
            ]);
    }

    public function markAsFailed(int $logId, string $checkedAt, string $errorMessage): void
    {
        DB::table('attendance_logs')
            ->where('id', $logId)
            ->where('checked_at', $checkedAt)
            ->update([
                'status' => 'FAILED',
                'error_message' => mb_substr($errorMessage, 0, 2000), // Giới hạn độ dài
                'processed_at' => now(),
            ]);
    }

    public function findTodayLog(int $employeeId, string $action): ?AttendanceLog
    {
        $today = now()->toDateString();
        $todayStart = $today.' 00:00:00+07';
        $todayEnd = $today.' 23:59:59+07';

        // Cung cấp range checked_at để PostgreSQL dùng Partition Pruning:
        // chỉ scan partition của ngày hôm nay, bỏ qua tất cả các tháng khác.
        return AttendanceLog::where('employee_id', $employeeId)
            ->where('action', $action)
            ->whereBetween('checked_at', [$todayStart, $todayEnd])
            ->orderByDesc('checked_at')
            ->first();
    }

    public function getEmployeeCode(int $employeeId): ?string
    {
        // Chỉ SELECT đúng cột cần, tránh SELECT * gây over-fetch
        return DB::table('employees')
            ->where('id', $employeeId)
            ->value('employee_code');
    }
}

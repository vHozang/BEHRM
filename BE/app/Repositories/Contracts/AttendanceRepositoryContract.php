<?php

namespace App\Repositories\Contracts;

use App\DTOs\CheckInData;
use App\Models\AttendanceLog;

/**
 * Contract (Interface) cho Attendance Repository.
 * Định nghĩa "hợp đồng" mà mọi implementation phải tuân theo.
 * Cho phép swap implementation dễ dàng (e.g., cho testing với mock).
 */
interface AttendanceRepositoryContract
{
    /**
     * Lưu bản ghi check-in/check-out vào database (attendance_logs).
     * Được gọi từ Queue Worker, không phải từ HTTP request trực tiếp.
     */
    public function createLog(CheckInData $data, string $employeeCode): AttendanceLog;

    /**
     * Đánh dấu một log đã được xử lý thành công bởi worker.
     */
    public function markAsProcessed(int $logId, string $checkedAt): void;

    /**
     * Đánh dấu log thất bại với thông báo lỗi.
     */
    public function markAsFailed(int $logId, string $checkedAt, string $errorMessage): void;

    /**
     * Kiểm tra nhân viên đã check-in hôm nay chưa.
     * Sử dụng partition pruning bằng cách giới hạn range tìm kiếm.
     */
    public function findTodayLog(int $employeeId, string $action): ?AttendanceLog;

    /**
     * Lấy toàn bộ employee_code từ DB (để dùng trong AttendanceService).
     */
    public function getEmployeeCode(int $employeeId): ?string;
}

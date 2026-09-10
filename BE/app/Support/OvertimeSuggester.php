<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tự ĐỀ XUẤT đơn tăng ca khi nhân viên ĐI LÀM ngày nghỉ tuần / lễ.
 *
 * Đi làm vào ngày nghỉ/lễ về bản chất là làm thêm (Đ.98). Khi ghi nhận giờ
 * ra cho một ngày như vậy mà chưa có đơn OT, tạo sẵn một đơn OT trạng thái
 * PENDING (chờ duyệt) để quản lý xác nhận — thay vì để nhân viên tự nhớ tạo.
 * Đánh dấu meta.auto_suggested=true để phân biệt với đơn nhân viên tự tạo.
 */
class OvertimeSuggester
{
    /**
     * @param array{employee_id:int, tenant_id:int, work_date:string,
     *              check_in:?string, check_out:?string, shift:mixed} $ctx
     */
    public static function suggest(array $ctx): bool
    {
        $checkIn = $ctx['check_in'] ?? null;
        $checkOut = $ctx['check_out'] ?? null;
        if (! $checkIn || ! $checkOut) {
            return false;
        }

        // Chỉ đề xuất khi là ngày nghỉ tuần / lễ.
        $dayType = TimePolicy::dayType($ctx['work_date']);
        if ($dayType === 'weekday') {
            return false;
        }

        $cls = TimePolicy::classifyAttendance($ctx['shift'] ?? null, $checkIn, $checkOut);
        $hours = (float) ($cls['worked_hours'] ?? 0);
        if ($hours < 0.5) {
            return false;
        }

        // Đã có đơn OT cho ngày này → không tạo trùng.
        $exists = DB::table('overtime_requests')
            ->where('tenant_id', $ctx['tenant_id'])
            ->where('employee_id', $ctx['employee_id'])
            ->whereDate('work_date', $ctx['work_date'])
            ->exists();
        if ($exists) {
            return false;
        }

        $ot = TimePolicy::classifyOvertime($ctx['work_date'], $checkIn, $checkOut, $hours);
        $now = now();

        DB::table('overtime_requests')->insert([
            'employee_id' => $ctx['employee_id'],
            'work_date' => $ctx['work_date'],
            'start_time' => $checkIn,
            'end_time' => $checkOut,
            'total_hours' => $ot['total_hours'],
            'status' => 'PENDING',
            'meta' => json_encode([
                'day_type' => $ot['day_type'],
                'multiplier' => $ot['multiplier'],
                'night_hours' => $ot['night_hours'],
                'pay_factor' => $ot['pay_factor'],
                'label' => $ot['label'],
                'reason' => 'Tự đề xuất: đi làm ngày nghỉ/lễ',
                'auto_suggested' => true,
            ], JSON_UNESCAPED_UNICODE),
            'tenant_id' => $ctx['tenant_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }
}

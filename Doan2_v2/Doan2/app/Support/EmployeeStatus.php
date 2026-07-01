<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Trạng thái nhân viên là giá trị SUY RA (derived) từ nguồn dữ liệu thật, để
 * luôn nhất quán với hợp đồng + đơn nghỉ:
 *
 *   - Không còn HĐ hiệu lực            → TERMINATED  (Đã nghỉ việc)
 *   - Đang trong kỳ nghỉ đã duyệt      → ON_LEAVE    (Đang nghỉ phép)
 *   - HĐ hiệu lực là HĐ thử việc       → PROBATION   (Thử việc)
 *   - Còn lại                          → ACTIVE      (Đang làm việc)
 *
 * Ưu tiên: TERMINATED > ON_LEAVE > PROBATION > ACTIVE.
 */
class EmployeeStatus
{
    private const ACTIVE_CONTRACT = ['CÓ_HIỆU_LỰC', 'ACTIVE', 'ĐANG_HIỆU_LỰC'];
    private const APPROVED_LEAVE = ['APPROVED', 'ĐÃ_DUYỆT'];

    public static function resolve(int $employeeId, ?string $today = null): string
    {
        $today = $today ?: now()->toDateString();

        $active = DB::table('contracts')
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::ACTIVE_CONTRACT)
            ->orderBy('id')
            ->first(['id', 'contract_type_id']);

        if (! $active) {
            return 'TERMINATED';
        }

        $onLeave = DB::table('leave_requests')
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::APPROVED_LEAVE)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();
        if ($onLeave) {
            return 'ON_LEAVE';
        }

        $probationTypeId = DB::table('contract_types')->where('contract_type_code', 'HDTV')->value('id');
        if ($probationTypeId && (int) $active->contract_type_id === (int) $probationTypeId) {
            return 'PROBATION';
        }

        return 'ACTIVE';
    }

    /** Nhãn tiếng Việt cho hiển thị. */
    public static function label(string $status): string
    {
        return [
            'ACTIVE' => 'Đang làm việc',
            'PROBATION' => 'Thử việc',
            'ON_LEAVE' => 'Đang nghỉ phép',
            'TERMINATED' => 'Đã nghỉ việc',
        ][$status] ?? $status;
    }
}

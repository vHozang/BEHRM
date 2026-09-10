<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Trạng thái nghỉ việc lấy từ employees.status; hợp đồng và đơn nghỉ chỉ
 * bổ sung trạng thái tạm thời cho nhân viên đang ACTIVE:
 *
 *   - Đang trong kỳ nghỉ đã duyệt      → ON_LEAVE    (Đang nghỉ phép)
 *   - HĐ hiệu lực là HĐ thử việc       → PROBATION   (Thử việc)
 *   - Còn lại                          → ACTIVE      (Đang làm việc)
 */
class EmployeeStatus
{
    private const ACTIVE_CONTRACT = ['CÓ_HIỆU_LỰC', 'ACTIVE', 'ĐANG_HIỆU_LỰC'];

    private const APPROVED_LEAVE = ['APPROVED', 'ĐÃ_DUYỆT'];

    public static function resolve(int $employeeId, ?string $today = null): string
    {
        $today = $today ?: now()->toDateString();

        $stored = strtoupper((string) DB::table('employees')->where('id', $employeeId)->value('status'));
        if ($stored !== 'ACTIVE') {
            return in_array($stored, ['INACTIVE', 'ON_LEAVE', 'PROBATION', 'TERMINATED'], true)
                ? $stored
                : 'ACTIVE';
        }

        $active = DB::table('contracts')
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::ACTIVE_CONTRACT)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderBy('id')
            ->first(['id', 'contract_type_id']);

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
        if ($active && $probationTypeId && (int) $active->contract_type_id === (int) $probationTypeId) {
            return 'PROBATION';
        }

        return 'ACTIVE';
    }

    /** @return array<int,string> */
    public static function resolveMany(iterable $employees, ?string $today = null): array
    {
        $today = $today ?: now()->toDateString();
        $rows = collect($employees);
        $resolved = [];
        $activeIds = [];

        foreach ($rows as $employee) {
            $stored = strtoupper((string) $employee->status);
            $status = in_array($stored, ['INACTIVE', 'ON_LEAVE', 'PROBATION', 'TERMINATED'], true)
                ? $stored
                : 'ACTIVE';
            $resolved[(int) $employee->id] = $status;
            if ($status === 'ACTIVE') {
                $activeIds[] = (int) $employee->id;
            }
        }

        if (! $activeIds) {
            return $resolved;
        }

        $onLeave = DB::table('leave_requests')->whereIn('employee_id', $activeIds)
            ->whereIn('status', self::APPROVED_LEAVE)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('employee_id')->mapWithKeys(fn ($id) => [(int) $id => true]);
        $probation = DB::table('contracts as c')
            ->join('contract_types as ct', 'ct.id', '=', 'c.contract_type_id')
            ->whereIn('c.employee_id', $activeIds)
            ->whereIn('c.status', self::ACTIVE_CONTRACT)
            ->where('ct.contract_type_code', 'HDTV')
            ->where(fn ($query) => $query->whereNull('c.start_date')->orWhere('c.start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('c.end_date')->orWhere('c.end_date', '>=', $today))
            ->pluck('c.employee_id')->mapWithKeys(fn ($id) => [(int) $id => true]);

        foreach ($activeIds as $employeeId) {
            $resolved[$employeeId] = isset($onLeave[$employeeId])
                ? 'ON_LEAVE'
                : (isset($probation[$employeeId]) ? 'PROBATION' : 'ACTIVE');
        }

        return $resolved;
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

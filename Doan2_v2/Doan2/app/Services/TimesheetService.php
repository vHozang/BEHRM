<?php

namespace App\Services;

use App\Support\HrmConfig;
use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * P3 — Chấm công: dựng "Bảng công tháng" (timesheet grid nhân viên × ngày) và
 * tái phân loại trạng thái chấm công (ON_TIME/LATE/EARLY_LEAVE/ABSENT) theo
 * ca làm việc (shift_types) + dung sai đi trễ (attendance.late_grace_minutes).
 *
 * Mọi tham số luật đọc qua TimePolicy/HrmConfig (tenant-configurable). Công chuẩn
 * tháng = ngày dương trừ ngày nghỉ hằng tuần (mặc định CN) trừ ngày lễ — KHÔNG
 * trừ thứ Bảy (DN 6 ngày/tuần). Đây là mẫu số feed cho payroll.
 */
class TimesheetService
{
    /** Trạng thái coi là "có mặt" (NV đã đến làm). */
    private const PRESENT_STATUSES = ['ON_TIME', 'LATE', 'EARLY_LEAVE'];

    private const APPROVED_STATUSES = ['APPROVED', 'ĐÃ_DUYỆT'];

    private const PENDING_STATUSES = ['PENDING', 'CHỜ_DUYỆT'];

    /**
     * Tái phân loại các bản ghi attendances trong [start,end] theo engine.
     * Idempotent — chạy lại cập nhật cùng các dòng. Dùng shift của chính bản ghi
     * (shift_type_id), fallback ca đang gán cho nhân viên.
     *
     * @return array{scanned:int, updated:int}
     */
    public function recompute(int $tenantId, int $legalEntityId, string $start, string $end, ?array $employeeIds = null): array
    {
        $shifts = $this->shiftMap($tenantId);
        $assignments = $this->assignmentMap($tenantId);

        $rows = DB::table('attendances')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereBetween('work_date', [$start, $end])
            ->when($employeeIds, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get();

        $updated = 0;
        $now = now();

        foreach ($rows as $row) {
            $shiftId = $row->shift_type_id ?: ($assignments[$row->employee_id] ?? null);
            $shift = $shiftId ? ($shifts[$shiftId] ?? null) : null;

            $cls = TimePolicy::classifyAttendance($shift, $row->check_in_time, $row->check_out_time);

            // Giữ nguyên các đơn đã duyệt theo nhãn VN (ĐÃ_DUYỆT) — không đụng.
            if (in_array($row->status, self::APPROVED_STATUSES, true)) {
                continue;
            }

            $meta = $this->decodeMeta($row->meta);
            $meta['late_minutes'] = $cls['late_minutes'];
            $meta['early_leave_minutes'] = $cls['early_leave_minutes'];
            $meta['worked_hours'] = $cls['worked_hours'];
            $meta['classified_by'] = 'timesheet-engine';

            DB::table('attendances')->where('id', $row->id)->update([
                'status' => $cls['status'],
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
            $updated++;
        }

        return ['scanned' => $rows->count(), 'updated' => $updated];
    }

    /**
     * Bảng công tháng: lưới nhân viên × ngày + tổng hợp mỗi nhân viên.
     *
     * @return array{
     *   month:string, start:string, end:string, standard_days:int,
     *   days:array<int,array{date:string,dow:int,day_type:string,is_rest:bool,is_holiday:bool}>,
     *   rows:array<int,array>
     * }
     */
    public function monthlyGrid(int $tenantId, int $legalEntityId, string $month, ?array $employeeIds = null): array
    {
        $startDate = CarbonImmutable::parse($month . '-01')->startOfMonth();
        $endDate = $startDate->endOfMonth();
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();
        $today = CarbonImmutable::parse(now()->toDateString());

        $holidays = TimePolicy::holidaySet($start, $end);
        $standardDays = TimePolicy::standardWorkingDays($start, $end);
        $halfDayHours = (float) HrmConfig::get('attendance.half_day_hours', 4);

        // Ca của từng nhân viên (để xác định ngày nghỉ theo ca + cờ ca đêm).
        $shifts = $this->shiftMap($tenantId);
        $assignments = $this->assignmentMap($tenantId);

        // Header ngày trong tháng.
        $days = [];
        for ($d = $startDate; $d->lte($endDate); $d = $d->addDay()) {
            $ds = $d->toDateString();
            $isHoliday = in_array($ds, $holidays, true);
            $isRest = TimePolicy::isRestDay($d);
            $days[] = [
                'date' => $ds,
                'day' => (int) $d->day,
                'dow' => (int) $d->dayOfWeek,
                'is_rest' => $isRest,
                'is_holiday' => $isHoliday,
                'day_type' => $isHoliday ? 'holiday' : ($isRest ? 'weekend' : 'weekday'),
            ];
        }

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->whereRaw("COALESCE((profile->>'system_account')::boolean, false) = false")
            ->when($employeeIds, fn ($q) => $q->whereIn('id', $employeeIds))
            ->orderBy('employee_code')
            ->get(['id', 'full_name', 'employee_code', 'hire_date']);

        $empIds = $employees->pluck('id')->all();

        // Prefetch attendance / leave / OT cho cả tháng.
        $attByEmpDate = $this->attendanceIndex($tenantId, $legalEntityId, $start, $end, $empIds);
        $leaveByEmp = $this->leaveIndex($tenantId, $start, $end, $empIds);
        $otByEmpDate = $this->overtimeIndex($tenantId, $start, $end, $empIds);

        $rows = [];
        foreach ($employees as $emp) {
            $hireDate = $emp->hire_date ? CarbonImmutable::parse($emp->hire_date)->toDateString() : null;
            // Ca của nhân viên → ngày làm trong tuần (meta.work_weekdays) nếu có.
            $empShiftId = $assignments[$emp->id] ?? null;
            $empShift = $empShiftId ? ($shifts[$empShiftId] ?? null) : null;
            $workWeekdays = $this->shiftWorkWeekdays($empShift); // null = dùng cấu hình chung
            $cells = [];
            $totals = [
                'present_days' => 0, 'on_time_days' => 0, 'late_days' => 0,
                'early_leave_days' => 0, 'half_days' => 0, 'absent_days' => 0, 'leave_days' => 0,
                'holiday_days' => 0, 'rest_days' => 0, 'ot_days' => 0,
                'late_minutes' => 0, 'early_leave_minutes' => 0,
                'worked_hours' => 0.0, 'overtime_hours' => 0.0,
            ];

            foreach ($days as $day) {
                $ds = $day['date'];
                $att = $attByEmpDate[$emp->id][$ds] ?? null;
                $leave = $this->leaveOnDate($leaveByEmp[$emp->id] ?? [], $ds);
                $leaveCode = $leave['code'] ?? null;
                $ot = (float) ($otByEmpDate[$emp->id][$ds] ?? 0);
                // Ngày nghỉ của RIÊNG nhân viên này (theo ca, fallback cấu hình chung).
                $isRest = $workWeekdays !== null
                    ? ! in_array((int) $day['dow'], $workWeekdays, true)
                    : $day['is_rest'];

                $cell = [
                    'date' => $ds,
                    'late_minutes' => 0,
                    'early_leave_minutes' => 0,
                    'worked_hours' => 0.0,
                    'overtime_hours' => $ot,
                    'leave_code' => $leaveCode,
                    'is_ot_day' => false,
                ];
                $totals['overtime_hours'] += $ot;

                if ($att && in_array($att->status, ['ON_TIME', 'LATE', 'EARLY_LEAVE'], true) || ($att && in_array($att->status, self::APPROVED_STATUSES, true))) {
                    $meta = $this->decodeMeta($att->meta);
                    $cell['check_in'] = $att->check_in_time;
                    $cell['check_out'] = $att->check_out_time;
                    $cell['late_minutes'] = (int) ($meta['late_minutes'] ?? 0);
                    $cell['early_leave_minutes'] = (int) ($meta['early_leave_minutes'] ?? 0);
                    $cell['worked_hours'] = (float) ($meta['worked_hours'] ?? 0);

                    $totals['late_minutes'] += $cell['late_minutes'];
                    $totals['early_leave_minutes'] += $cell['early_leave_minutes'];
                    $totals['worked_hours'] += $cell['worked_hours'];

                    // Nửa công: có đi làm nhưng giờ làm < ngưỡng nửa công.
                    $isHalf = $cell['worked_hours'] > 0 && $cell['worked_hours'] < $halfDayHours;
                    // Đi làm vào ngày nghỉ tuần / lễ → đánh dấu OT-eligible.
                    $cell['is_ot_day'] = $isRest || $day['is_holiday'];
                    if ($cell['is_ot_day']) {
                        $totals['ot_days']++;
                    }

                    $totals['present_days']++;
                    if ($isHalf) {
                        $cell['status'] = 'HALF_DAY';
                        $totals['half_days']++;
                    } else {
                        $cell['status'] = $att->status;
                        if ($att->status === 'LATE') {
                            $totals['late_days']++;
                        } elseif ($att->status === 'EARLY_LEAVE') {
                            $totals['early_leave_days']++;
                        } else {
                            $totals['on_time_days']++;
                        }
                    }
                } elseif ($att && $att->status === 'ABSENT') {
                    // Bản ghi đã đánh dấu vắng (vd bị từ chối xác minh).
                    $cell['status'] = 'ABSENT';
                    $totals['absent_days']++;
                } elseif ($day['is_holiday']) {
                    // Ngày lễ là ngày nghỉ hưởng lương — KHÔNG trừ phép dù đơn nghỉ
                    // có phủ qua. (Nếu có chấm công ở trên = đi làm ngày lễ → OT.)
                    $cell['status'] = 'HOLIDAY';
                    $totals['holiday_days']++;
                } elseif ($isRest) {
                    // Ngày nghỉ hằng tuần (theo ca / cấu hình). Phép KHÔNG áp vào ngày
                    // nghỉ. Đi làm ngày này = OT (đã xử lý ở nhánh có chấm công trên).
                    $cell['status'] = 'REST';
                    $totals['rest_days']++;
                } elseif ($leave && ! $leave['pending']) {
                    if (! empty($leave['half'])) {
                        $cell['status'] = 'LEAVE_HALF';
                        $totals['leave_days'] += 0.5;
                    } else {
                        $cell['status'] = 'LEAVE';
                        $totals['leave_days']++;
                    }
                } elseif ($leave && $leave['pending']) {
                    // Đơn nghỉ phép CHỜ DUYỆT phủ ngày làm việc → không tính vắng.
                    $cell['status'] = 'LEAVE_PENDING';
                } elseif ($hireDate && $ds < $hireDate) {
                    // Trước ngày vào làm → không tính (chưa là nhân viên).
                    $cell['status'] = '';
                } elseif (CarbonImmutable::parse($ds)->lt($today)) {
                    // Ngày làm việc ĐÃ QUA, không chấm công, không nghỉ → vắng.
                    // (Ngày hôm nay chưa kết thúc nên chưa tính vắng.)
                    $cell['status'] = 'ABSENT';
                    $totals['absent_days']++;
                } else {
                    $cell['status'] = ''; // tương lai
                }

                $cells[$ds] = $cell;
            }

            $totals['worked_hours'] = round($totals['worked_hours'], 2);
            $totals['overtime_hours'] = round($totals['overtime_hours'], 2);
            $totals['standard_days'] = $standardDays;
            // Công thực tế (cho payroll): ngày công đủ (present trừ nửa công) +
            // nửa công × 0.5 + nghỉ có lương. present_days đã gồm cả half_days.
            $fullPresent = $totals['present_days'] - $totals['half_days'];
            $totals['payable_days'] = round($fullPresent + $totals['half_days'] * 0.5 + $totals['leave_days'], 1);

            $rows[] = [
                'employee_id' => $emp->id,
                'employee_code' => $emp->employee_code,
                'full_name' => $emp->full_name,
                'cells' => $cells,
                'totals' => $totals,
            ];
        }

        return [
            'month' => $startDate->format('Y-m'),
            'start' => $start,
            'end' => $end,
            'standard_days' => $standardDays,
            'days' => $days,
            'rows' => $rows,
        ];
    }

    // ── Prefetch helpers ─────────────────────────────────

    private function shiftMap(int $tenantId): array
    {
        return DB::table('shift_types')
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** Ca đang hiệu lực của mỗi nhân viên (đơn giản: bản ghi active mới nhất). */
    private function assignmentMap(int $tenantId): array
    {
        $map = [];
        DB::table('shift_assignments')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'INACTIVE')
            ->orderByDesc('effective_date')
            ->get(['employee_id', 'shift_type_id'])
            ->each(function ($r) use (&$map) {
                if (! isset($map[$r->employee_id])) {
                    $map[$r->employee_id] = $r->shift_type_id;
                }
            });

        return $map;
    }

    /**
     * Ngày làm trong tuần của một ca (shift_types.meta.work_weekdays = [1..6]).
     * Trả null nếu ca không cấu hình → dùng ngày nghỉ chung (weekly_rest_weekday).
     *
     * @return array<int,int>|null
     */
    private function shiftWorkWeekdays($shift): ?array
    {
        if (! $shift) {
            return null;
        }
        $meta = $shift->meta ?? null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $wd = is_array($meta) ? ($meta['work_weekdays'] ?? null) : null;
        if (! is_array($wd) || empty($wd)) {
            return null;
        }

        return array_values(array_map('intval', $wd));
    }

    private function attendanceIndex(int $tenantId, int $legalEntityId, string $start, string $end, array $empIds): array
    {
        if (empty($empIds)) {
            return [];
        }
        $map = [];
        DB::table('attendances')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('employee_id', $empIds)
            ->whereBetween('work_date', [$start, $end])
            ->get()
            ->each(function ($r) use (&$map) {
                $date = CarbonImmutable::parse($r->work_date)->toDateString();
                $map[$r->employee_id][$date] = $r;
            });

        return $map;
    }

    private function leaveIndex(int $tenantId, string $start, string $end, array $empIds): array
    {
        if (empty($empIds)) {
            return [];
        }
        $map = [];
        DB::table('leave_requests')
            ->leftJoin('leave_types', 'leave_requests.leave_type_id', '=', 'leave_types.id')
            ->where('leave_requests.tenant_id', $tenantId)
            ->whereIn('leave_requests.employee_id', $empIds)
            ->whereIn('leave_requests.status', array_merge(self::APPROVED_STATUSES, self::PENDING_STATUSES))
            ->where('leave_requests.start_date', '<=', $end)
            ->where('leave_requests.end_date', '>=', $start)
            ->get([
                'leave_requests.employee_id',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.status',
                'leave_requests.total_days',
                'leave_types.leave_type_code as code',
            ])
            ->each(function ($r) use (&$map) {
                $start = CarbonImmutable::parse($r->start_date)->toDateString();
                $end = CarbonImmutable::parse($r->end_date)->toDateString();
                // Nghỉ nửa ngày/theo giờ: cùng ngày + tổng < 1 công.
                $half = $start === $end && (float) $r->total_days > 0 && (float) $r->total_days < 1;
                $map[$r->employee_id][] = [
                    'start' => $start,
                    'end' => $end,
                    'code' => $r->code ?: 'LEAVE',
                    'pending' => ! in_array($r->status, self::APPROVED_STATUSES, true),
                    'half' => $half,
                ];
            });

        return $map;
    }

    private function overtimeIndex(int $tenantId, string $start, string $end, array $empIds): array
    {
        if (empty($empIds)) {
            return [];
        }
        $map = [];
        DB::table('overtime_requests')
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $empIds)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('work_date', [$start, $end])
            ->get(['employee_id', 'work_date', 'total_hours'])
            ->each(function ($r) use (&$map) {
                $date = CarbonImmutable::parse($r->work_date)->toDateString();
                $map[$r->employee_id][$date] = ($map[$r->employee_id][$date] ?? 0) + (float) $r->total_hours;
            });

        return $map;
    }

    /** @return array{code:string,pending:bool}|null */
    private function leaveOnDate(array $intervals, string $date): ?array
    {
        // Prefer an approved leave over a pending one if both cover the day.
        $found = null;
        foreach ($intervals as $iv) {
            if ($date >= $iv['start'] && $date <= $iv['end']) {
                if (empty($iv['pending'])) {
                    return ['code' => $iv['code'], 'pending' => false, 'half' => ! empty($iv['half'])];
                }
                $found = ['code' => $iv['code'], 'pending' => true, 'half' => ! empty($iv['half'])];
            }
        }

        return $found;
    }

    private function decodeMeta($meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

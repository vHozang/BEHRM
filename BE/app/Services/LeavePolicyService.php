<?php

namespace App\Services;

use App\Support\HrmConfig;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Vietnamese labour-law leave policy engine.
 *
 * Encapsulates working-day counting (weekend + holiday aware), seniority based
 * annual entitlement and the yearly balance recompute / accrual routine.
 *
 * Legal references (Bộ luật Lao động 2019):
 *   - Art.113: base annual leave = 12 working days/year for normal conditions.
 *   - Art.114: +1 day for every 5 full years of service with one employer.
 *
 * Decisions (see also the controller wiring notes):
 *   - "Working days" excludes Saturday, Sunday and tenant holidays in the
 *     inclusive [start, end] range. The `holidays` table is tenant-scoped only
 *     (no legal_entity_id column), so holidays apply tenant-wide.
 *   - Accrual timing: entitlement for a year is granted in full up-front
 *     (annual grant model), not pro-rated monthly. This keeps the engine
 *     idempotent and matches how most VN SMEs administer annual leave.
 *   - Carryover: the previous year's `remaining_days` rolls forward into the
 *     current year with NO cap for now (documented; make configurable later).
 */
class LeavePolicyService
{
    /** VN base annual leave entitlement in working days (Art.113) — default. */
    public const BASE_ANNUAL_DAYS = 12;

    /** Extra days granted per N full years of service (Art.114) — default. */
    public const SENIORITY_BONUS_PER_YEARS = 5;

    /**
     * Resolve the effective rule config for a leave type. Reads `leave_types.meta`
     * (authoritative, set by the statutory seeder / leave-type admin) and falls
     * back to sensible defaults derived from the code + HrmConfig (so a type with
     * no meta still behaves correctly).
     *
     * @param  object|array  $leaveType  a leave_types row (or array)
     * @return array{paid:bool, accrual:string, requires_balance:bool,
     *               max_days_per_event:?int, default_days:?int,
     *               affects_annual_balance:bool, statutory_ref:?string}
     *   accrual ∈ annual | per_event | none
     */
    public function typeConfig($leaveType): array
    {
        $meta = data_get($leaveType, 'meta');
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $meta = is_array($meta) ? $meta : [];

        $code = strtoupper((string) data_get($leaveType, 'leave_type_code', ''));
        $category = strtoupper((string) data_get($leaveType, 'category', ''));

        // Per-code statutory defaults (used when meta doesn't specify).
        $defaults = [
            'ANNUAL' => ['paid' => true, 'accrual' => 'annual', 'requires_balance' => true,
                'default_days' => (int) HrmConfig::get('leave.annual_base_days', self::BASE_ANNUAL_DAYS),
                'affects_annual_balance' => true, 'statutory_ref' => 'Điều 113'],
            'SICK' => ['paid' => true, 'accrual' => 'none', 'requires_balance' => false,
                'statutory_ref' => 'Điều 25 Luật BHXH'],
            'UNPAID' => ['paid' => false, 'accrual' => 'none', 'requires_balance' => false,
                'max_days_per_event' => (int) HrmConfig::get('leave.unpaid_personal_days', 1),
                'statutory_ref' => 'Điều 115.2'],
            'MATERNITY' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                // Trần = mức cao nhất giữa thai sản thường (6 tháng) và sinh con
                // thứ hai (7 tháng — luật hiệu lực 01/07/2026). Số ngày thực tế
                // của từng đơn do HR nhập; policy chỉ chặn trần.
                'max_days_per_event' => max(
                    (int) HrmConfig::get('leave.maternity_days', 180),
                    (int) HrmConfig::get('leave.maternity_days_second_child', 210)
                ),
                'statutory_ref' => 'Điều 139 + luật 01/07/2026 (con thứ 2: 7 tháng)'],
            'MARRIAGE' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                'max_days_per_event' => (int) HrmConfig::get('leave.marriage_self_days', 3),
                'statutory_ref' => 'Điều 115.1.a'],
            'CHILD_MARRIAGE' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                'max_days_per_event' => (int) HrmConfig::get('leave.marriage_child_days', 1),
                'statutory_ref' => 'Điều 115.1.b'],
            'BEREAVEMENT' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                'max_days_per_event' => (int) HrmConfig::get('leave.bereavement_days', 3),
                'statutory_ref' => 'Điều 115.1.c'],
            // WFH / công tác: KHÔNG phải nghỉ — tính là ngày làm việc, không trừ
            // quỹ phép. Duyệt theo đơn (cấp quản lý) rồi tính công.
            'WFH' => ['paid' => true, 'accrual' => 'none', 'requires_balance' => false,
                'affects_annual_balance' => false, 'counts_as_work' => true, 'statutory_ref' => 'Nội quy công ty'],
            'BUSINESS_TRIP' => ['paid' => true, 'accrual' => 'none', 'requires_balance' => false,
                'affects_annual_balance' => false, 'counts_as_work' => true, 'statutory_ref' => 'Nội quy công ty'],
        ];

        $base = $defaults[$code] ?? [
            'paid' => $category !== 'UNPAID',
            'accrual' => 'none',
            'requires_balance' => false,
            'statutory_ref' => null,
        ];

        // meta overrides defaults.
        $cfg = array_merge([
            'paid' => true, 'accrual' => 'none', 'requires_balance' => false,
            'max_days_per_event' => null, 'default_days' => null,
            'affects_annual_balance' => false, 'statutory_ref' => null,
        ], $base, array_intersect_key($meta, array_flip([
            'paid', 'accrual', 'requires_balance', 'max_days_per_event',
            'default_days', 'affects_annual_balance', 'statutory_ref',
        ])));

        $cfg['paid'] = (bool) $cfg['paid'];
        $cfg['requires_balance'] = (bool) $cfg['requires_balance'];
        $cfg['affects_annual_balance'] = (bool) $cfg['affects_annual_balance'];
        $cfg['max_days_per_event'] = $cfg['max_days_per_event'] !== null ? (int) $cfg['max_days_per_event'] : null;

        return $cfg;
    }

    /**
     * Count working days in the inclusive range [start, end], excluding
     * Saturdays, Sundays and tenant holidays.
     */
    public function workingDays(string $start, string $end, int $tenantId, ?int $legalEntityId = null): int
    {
        $startDate = CarbonImmutable::parse($start)->startOfDay();
        $endDate = CarbonImmutable::parse($end)->startOfDay();

        if ($endDate->lt($startDate)) {
            return 0;
        }

        $holidays = $this->holidayDates($startDate->toDateString(), $endDate->toDateString(), $tenantId);

        $count = 0;
        foreach (CarbonPeriod::create($startDate, $endDate) as $day) {
            // 6 = Saturday, 0 = Sunday (Carbon dayOfWeek).
            if ($day->isWeekend()) {
                continue;
            }
            if (in_array($day->toDateString(), $holidays, true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * Full years of service from hire_date as of the given date (default: now).
     */
    public function yearsOfService($employee, $asOf = null): int
    {
        $hireDate = data_get($employee, 'hire_date');

        if (empty($hireDate)) {
            return 0;
        }

        $hire = CarbonImmutable::parse($hireDate);
        $reference = $asOf ? CarbonImmutable::parse($asOf) : CarbonImmutable::now();

        if ($reference->lt($hire)) {
            return 0;
        }

        return (int) $hire->diffInYears($reference);
    }

    /**
     * Annual entitlement (working days) for a given calendar year:
     * 12 + floor(yearsOfService / 5).
     *
     * First-year proration (config('hrm.leave.prorate_first_year')): when the
     * employee's hire_date falls inside the target year, the BASE entitlement is
     * prorated by months worked in that year — round(BASE * monthsWorked / 12).
     * Month-counting rule: months worked = December - hireMonth + 1 (the hire
     * month counts as a full worked month through to year end). The seniority
     * bonus (Art.114) is NOT prorated; in a mid-year hire year it is 0 anyway
     * because the employee has < 5 full years of service.
     */
    public function annualEntitlement($employee, int $year): int
    {
        // Measure seniority at the end of the target year so the bonus the
        // employee is entitled to during that year is applied for the whole year.
        $baseDays = (int) HrmConfig::get('leave.annual_base_days', self::BASE_ANNUAL_DAYS);
        $perYears = max(1, (int) HrmConfig::get('leave.seniority_bonus_per_years', self::SENIORITY_BONUS_PER_YEARS));

        $asOf = CarbonImmutable::create($year, 12, 31);
        $bonus = intdiv($this->yearsOfService($employee, $asOf), $perYears);

        $base = $baseDays;

        $hireDate = data_get($employee, 'hire_date');
        if (HrmConfig::get('leave.prorate_first_year', true) && ! empty($hireDate)) {
            $hire = CarbonImmutable::parse($hireDate);
            if ((int) $hire->year === $year) {
                // Months worked in the hire year: hire month .. December (inclusive).
                $monthsWorked = 12 - (int) $hire->month + 1;
                $base = (int) round($baseDays * $monthsWorked / 12);
            }
        }

        return $base + $bonus;
    }

    /**
     * Recompute leave balances for every ACTIVE employee for the ANNUAL leave
     * type(s) of a tenant/year. Idempotent: re-running updates the same rows and
     * does not create duplicate GRANT ledger entries (guarded by a meta marker).
     *
     * @return array{employees:int, balances_upserted:int, transactions_written:int}
     */
    public function recomputeBalances(int $tenantId, int $year): array
    {
        $yearStr = (string) $year;
        $prevYearStr = (string) ($year - 1);

        // ANNUAL-leave types for this tenant. Identified by leave_type_code in
        // this schema (the `category` column carries PAID/UNPAID, not ANNUAL).
        $annualTypeIds = DB::table('leave_types')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->where('leave_type_code', 'ANNUAL')
            ->pluck('id')
            ->all();

        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->get(['id', 'hire_date']);

        $balancesUpserted = 0;
        $transactionsWritten = 0;

        foreach ($employees as $employee) {
            foreach ($annualTypeIds as $leaveTypeId) {
                $entitlement = $this->annualEntitlement($employee, $year);

                // Carryover = prior-year remaining, TRẦN theo nội quy (0 = không giới hạn
                // — luật Đ.113.4 không đặt trần, đây là chính sách công ty qua config).
                $carryover = (float) (DB::table('leave_balances')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $leaveTypeId)
                    ->where('year', $prevYearStr)
                    ->value('remaining_days') ?? 0);
                $cap = (float) HrmConfig::get('leave.carryover_max_days', 0);
                if ($cap > 0) {
                    $carryover = min($carryover, $cap);
                }

                $existing = DB::table('leave_balances')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $leaveTypeId)
                    ->where('year', $yearStr)
                    ->first();

                $meta = $existing && $existing->meta ? json_decode($existing->meta, true) : [];
                $meta = is_array($meta) ? $meta : [];

                // Phần carryover ĐÃ hết hạn (job hrm:leave-carryover-expire đánh dấu)
                // không được hồi sinh khi recompute chạy lại (seeder gọi hàm này).
                $carryover = max(0.0, $carryover - (float) ($meta['carryover_expired'] ?? 0));

                $total = $entitlement + $carryover;
                $used = (float) ($existing->used_days ?? 0);
                $remaining = $total - $used;
                $meta['entitlement'] = $entitlement;
                $meta['carryover'] = $carryover;
                $meta['accrual_basis'] = 'annual_grant';
                $meta['last_accrual_year'] = $year;

                $now = now();

                if ($existing) {
                    DB::table('leave_balances')->where('id', $existing->id)->update([
                        'total_days' => $total,
                        'used_days' => $used,
                        'remaining_days' => $remaining,
                        'meta' => json_encode($meta),
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('leave_balances')->insert(TenantContext::stamp([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveTypeId,
                        'year' => $yearStr,
                        'total_days' => $total,
                        'used_days' => $used,
                        'remaining_days' => $remaining,
                        'meta' => json_encode($meta),
                        'tenant_id' => $tenantId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                }
                $balancesUpserted++;

                // Idempotent GRANT ledger row: one per employee/type/year.
                $grantExists = DB::table('leave_transactions')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $leaveTypeId)
                    ->where('transaction_type', 'GRANT')
                    ->where('reference_type', 'ACCRUAL')
                    ->where('reference_id', $year)
                    ->exists();

                if (! $grantExists) {
                    DB::table('leave_transactions')->insert(TenantContext::stamp([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveTypeId,
                        'transaction_date' => $now->toDateString(),
                        'transaction_type' => 'GRANT',
                        'quantity' => $total,
                        'before_balance' => 0,
                        'after_balance' => $total,
                        'reference_id' => $year,
                        'reference_type' => 'ACCRUAL',
                        'reason' => "Cấp phép năm {$year} (12 ngày + thâm niên" . ($carryover > 0 ? ", chuyển {$carryover} ngày từ năm trước" : '') . ')',
                        'meta' => json_encode(['entitlement' => $entitlement, 'carryover' => $carryover]),
                        'tenant_id' => $tenantId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                    $transactionsWritten++;
                }
            }
        }

        return [
            'employees' => $employees->count(),
            'balances_upserted' => $balancesUpserted,
            'transactions_written' => $transactionsWritten,
        ];
    }

    /**
     * Distinct holiday dates (Y-m-d) for a tenant within [start, end] inclusive.
     * Includes recurring holidays whose month/day fall in the range.
     *
     * @return array<int, string>
     */
    protected function holidayDates(string $start, string $end, int $tenantId): array
    {
        $rows = DB::table('holidays')
            ->where('tenant_id', $tenantId)
            ->whereBetween('holiday_date', [$start, $end])
            ->pluck('holiday_date')
            ->all();

        return array_values(array_unique(array_map(
            fn ($d) => CarbonImmutable::parse($d)->toDateString(),
            $rows
        )));
    }
}

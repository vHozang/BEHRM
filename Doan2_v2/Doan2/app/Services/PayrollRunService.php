<?php

namespace App\Services;

use App\Support\HrmConfig;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EPIC 2 — Vietnam payroll run.
 *
 * Computes, per ACTIVE employee in a salary period's tenant + legal entity, the
 * statutory gross -> insurance -> PIT -> net waterfall and upserts the result
 * into salary_details (+ transparent salary_breakdowns line items). Idempotent:
 * re-running a still-open period recomputes the same rows.
 *
 * Column mapping notes (salary_details has no tax/insurance/status/lock cols):
 *   - gross_salary, net_salary -> dedicated numeric columns.
 *   - insurance, PIT, taxable income, dependents, attendance ref, lock flag and
 *     the full computation trace -> meta (jsonb).
 * Lock model: there is no is_locked column. A period is "locked" when its status
 * is CLOSED/LOCKED (then the whole run is refused); an individual detail can be
 * pinned via meta->locked = true (then that employee is skipped on recompute).
 */
class PayrollRunService
{
    /** Period statuses that forbid (re)computation. */
    private const LOCKED_PERIOD_STATUSES = ['CLOSED', 'LOCKED', 'ĐÃ_ĐÓNG', 'DA_DONG'];

    public function __construct(
        private readonly PayrollTaxService $tax,
        private readonly InsuranceService $insurance,
    ) {}

    /**
     * Run payroll for a salary period.
     *
     * @return array{period_id:int, employees_processed:int, employees_skipped:int, totals:array{gross:float,insurance:float,pit:float,net:float}, notes:array<int,string>}
     */
    public function run(int $salaryPeriodId): array
    {
        $period = DB::table('salary_periods')
            ->where('id', $salaryPeriodId)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->first();

        if (! $period) {
            throw new RuntimeException("Salary period {$salaryPeriodId} not found", 404);
        }

        if (in_array((string) $period->status, self::LOCKED_PERIOD_STATUSES, true)) {
            // 409: closed periods are immutable.
            throw new RuntimeException('Kỳ lương đã chốt, không thể tính lại lương', 409);
        }

        $tenantId = (int) $period->tenant_id;
        $legalEntityId = (int) $period->legal_entity_id;
        $periodStart = (string) $period->start_date;
        $periodEnd = (string) $period->end_date;

        $allowancesTaxable = (bool) HrmConfig::get('payroll.allowances_taxable_by_default', true);

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->whereRaw("COALESCE((profile->>'system_account')::boolean, false) = false")
            ->get(['id', 'employee_code', 'base_salary']);

        $now = now();
        $processed = 0;
        $skipped = 0;
        $notes = [];
        $nullBaseCount = 0;

        $totals = ['gross' => 0.0, 'insurance' => 0.0, 'pit' => 0.0, 'net' => 0.0];

        DB::transaction(function () use (
            $employees, $tenantId, $legalEntityId, $salaryPeriodId, $periodStart, $periodEnd,
            $allowancesTaxable, $now, &$processed, &$skipped, &$totals, &$nullBaseCount
        ): void {
            foreach ($employees as $emp) {
                $employeeId = (int) $emp->id;

                // Respect an individually pinned (locked) detail — skip recompute.
                $existing = DB::table('salary_details')
                    ->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $legalEntityId)
                    ->where('period_id', $salaryPeriodId)
                    ->where('employee_id', $employeeId)
                    ->first();

                if ($existing && $this->detailLocked($existing)) {
                    $skipped++;

                    continue;
                }

                // Resilience: base_salary is a denormalized field — if it's empty
                // (e.g. set only on the contract, or drifted), fall back to the
                // active contract's meta.basic_salary so payroll still runs.
                $baseSalary = (float) ($emp->base_salary ?? 0);
                if ($baseSalary <= 0.0) {
                    $baseSalary = $this->contractBaseSalary($employeeId);
                }

                // Still no base salary → skip rather than clobber any existing,
                // possibly hand-keyed, salary_details row with a near-zero figure.
                if ($baseSalary <= 0.0) {
                    $nullBaseCount++;
                    $skipped++;

                    continue;
                }

                // Taxable allowances (active, effective in period).
                $allowanceTotal = (float) DB::table('employee_allowances')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->where('is_active', DB::raw('true'))
                    ->where(function ($q) use ($periodEnd) {
                        $q->whereNull('effective_date')->orWhere('effective_date', '<=', $periodEnd);
                    })
                    ->where(function ($q) use ($periodStart) {
                        $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $periodStart);
                    })
                    ->sum('amount');

                // Fixed-amount deductions only. Percentage-based employee_deductions
                // are the legacy encoding of statutory insurance (8/1.5/1) which we
                // compute authoritatively below — including them would double count.
                $fixedDeductions = (float) DB::table('employee_deductions')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->where('is_active', DB::raw('true'))
                    ->whereNotNull('amount')
                    ->where('amount', '>', 0)
                    ->where(function ($q) use ($periodEnd) {
                        $q->whereNull('effective_date')->orWhere('effective_date', '<=', $periodEnd);
                    })
                    ->where(function ($q) use ($periodStart) {
                        $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $periodStart);
                    })
                    ->sum('amount');

                // Attendance reference — drives proration (unpaid leave) + OT pay.
                $attendance = DB::table('salary_attendance_summary')
                    ->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $legalEntityId)
                    ->where('period_id', $salaryPeriodId)
                    ->where('employee_id', $employeeId)
                    ->first();

                // Proration: scale base + allowances by paid-days / standard-days.
                $prorate = (bool) HrmConfig::get('payroll.prorate_by_attendance', true);
                $prorationFactor = 1.0;
                $prorationLabel = 'NONE_FULL_MONTH';

                if ($prorate && $attendance) {
                    $standardDays = (float) ($attendance->standard_days ?? 0);
                    $unpaidLeaveDays = (float) ($attendance->unpaid_leave_days ?? 0);

                    if ($standardDays > 0 && $unpaidLeaveDays > 0) {
                        $paidDays = max(0.0, $standardDays - $unpaidLeaveDays);
                        $prorationFactor = round($paidDays / $standardDays, 6);
                        $prorationLabel = "PRORATED {$paidDays}/{$standardDays}";
                    }
                }

                $proratedBase = round($baseSalary * $prorationFactor, 4);
                $proratedAllowance = round($allowanceTotal * $prorationFactor, 4);

                // Overtime earning: hourly rate from monthly base × OT multiplier.
                $overtimeHours = $attendance ? (float) ($attendance->overtime_hours ?? 0) : 0.0;
                $overtimePay = 0.0;
                if ($overtimeHours > 0) {
                    $stdDaysForRate = $attendance && (float) ($attendance->standard_days ?? 0) > 0
                        ? (float) $attendance->standard_days
                        : 26.0;
                    $hoursPerDay = (float) HrmConfig::get('payroll.standard_hours_per_day', 8);
                    $otMultiplier = (float) HrmConfig::get('payroll.overtime_multiplier', 1.5);
                    $monthlyHours = max(1.0, $stdDaysForRate * $hoursPerDay);
                    $hourlyRate = $baseSalary / $monthlyHours;
                    $overtimePay = round($overtimeHours * $hourlyRate * $otMultiplier, 4);
                }

                // Công khoán theo sản phẩm (piece-rate): tổng sản lượng trong kỳ.
                $pieceRatePay = (float) DB::table('piece_rate_entries')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->whereBetween('work_date', [$periodStart, $periodEnd])
                    ->sum('amount');

                // gross floored at 0 — never persist a negative payslip.
                $gross = round(max(0.0, $proratedBase + $proratedAllowance + $overtimePay + $pieceRatePay - $fixedDeductions), 4);
                $grossTaxable = $allowancesTaxable
                    ? $gross
                    : round(max(0.0, $proratedBase + $overtimePay + $pieceRatePay - $fixedDeductions), 4);

                // Active dependents registered within the period window.
                // status is mixed-encoded across data ('true','1','ACTIVE',NULL);
                // count anything NOT explicitly inactive as active so relief is
                // not silently dropped (which over-charges PIT).
                $dependentCount = (int) DB::table('dependents')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->where(function ($q) {
                        $q->whereNull('status')
                            ->orWhereRaw('LOWER(status) NOT IN (?, ?, ?, ?, ?)', ['false', '0', 'inactive', 'expired', 'deleted']);
                    })
                    ->where(function ($q) use ($periodEnd) {
                        $q->whereNull('start_date')->orWhere('start_date', '<=', $periodEnd);
                    })
                    ->where(function ($q) use ($periodStart) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', $periodStart);
                    })
                    ->count();

                // Insurance is contributed on the base salary (capped in service).
                $empInsurance = $this->insurance->employee($baseSalary);
                $employerInsurance = $this->insurance->employer($baseSalary);

                $relief = $this->tax->personalRelief($dependentCount);
                $taxableIncome = max(0.0, round($grossTaxable - $empInsurance['total'] - $relief, 4));
                $pit = $this->tax->pit($taxableIncome);

                // net floored at 0 — an employee is never paid a negative salary.
                $net = round(max(0.0, $gross - $empInsurance['total'] - $pit['tax']), 4);

                $meta = [
                    'engine' => 'vn-payroll-v1',
                    'computed_at' => $now->toIso8601String(),
                    'base_salary' => $baseSalary,
                    'prorated_base' => $proratedBase,
                    'proration_factor' => $prorationFactor,
                    'allowance_total' => $allowanceTotal,
                    'prorated_allowance' => $proratedAllowance,
                    'overtime_hours' => $overtimeHours,
                    'overtime_pay' => $overtimePay,
                    'piece_rate_pay' => $pieceRatePay,
                    'fixed_deduction_total' => $fixedDeductions,
                    'gross' => $gross,
                    'gross_taxable' => $grossTaxable,
                    'allowances_taxable' => $allowancesTaxable,
                    'dependents' => $dependentCount,
                    'personal_relief' => $relief,
                    'insurance_employee' => $empInsurance,
                    'insurance_employer' => $employerInsurance,
                    'taxable_income' => $taxableIncome,
                    'pit' => $pit,
                    'net' => $net,
                    'attendance_summary_id' => $attendance->id ?? null,
                    'proration' => $prorationLabel,
                    'locked' => false,
                ];

                $detailPayload = TenantContext::stamp([
                    'gross_salary' => $gross,
                    'net_salary' => $net,
                    'transfer_status' => $existing->transfer_status ?? 'PENDING',
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $legalEntityId,
                    'updated_at' => $now,
                ], true);

                if ($existing) {
                    DB::table('salary_details')->where('id', $existing->id)->update($detailPayload);
                    $detailId = (int) $existing->id;
                } else {
                    $detailId = (int) DB::table('salary_details')->insertGetId(array_merge($detailPayload, [
                        'period_id' => $salaryPeriodId,
                        'employee_id' => $employeeId,
                        'created_at' => $now,
                    ]));
                }

                $this->writeBreakdowns($detailId, $tenantId, $legalEntityId, $now, [
                    ['EARNING', 'BASE', 'Lương cơ bản (theo công)', $proratedBase],
                    ['EARNING', 'ALLOWANCE', 'Phụ cấp', $proratedAllowance],
                    ['EARNING', 'OVERTIME', 'Tăng ca', $overtimePay],
                    ['EARNING', 'PIECE_RATE', 'Công khoán sản phẩm', $pieceRatePay],
                    ['DEDUCTION', 'INS_BHXH', 'BHXH (8%)', $empInsurance['bhxh']],
                    ['DEDUCTION', 'INS_BHYT', 'BHYT (1.5%)', $empInsurance['bhyt']],
                    ['DEDUCTION', 'INS_BHTN', 'BHTN (1%)', $empInsurance['bhtn']],
                    ['DEDUCTION', 'PIT', 'Thuế TNCN', $pit['tax']],
                    ['DEDUCTION', 'FIXED_DEDUCTION', 'Khấu trừ cố định', $fixedDeductions],
                    ['INFO', 'TAXABLE_INCOME', 'Thu nhập chịu thuế', $taxableIncome],
                    ['NET', 'NET', 'Thực nhận', $net],
                    ['EMPLOYER_COST', 'ER_BHXH', 'BHXH (DN 17.5%)', $employerInsurance['bhxh']],
                    ['EMPLOYER_COST', 'ER_BHYT', 'BHYT (DN 3%)', $employerInsurance['bhyt']],
                    ['EMPLOYER_COST', 'ER_BHTN', 'BHTN (DN 1%)', $employerInsurance['bhtn']],
                ]);

                $totals['gross'] += $gross;
                $totals['insurance'] += $empInsurance['total'];
                $totals['pit'] += $pit['tax'];
                $totals['net'] += $net;
                $processed++;
            }
        });

        foreach ($totals as $k => $v) {
            $totals[$k] = round($v, 4);
        }

        if ($nullBaseCount > 0) {
            $notes[] = "{$nullBaseCount} nhân viên không có base_salary hợp lệ — đã bỏ qua (không ghi đè salary_details).";
        }
        if ($processed === 0 && $skipped === 0) {
            $notes[] = 'Không có nhân viên ACTIVE nào trong kỳ.';
        }

        return [
            'period_id' => $salaryPeriodId,
            'employees_processed' => $processed,
            'employees_skipped' => $skipped,
            'totals' => $totals,
            'notes' => $notes,
        ];
    }

    /**
     * A detail is locked when its meta->locked flag is truthy.
     */
    private function detailLocked(object $detail): bool
    {
        if (empty($detail->meta)) {
            return false;
        }

        $meta = is_string($detail->meta) ? json_decode($detail->meta, true) : (array) $detail->meta;

        return is_array($meta) && ! empty($meta['locked']);
    }

    /**
     * Fallback base salary from the employee's active contract (meta.basic_salary)
     * when employees.base_salary is empty. Keeps payroll robust to denorm drift.
     */
    private function contractBaseSalary(int $employeeId): float
    {
        $contract = DB::table('contracts')
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['CÓ_HIỆU_LỰC', 'ACTIVE'])
            ->orderByDesc('id')
            ->first();

        if (! $contract || ! $contract->meta) {
            return 0.0;
        }
        $meta = is_string($contract->meta) ? json_decode($contract->meta, true) : (array) $contract->meta;
        $raw = is_array($meta) ? ($meta['basic_salary'] ?? null) : null;

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * Replace the breakdown line items for a detail (idempotent rewrite).
     *
     * @param  array<int, array{0:string,1:string,2:string,3:float}>  $lines
     */
    private function writeBreakdowns(int $detailId, int $tenantId, int $legalEntityId, $now, array $lines): void
    {
        DB::table('salary_breakdowns')
            ->where('salary_detail_id', $detailId)
            ->where('tenant_id', $tenantId)
            ->delete();

        $rows = [];
        foreach ($lines as [$type, $code, $name, $amount]) {
            $rows[] = [
                'salary_detail_id' => $detailId,
                'item_type' => $type,
                'item_code' => $code,
                'item_name' => $name,
                'amount' => round((float) $amount, 4),
                'meta' => null,
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            DB::table('salary_breakdowns')->insert($rows);
        }
    }
}

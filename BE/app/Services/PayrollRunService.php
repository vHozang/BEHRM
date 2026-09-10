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
    /** Period statuses that forbid (re)computation (đã chốt hoặc đã chi trả). */
    // CHỜ_DUYỆT: kỳ đã trình chốt (maker–checker) — cấm tính lại khi đang chờ duyệt.
    // PUBLIC vì PayrollController::run() pre-check trước khi dispatch (1 nguồn, khỏi trôi lệch).
    public const LOCKED_PERIOD_STATUSES = ['CLOSED', 'LOCKED', 'PAID', 'ĐÃ_ĐÓNG', 'DA_DONG', 'ĐÃ_TRẢ', 'DA_TRA', 'CHỜ_DUYỆT'];

    public function __construct(
        private readonly PayrollTaxService $tax,
        private readonly InsuranceService $insurance,
        private readonly AttendanceSummaryService $attendanceSummary,
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

        // Đồng bộ: rebuild bảng công tháng NGAY TRƯỚC khi tính lương để mọi
        // thay đổi chấm công / đơn từ mới duyệt đều được phản ánh (tránh tính
        // lương trên summary cũ). Idempotent — upsert theo (tenant, NV, kỳ).
        $this->attendanceSummary->build($salaryPeriodId);

        $allowancesTaxable = (bool) HrmConfig::get('payroll.allowances_taxable_by_default', true);

        // Nhân viên đang làm (chính thức + thử việc), loại tài khoản hệ thống.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->where(fn ($query) => $query->whereNull('hire_date')->orWhere('hire_date', '<=', $periodEnd))
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
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
                $contract = $this->effectiveContract($employeeId, $tenantId, $legalEntityId, $periodStart, $periodEnd);

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
                    $baseSalary = $this->contractBaseSalary($contract);
                }

                // Still no base salary → skip rather than clobber any existing,
                // possibly hand-keyed, salary_details row with a near-zero figure.
                if ($baseSalary <= 0.0) {
                    $nullBaseCount++;
                    $skipped++;

                    continue;
                }

                // Phụ cấp trong kỳ, kèm CỜ từ catalog `allowances` (nghiệp vụ thật —
                // đối chiếu phiếu lương nhà máy ADMS/Aureole, khớp TT10/2020 + Đ.89):
                //  - is_insurable: tính vào NỀN BHXH (kỹ năng/thâm niên/chức vụ CÓ;
                //    chuyên cần/đi lại/ăn ca KHÔNG).
                //  - is_taxable: tính vào thu nhập chịu thuế (ăn ca miễn tới trần TT111).
                //  - meta->in_ot_base: tính vào ĐƠN GIÁ giờ tăng ca (ADMS: kỹ năng + đi lại).
                $allowanceRows = DB::table('employee_allowances as ea')
                    ->leftJoin('allowances as a', 'a.id', '=', 'ea.allowance_id')
                    ->where('ea.tenant_id', $tenantId)
                    ->where('ea.employee_id', $employeeId)
                    ->where('ea.is_active', DB::raw('true'))
                    ->where(function ($q) use ($periodEnd) {
                        $q->whereNull('ea.effective_date')->orWhere('ea.effective_date', '<=', $periodEnd);
                    })
                    ->where(function ($q) use ($periodStart) {
                        $q->whereNull('ea.expiry_date')->orWhere('ea.expiry_date', '>=', $periodStart);
                    })
                    ->get(['ea.amount', 'a.allowance_name', 'a.is_insurable', 'a.is_taxable', 'a.meta as allowance_meta']);

                $allowanceTotal = 0.0;
                $insurableAllowance = 0.0;   // vào nền BHXH
                $taxableAllowance = 0.0;     // vào thu nhập chịu thuế
                $otBaseAllowance = 0.0;      // vào đơn giá giờ OT
                $allowanceNamed = [];        // [tên => tiền] cho phiếu lương kiểu ADMS
                foreach ($allowanceRows as $ar) {
                    $amt = (float) $ar->amount;
                    $allowanceTotal += $amt;
                    $anm = trim((string) ($ar->allowance_name ?? '')) ?: 'Phụ cấp khác';
                    $allowanceNamed[$anm] = ($allowanceNamed[$anm] ?? 0) + $amt;
                    // Không join được catalog (allowance_id null) → coi như taxable,
                    // không insurable (giữ hành vi cũ, không âm thầm tăng tiền đóng BH).
                    if (filter_var($ar->is_insurable ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        $insurableAllowance += $amt;
                    }
                    if (filter_var($ar->is_taxable ?? true, FILTER_VALIDATE_BOOLEAN)) {
                        $taxableAllowance += $amt;
                    }
                    $aMeta = is_string($ar->allowance_meta) ? (json_decode($ar->allowance_meta, true) ?: []) : (array) ($ar->allowance_meta ?? []);
                    if (! empty($aMeta['in_ot_base'])) {
                        $otBaseAllowance += $amt;
                    }
                }

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
                // Ngày KHÔNG lương = nghỉ không lương (unpaid_leave_days) + vắng
                // không phép (ABSENT, lấy từ summary meta.absent_days). Nghỉ CÓ
                // phép (paid_leave_days) KHÔNG bị trừ. Ngày thiếu bản ghi chấm công
                // không tính là vắng (tránh trừ oan khi dữ liệu chấm công chưa đủ).
                $prorate = (bool) HrmConfig::get('payroll.prorate_by_attendance', true);
                $prorationFactor = 1.0;
                $prorationLabel = 'NONE_FULL_MONTH';
                $unpaidLeaveDays = 0.0;
                $absentDays = 0.0;

                if ($prorate && $attendance) {
                    $standardDays = (float) ($attendance->standard_days ?? 0);
                    $unpaidLeaveDays = (float) ($attendance->unpaid_leave_days ?? 0);

                    $summaryMeta = [];
                    if (! empty($attendance->meta)) {
                        $summaryMeta = is_string($attendance->meta)
                            ? (json_decode($attendance->meta, true) ?: [])
                            : (array) $attendance->meta;
                    }
                    $absentDays = (float) ($summaryMeta['absent_days'] ?? 0);

                    $unpaidDays = $unpaidLeaveDays + $absentDays;

                    if ($standardDays > 0 && $unpaidDays > 0) {
                        $paidDays = max(0.0, $standardDays - $unpaidDays);
                        $prorationFactor = round($paidDays / $standardDays, 6);
                        $prorationLabel = "PRORATED {$paidDays}/{$standardDays} (nghỉ KL {$unpaidLeaveDays} + vắng {$absentDays})";
                    }
                }

                $proratedBase = round($baseSalary * $prorationFactor, 4);
                $proratedAllowance = round($allowanceTotal * $prorationFactor, 4);

                // Overtime earning — tính theo HỆ SỐ RIÊNG của từng đơn OT
                // (ngày thường 150%, thứ Bảy/CN 200%, lễ 300%: Bộ luật LĐ Đ.98)
                // thay vì áp một hệ số phẳng. Hệ số lấy từ meta.pay_factor của
                // đơn (fallback: overtime_multiplier trong config). OT đã quy đổi
                // nghỉ bù thì không trả tiền (tránh hưởng kép).
                $defaultOtMultiplier = (float) HrmConfig::get('payroll.overtime_multiplier', 1.5);
                // Phụ trội làm ĐÊM (Đ.98 BLLĐ: ít nhất +30% đơn giá) — cộng thêm
                // trên mỗi giờ đêm (meta.night_hours) NGOÀI hệ số ngày.
                $nightPremium = (float) HrmConfig::get('payroll.night_ot_premium', 0.3);
                $otRows = DB::table('overtime_requests')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['APPROVED', 'ĐÃ_DUYỆT'])
                    ->whereBetween('work_date', [$periodStart, $periodEnd])
                    ->where(fn ($query) => $query->whereNull('meta->converted_to_comp_off')->orWhere('meta->converted_to_comp_off', false))
                    ->get(['total_hours', 'meta']);

                $overtimeHours = 0.0;      // tổng giờ OT thô (để hiển thị)
                $weightedOtHours = 0.0;    // tổng giờ đã nhân hệ số (để tính tiền)
                $otBuckets = [];           // [hệ số => giờ] tách thường/CN/lễ cho phiếu ADMS
                $otNightHours = 0.0;       // giờ đêm hưởng phụ trội
                foreach ($otRows as $ot) {
                    $otMeta = [];
                    if (! empty($ot->meta)) {
                        $otMeta = is_string($ot->meta) ? (json_decode($ot->meta, true) ?: []) : (array) $ot->meta;
                    }
                    $h = max(0, (float) ($otMeta['payable_overtime_minutes'] ?? 0) / 60);
                    if ($h <= 0) {
                        continue;
                    }
                    $factor = $otMeta['pay_factor'] ?? $otMeta['multiplier'] ?? $defaultOtMultiplier;
                    $factor = (float) $factor;
                    if ($factor <= 0) {
                        $factor = $defaultOtMultiplier;
                    }
                    $overtimeHours += $h;
                    $weightedOtHours += $h * $factor;
                    $otBuckets[(string) $factor] = ($otBuckets[(string) $factor] ?? 0) + $h;
                    $nightHours = (float) ($otMeta['night_hours'] ?? 0);
                    if ($nightHours > 0 && $nightPremium > 0) {
                        $weightedOtHours += min($nightHours, $h) * $nightPremium;
                        $otNightHours += min($nightHours, $h);
                    }
                }

                $overtimePay = 0.0;
                $otBasePay = 0.0;     // phần bằng đơn giá giờ thường (100%) — chịu thuế
                $otPremiumPay = 0.0;  // phần phụ trội (50/100/200%…) — MIỄN thuế TNCN
                if ($weightedOtHours > 0) {
                    $stdDaysForRate = $attendance && (float) ($attendance->standard_days ?? 0) > 0
                        ? (float) $attendance->standard_days
                        : 26.0;
                    $hoursPerDay = (float) HrmConfig::get('payroll.standard_hours_per_day', 8);
                    $monthlyHours = max(1.0, $stdDaysForRate * $hoursPerDay);
                    // Đơn giá OT = (LCB + phụ cấp gắn cờ in_ot_base) / giờ chuẩn —
                    // khớp phiếu ADMS: (6.949.000+300.000+180.000)/26/8 × 1.5 × 24h.
                    $hourlyRate = ($baseSalary + $otBaseAllowance) / $monthlyHours;
                    // Hệ số đã nằm trong weightedOtHours nên không nhân lại.
                    $overtimePay = round($weightedOtHours * $hourlyRate, 4);
                    // Miễn thuế TNCN phần trả CAO HƠN đơn giá giờ thường của tiền
                    // làm thêm giờ / làm đêm (TT 111/2013 Đ.3; nhấn lại trong đợt
                    // luật hiệu lực 01/07/2026). Ví dụ OT 150%: 100% chịu thuế,
                    // 50% phụ trội miễn thuế.
                    $otBasePay = round($overtimeHours * $hourlyRate, 4);
                    $otPremiumPay = round(max(0.0, $overtimePay - $otBasePay), 4);
                }
                $otPremiumExempt = (bool) HrmConfig::get('payroll.ot_premium_tax_exempt', true);
                // Phần OT tính vào thu nhập CHỊU THUẾ.
                $overtimeTaxable = $otPremiumExempt ? $otBasePay : $overtimePay;

                // Công khoán theo sản phẩm (piece-rate): tổng sản lượng trong kỳ.
                $pieceRatePay = (float) DB::table('piece_rate_entries')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->whereBetween('work_date', [$periodStart, $periodEnd])
                    ->sum('amount');

                // Chỉ điều chỉnh đã qua maker-checker mới được engine sử dụng. APPLIED
                // vẫn được đọc khi chạy lại để salary_details được ghi đè idempotent.
                $adjustmentRows = DB::table('payroll_adjustments')
                    ->where('tenant_id', $tenantId)
                    ->where('legal_entity_id', $legalEntityId)
                    ->where('employee_id', $employeeId)
                    ->where('paid_period_id', $salaryPeriodId)
                    ->whereIn('status', ['APPROVED', 'APPLIED'])
                    ->get(['id', 'adjustment_type', 'amount', 'status']);
                $earningTypes = ['BONUS', 'THANG_13', 'THUONG', 'THUONG_TET', 'EARNING', 'OTHER_EARNING'];
                $deductionTypes = ['DEDUCTION', 'ADVANCE', 'OTHER_DEDUCTION'];
                $bonusTypes = ['BONUS', 'THANG_13', 'THUONG', 'THUONG_TET'];
                $bonusTotal = (float) $adjustmentRows
                    ->whereIn('adjustment_type', $bonusTypes)
                    ->sum('amount');
                $otherAdjustmentEarnings = (float) $adjustmentRows
                    ->whereIn('adjustment_type', array_values(array_diff($earningTypes, $bonusTypes)))
                    ->sum('amount');
                $adjustmentEarningTotal = $bonusTotal + $otherAdjustmentEarnings;
                $adjustmentDeductionTotal = (float) $adjustmentRows
                    ->whereIn('adjustment_type', $deductionTypes)
                    ->sum('amount');

                // GROSS = tổng thu nhập TRƯỚC khấu trừ. Khấu trừ cố định (tạm ứng,
                // đoàn phí…) là khấu trừ SAU THUẾ: trừ vào NET, KHÔNG giảm gross và
                // KHÔNG giảm thu nhập chịu thuế (chỉ BH + giảm trừ gia cảnh mới
                // giảm thuế). Trước đây trừ vào gross → gross sai + tính thiếu thuế.
                $gross = round(max(0.0, $proratedBase + $proratedAllowance + $overtimePay + $pieceRatePay + $adjustmentEarningTotal), 4);
                // Thu nhập chịu thuế: dùng $overtimeTaxable (đã loại phần phụ trội
                // OT được miễn thuế) thay vì toàn bộ $overtimePay. Thưởng cộng đủ (chịu thuế).
                // Thu nhập chịu thuế dùng phụ cấp GẮN CỜ is_taxable (ăn ca miễn thuế
                // theo TT111); config allowances_taxable_by_default chỉ còn là fallback
                // khi tắt hẳn (false → bỏ toàn bộ phụ cấp khỏi thuế như cũ).
                $proratedTaxableAllowance = round($taxableAllowance * $prorationFactor, 4);
                $grossTaxable = $allowancesTaxable
                    ? round(max(0.0, $proratedBase + $proratedTaxableAllowance + $overtimeTaxable + $pieceRatePay + $adjustmentEarningTotal), 4)
                    : round(max(0.0, $proratedBase + $overtimeTaxable + $pieceRatePay + $adjustmentEarningTotal), 4);

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
                // Nền BHXH = LCB + phụ cấp is_insurable (Đ.89 Luật BHXH + TT10/2020:
                // khoản bổ sung ổn định tính chất lương phải đóng; chuyên cần/đi lại/
                // ăn ca được loại). Khớp phiếu ADMS: (6.949.000+400.000) × 8% = 587.920.
                $insuranceBase = $baseSalary + $insurableAllowance;
                $empInsurance = $this->insurance->employee($insuranceBase);
                $employerInsurance = $this->insurance->employer($insuranceBase);

                $relief = $this->tax->personalRelief($dependentCount);
                $taxableIncome = max(0.0, round($grossTaxable - $empInsurance['total'] - $relief, 4));
                $pit = $this->tax->pit($taxableIncome);

                $preAttendanceDeductionNet = round(
                    $gross - $empInsurance['total'] - $pit['tax'] - $fixedDeductions - $adjustmentDeductionTotal,
                    4,
                );
                $standardDaysForViolation = $attendance && (float) ($attendance->standard_days ?? 0) > 0
                    ? (float) $attendance->standard_days
                    : 0.0;
                $attendanceViolationDeduction = 0.0;
                $attendanceReviewRows = collect();
                if ($standardDaysForViolation > 0) {
                    $attendanceReviewRows = DB::table('attendance_payroll_reviews')
                        ->where('tenant_id', $tenantId)
                        ->where('employee_id', $employeeId)
                        ->whereBetween('work_date', [$periodStart, $periodEnd])
                        ->whereIn('status', ['APPROVED', 'APPLIED'])
                        ->whereNotNull('approved_percent')
                        ->get(['id', 'approved_percent']);
                    foreach ($attendanceReviewRows as $review) {
                        $attendanceViolationDeduction += ($baseSalary / $standardDaysForViolation)
                            * ((float) $review->approved_percent / 100);
                    }
                    $attendanceViolationDeduction = round($attendanceViolationDeduction, 4);
                }

                // This deduction is deliberately after insurance and PIT. Do not
                // clamp a negative result: readiness must expose it for HR to fix.
                $net = round($preAttendanceDeductionNet - $attendanceViolationDeduction, 4);

                $meta = [
                    'engine' => 'vn-payroll-v1',
                    'computed_at' => $now->toIso8601String(),
                    'base_salary' => $baseSalary,
                    'prorated_base' => $proratedBase,
                    'proration_factor' => $prorationFactor,
                    'proration_unpaid_leave_days' => $unpaidLeaveDays,
                    'proration_absent_days' => $absentDays,
                    'allowance_total' => $allowanceTotal,
                    'prorated_allowance' => $proratedAllowance,
                    'insurable_allowance' => $insurableAllowance,
                    'insurance_base' => $insuranceBase,
                    'taxable_allowance' => $taxableAllowance,
                    'ot_base_allowance' => $otBaseAllowance,
                    'overtime_hours' => $overtimeHours,
                    'overtime_weighted_hours' => $weightedOtHours,
                    'overtime_pay' => $overtimePay,
                    'overtime_base_pay' => $otBasePay,
                    'overtime_premium_pay' => $otPremiumPay,
                    'overtime_premium_tax_exempt' => $otPremiumExempt,
                    'piece_rate_pay' => $pieceRatePay,
                    'bonus_total' => $bonusTotal,
                    'payroll_adjustment_earning_total' => $adjustmentEarningTotal,
                    'payroll_adjustment_other_earning_total' => $otherAdjustmentEarnings,
                    'payroll_adjustment_deduction_total' => $adjustmentDeductionTotal,
                    'payroll_adjustment_ids' => $adjustmentRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'fixed_deduction_total' => $fixedDeductions,
                    'pre_attendance_deduction_net' => $preAttendanceDeductionNet,
                    'attendance_violation_deduction' => $attendanceViolationDeduction,
                    'attendance_review_ids' => $attendanceReviewRows->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'attendance_deduction_exceeds_net' => $attendanceViolationDeduction > $preAttendanceDeductionNet,
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
                    'contract_id' => $contract?->id,
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

                // Phiếu lương kiểu ADMS: từng phụ cấp THEO TÊN + OT tách hệ số
                // (thường 150%/CN 200%/lễ 300%) + các dòng INFO đối soát.
                $lines = [
                    ['EARNING', 'BASE', 'Lương cơ bản (theo công)', $proratedBase],
                ];
                foreach ($allowanceNamed as $anm => $aval) {
                    $lines[] = ['EARNING', 'ALLOWANCE', $anm, round($aval * $prorationFactor, 4)];
                }
                if ($overtimePay > 0) {
                    $otLabels = ['1.5' => 'Tăng ca ngày thường (150%)', '2' => 'Tăng ca Chủ nhật (200%)', '3' => 'Tăng ca ngày lễ (300%)'];
                    $stdD = (float) ($attendance?->standard_days ?? 0) ?: 26.0;
                    $hourly = ($baseSalary + $otBaseAllowance) / max(1.0, $stdD * (float) HrmConfig::get('payroll.standard_hours_per_day', 8));
                    $bucketSum = 0.0;
                    foreach ($otBuckets as $factorKey => $bh) {
                        $label = $otLabels[$factorKey] ?? ('Tăng ca (hệ số '.$factorKey.')');
                        $amt = round($bh * (float) $factorKey * $hourly, 4);
                        $bucketSum += $amt;
                        $lines[] = ['EARNING', 'OVERTIME', $label." — {$bh}h", $amt];
                    }
                    // Phần còn lại của overtimePay = phụ trội ca đêm (nếu có giờ đêm).
                    $nightAmt = round($overtimePay - $bucketSum, 4);
                    if ($otNightHours > 0 && $nightAmt > 0) {
                        $lines[] = ['EARNING', 'OVERTIME', 'Phụ trội ca đêm ('.round($nightPremium * 100).'%) — '.$otNightHours.'h', $nightAmt];
                    }
                }
                $lines = array_merge($lines, [
                    ['INFO', 'OT_EXEMPT', 'Phụ trội tăng ca (miễn thuế TNCN)', $otPremiumExempt ? $otPremiumPay : 0.0],
                    ['EARNING', 'PIECE_RATE', 'Công khoán sản phẩm', $pieceRatePay],
                    ['EARNING', 'BONUS', 'Thưởng / Lương tháng 13', $bonusTotal],
                    ['EARNING', 'PAYROLL_ADJUSTMENT', 'Điều chỉnh tăng lương khác', $otherAdjustmentEarnings],
                ]);
                $this->writeBreakdowns($detailId, $tenantId, $legalEntityId, $now, array_merge($lines, [
                    ['DEDUCTION', 'INS_BHXH', 'BHXH (8%)', $empInsurance['bhxh']],
                    ['DEDUCTION', 'INS_BHYT', 'BHYT (1.5%)', $empInsurance['bhyt']],
                    ['DEDUCTION', 'INS_BHTN', 'BHTN (1%)', $empInsurance['bhtn']],
                    ['DEDUCTION', 'PIT', 'Thuế TNCN', $pit['tax']],
                    ['DEDUCTION', 'FIXED_DEDUCTION', 'Khấu trừ cố định', $fixedDeductions],
                    ['DEDUCTION', 'PAYROLL_ADJUSTMENT', 'Điều chỉnh khấu trừ lương', $adjustmentDeductionTotal],
                    ['DEDUCTION', 'ATTENDANCE_VIOLATION', 'Khấu trừ đi trễ/về sớm', $attendanceViolationDeduction],
                    ['INFO', 'INS_BASE', 'Nền đóng BHXH (lương + PC tính chất lương)', $insuranceBase],
                    ['INFO', 'WORK_DAYS', 'Số ngày công thực tế (chuẩn '.(float) ($attendance?->standard_days ?? 26).')', (float) ($attendance?->actual_work_days ?? 0)],
                    ['INFO', 'RELIEF', 'Giảm trừ gia cảnh ('.$dependentCount.' người phụ thuộc)', $relief],
                    ['INFO', 'TAXABLE_INCOME', 'Thu nhập chịu thuế', $taxableIncome],
                    ['NET', 'NET', 'Thực nhận', $net],
                    ['EMPLOYER_COST', 'ER_BHXH', 'BHXH (DN 17.5%)', $employerInsurance['bhxh']],
                    ['EMPLOYER_COST', 'ER_BHYT', 'BHYT (DN 3%)', $employerInsurance['bhyt']],
                    ['EMPLOYER_COST', 'ER_BHTN', 'BHTN (DN 1%)', $employerInsurance['bhtn']],
                ]));

                $totals['gross'] += $gross;
                $totals['insurance'] += $empInsurance['total'];
                $totals['pit'] += $pit['tax'];
                $totals['net'] += $net;
                if ($attendanceReviewRows->isNotEmpty()) {
                    DB::table('attendance_payroll_reviews')
                        ->whereIn('id', $attendanceReviewRows->pluck('id')->all())
                        ->where('status', 'APPROVED')
                        ->update(['status' => 'APPLIED', 'applied_at' => $now, 'updated_at' => $now]);
                }
                if ($adjustmentRows->where('status', 'APPROVED')->isNotEmpty()) {
                    DB::table('payroll_adjustments')
                        ->where('tenant_id', $tenantId)
                        ->where('legal_entity_id', $legalEntityId)
                        ->whereIn('id', $adjustmentRows->where('status', 'APPROVED')->pluck('id')->all())
                        ->where('status', 'APPROVED')
                        ->update([
                            'status' => 'APPLIED',
                            'paid_salary_detail_id' => $detailId,
                            'applied_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
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

    /** Hợp đồng có hiệu lực giao với kỳ lương, mới nhất thắng nếu có nhiều bản ghi. */
    private function effectiveContract(
        int $employeeId,
        int $tenantId,
        int $legalEntityId,
        string $periodStart,
        string $periodEnd,
    ): ?object {
        return DB::table('contracts')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
            ->where('start_date', '<=', $periodEnd)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $periodStart))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first(['id', 'meta']);
    }

    private function contractBaseSalary(?object $contract): float
    {
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

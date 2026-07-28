<?php

namespace App\Services;

use App\Support\HrmConfig;

/**
 * Compulsory social-insurance computation (bảo hiểm bắt buộc) — pure,
 * config-driven. Applies the statutory rates and the two contribution-base
 * caps from HrmConfig (per-tenant override → config/hrm.php default):
 *
 *   - BHXH + BHYT base capped at 20x lương cơ sở (base_salary).
 *   - BHTN base capped at 20x lương tối thiểu vùng (region_min_wage).
 */
class InsuranceService
{
    /**
     * Employee-side contributions on an insurance base.
     *
     * @return array{bhxh: float, bhyt: float, bhtn: float, total: float, base: array{bhxh_bhyt: float, bhtn: float}}
     */
    public function employee(float $insuranceBase): array
    {
        return $this->compute($insuranceBase, (array) HrmConfig::get('payroll.insurance.employee', []));
    }

    /**
     * Employer-side contributions on an insurance base (for cost reporting).
     *
     * @return array{bhxh: float, bhyt: float, bhtn: float, total: float, base: array{bhxh_bhyt: float, bhtn: float}}
     */
    public function employer(float $insuranceBase): array
    {
        return $this->compute($insuranceBase, (array) HrmConfig::get('payroll.insurance.employer', []));
    }

    /**
     * @param  array{bhxh?: float, bhyt?: float, bhtn?: float}  $rates
     * @return array{bhxh: float, bhyt: float, bhtn: float, total: float, base: array{bhxh_bhyt: float, bhtn: float}}
     */
    private function compute(float $insuranceBase, array $rates): array
    {
        $base = max(0.0, $insuranceBase);

        $baseSalary = (float) HrmConfig::get('payroll.base_salary', 0);
        $regionMin = (float) HrmConfig::get('payroll.region_min_wage', 0);
        $bhxhBhytMult = (float) HrmConfig::get('payroll.caps.bhxh_bhyt_multiplier', 20);
        $bhtnMult = (float) HrmConfig::get('payroll.caps.bhtn_multiplier', 20);

        $bhxhBhytCap = $baseSalary * $bhxhBhytMult;
        $bhtnCap = $regionMin * $bhtnMult;

        // Cap of 0 (mis-config) is treated as "no cap" to avoid zeroing everything.
        $bhxhBhytBase = $bhxhBhytCap > 0 ? min($base, $bhxhBhytCap) : $base;
        $bhtnBase = $bhtnCap > 0 ? min($base, $bhtnCap) : $base;

        // FLOOR: tiền lương đóng BH không thấp hơn lương tối thiểu vùng (Đ.89
        // Luật BHXH). Chỉ floor khi có base thực (>0) — NV lương 0 đã bị bỏ qua.
        if ($regionMin > 0 && $base > 0) {
            $bhxhBhytBase = max($bhxhBhytBase, $regionMin);
            $bhtnBase = max($bhtnBase, $regionMin);
        }

        $bhxh = round($bhxhBhytBase * (float) ($rates['bhxh'] ?? 0), 4);
        $bhyt = round($bhxhBhytBase * (float) ($rates['bhyt'] ?? 0), 4);
        $bhtn = round($bhtnBase * (float) ($rates['bhtn'] ?? 0), 4);

        return [
            'bhxh' => $bhxh,
            'bhyt' => $bhyt,
            'bhtn' => $bhtn,
            'total' => round($bhxh + $bhyt + $bhtn, 4),
            'base' => [
                'bhxh_bhyt' => $bhxhBhytBase,
                'bhtn' => $bhtnBase,
            ],
        ];
    }
}

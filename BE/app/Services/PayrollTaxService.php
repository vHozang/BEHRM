<?php

namespace App\Services;

use App\Support\HrmConfig;

/**
 * Personal income tax (PIT) computation — pure, config-driven.
 *
 * Implements the Vietnam monthly progressive bracket model but reads every
 * number from HrmConfig (per-tenant override → config/hrm.php default), so
 * swapping the country/year/company policy is a config change only. All methods
 * are side-effect free.
 */
class PayrollTaxService
{
    /**
     * Compute progressive PIT on an already-computed taxable income.
     *
     * Each bracket is taxed marginally: only the slice of income that falls
     * inside the bracket is taxed at that bracket's rate.
     *
     * @return array{tax: float, by_bracket: array<int, array{from: float, to: float|null, rate: float, taxable: float, tax: float}>}
     */
    public function pit(float $taxableIncome): array
    {
        $income = max(0.0, $taxableIncome);
        $brackets = HrmConfig::get('payroll.pit_brackets', []);

        $tax = 0.0;
        $lower = 0.0;
        $byBracket = [];

        foreach ($brackets as $bracket) {
            $upper = $bracket['up'] ?? null; // null => open-ended top bracket
            $rate = (float) $bracket['rate'];

            // Width of income that lands inside this bracket.
            $ceiling = $upper === null ? $income : min($income, (float) $upper);
            $slice = max(0.0, $ceiling - $lower);
            $sliceTax = round($slice * $rate, 4);

            if ($slice > 0) {
                $byBracket[] = [
                    'from' => $lower,
                    'to' => $upper === null ? null : (float) $upper,
                    'rate' => $rate,
                    'taxable' => $slice,
                    'tax' => $sliceTax,
                ];
            }

            $tax += $sliceTax;

            if ($upper === null || $income <= (float) $upper) {
                break;
            }

            $lower = (float) $upper;
        }

        return [
            'tax' => round($tax, 4),
            'by_bracket' => $byBracket,
        ];
    }

    /**
     * Family-circumstance relief (giảm trừ gia cảnh):
     * personal deduction + per-dependent deduction.
     */
    public function personalRelief(int $dependents): float
    {
        $personal = (float) HrmConfig::get('payroll.personal_deduction', 0);
        $perDependent = (float) HrmConfig::get('payroll.dependent_deduction', 0);

        return $personal + max(0, $dependents) * $perDependent;
    }
}

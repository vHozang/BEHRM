<?php

namespace App\Services;

use App\Models\LegalEntity;
use App\Models\SalaryDetail;
use App\Support\HrmConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayslipDataBuilder
{
    public const TEMPLATE_VERSION = 'excel-final-v1';

    public function build(SalaryDetail $detail): array
    {
        $detail->loadMissing([
            'employee:id,full_name,employee_code,company_email,personal_email,department_id,position_id,legal_entity_id,profile',
            'employee.department:id,department_code,department_name',
            'employee.position:id,position_code,position_name',
            'period:id,period_code,period_name,start_date,end_date,status,legal_entity_id',
        ]);

        $breakdowns = DB::table('salary_breakdowns')
            ->where('salary_detail_id', $detail->id)
            ->orderBy('id')
            ->get();
        $attendance = DB::table('salary_attendance_summary')
            ->where('employee_id', $detail->employee_id)
            ->where('period_id', $detail->period_id)
            ->first();
        $legalEntity = LegalEntity::query()
            ->select(['id', 'name', 'code', 'tax_code', 'address', 'meta'])
            ->find($detail->legal_entity_id);

        $earnings = $this->fixedEarningRows();
        $deductions = $this->fixedDeductionRows();

        foreach ($breakdowns->where('item_type', 'EARNING') as $row) {
            $amount = (float) $row->amount;
            $code = strtoupper((string) $row->item_code);
            $name = $this->normalize((string) $row->item_name);

            if ($code === 'BASE') {
                $earnings['work_100']['amount'] += $amount;

                continue;
            }

            if ($code === 'OVERTIME') {
                $target = $this->overtimeTarget((string) $row->item_name, $name);
                $earnings[$target]['amount'] += $amount;
                if ($target !== 'other_income') {
                    $earnings[$target]['quantity'] += $this->hoursFromName((string) $row->item_name);
                }

                continue;
            }

            if ($code === 'ALLOWANCE') {
                $earnings[$this->allowanceTarget($name)]['amount'] += $amount;

                continue;
            }

            $earnings['other_income']['amount'] += $amount;
        }

        foreach ($breakdowns->where('item_type', 'DEDUCTION') as $row) {
            $amount = (float) $row->amount;
            $code = strtoupper((string) $row->item_code);
            $name = $this->normalize((string) $row->item_name);

            $target = match ($code) {
                'INS_BHXH' => 'bhxh',
                'INS_BHYT' => 'bhyt',
                'INS_BHTN' => 'bhtn',
                'PIT' => 'pit',
                'TAX_REFUND' => 'tax_refund',
                default => str_contains($name, 'cong doan') ? 'union_fee' : 'other_deduction',
            };
            $deductions[$target]['amount'] += $amount;
        }

        $actualDays = (float) ($attendance->actual_working_days ?? 0);
        $earnings['work_100']['quantity'] = $actualDays;

        $separator = (string) HrmConfig::get('display.money_group_separator', '.');
        if (! in_array($separator, ['.', ','], true)) {
            $separator = '.';
        }

        return [
            'template_version' => self::TEMPLATE_VERSION,
            'title' => (string) HrmConfig::get('payslip.title', 'PHIẾU LƯƠNG THÁNG'),
            'footer' => (string) HrmConfig::get(
                'payslip.footer',
                'Công ty xin chân thành cảm ơn toàn thể nhân viên! Vui lòng đối chiếu số tiền trong tài khoản.'
            ),
            'money_separator' => $separator,
            'legal_entity' => $legalEntity,
            'employee' => $detail->employee,
            'period' => $detail->period,
            'attendance' => $attendance,
            'earnings' => array_values($this->formatRows($earnings, $separator)),
            'deductions' => array_values($this->formatRows($deductions, $separator)),
            'gross' => (float) $detail->gross_salary,
            'gross_formatted' => $this->formatMoney((float) $detail->gross_salary, $separator),
            'total_deductions' => max(0.0, (float) $detail->gross_salary - (float) $detail->net_salary),
            'total_deductions_formatted' => $this->formatMoney(
                max(0.0, (float) $detail->gross_salary - (float) $detail->net_salary),
                $separator
            ),
            'net' => (float) $detail->net_salary,
            'net_formatted' => $this->formatMoney((float) $detail->net_salary, $separator),
            'generated_label' => now()->format('d/m/Y H:i'),
        ];
    }

    private function fixedEarningRows(): array
    {
        return [
            'work_75' => $this->row('Ngày công hưởng 75%'),
            'work_85' => $this->row('Ngày công hưởng 85%'),
            'work_100' => $this->row('Lương theo ngày công 100%'),
            'training_probation' => $this->row('Đào tạo / thử việc'),
            'ot_weekday' => $this->row('Tăng ca ngày thường'),
            'ot_saturday' => $this->row('Tăng ca Thứ bảy'),
            'ot_sunday' => $this->row('Tăng ca Chủ nhật'),
            'ot_holiday' => $this->row('Tăng ca ngày lễ'),
            'shift_3' => $this->row('Phụ cấp ca 3 / ca đêm'),
            'skill' => $this->row('Phụ cấp kỹ năng'),
            'responsibility' => $this->row('Phụ cấp trách nhiệm / chức vụ'),
            'travel' => $this->row('Phụ cấp đi lại'),
            'attendance' => $this->row('Phụ cấp chuyên cần'),
            'seniority' => $this->row('Phụ cấp thâm niên'),
            'housing' => $this->row('Phụ cấp nhà ở'),
            'qualification' => $this->row('Phụ cấp bằng cấp / trình độ'),
            'child_support' => $this->row('Trợ cấp con nhỏ'),
            'meal' => $this->row('Phụ cấp tiền ăn'),
            'company_policy' => $this->row('Phụ cấp theo chính sách công ty'),
            'other_income' => $this->row('Khoản thu nhập khác'),
        ];
    }

    private function fixedDeductionRows(): array
    {
        return [
            'bhxh' => $this->row('BHXH (8%)'),
            'bhyt' => $this->row('BHYT (1,5%)'),
            'bhtn' => $this->row('BHTN (1%)'),
            'pit' => $this->row('Thuế thu nhập cá nhân'),
            'union_fee' => $this->row('Đoàn phí / Công đoàn'),
            'tax_refund' => $this->row('Hoàn thuế'),
            'other_deduction' => $this->row('Khoản khấu trừ khác'),
        ];
    }

    private function row(string $label): array
    {
        return ['label' => $label, 'quantity' => 0.0, 'amount' => 0.0];
    }

    private function allowanceTarget(string $name): string
    {
        return match (true) {
            str_contains($name, 'ky nang'), str_contains($name, 'tay nghe') => 'skill',
            str_contains($name, 'trach nhiem'), str_contains($name, 'chuc vu') => 'responsibility',
            str_contains($name, 'di lai'), str_contains($name, 'xang'), str_contains($name, 'transport') => 'travel',
            str_contains($name, 'chuyen can') => 'attendance',
            str_contains($name, 'tham nien') => 'seniority',
            str_contains($name, 'nha o') => 'housing',
            str_contains($name, 'bang cap'), str_contains($name, 'trinh do') => 'qualification',
            str_contains($name, 'con nho'), str_contains($name, 'child') => 'child_support',
            str_contains($name, 'an ca'), str_contains($name, 'tien an'), str_contains($name, 'com') => 'meal',
            default => 'company_policy',
        };
    }

    private function normalize(string $value): string
    {
        return strtolower(Str::ascii(trim($value)));
    }

    private function hoursFromName(string $name): float
    {
        return preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*h/iu', $name, $matches)
            ? (float) str_replace(',', '.', $matches[1])
            : 0.0;
    }

    private function overtimeTarget(string $rawName, string $normalizedName): string
    {
        if ($this->hoursFromName($rawName) <= 0) {
            return 'other_income';
        }

        $hasFactor = static fn (array $patterns): bool => collect($patterns)
            ->contains(fn (string $pattern) => preg_match($pattern, $rawName) === 1);

        return match (true) {
            str_contains($normalizedName, 'ngay thuong')
                && $hasFactor(['/\b150\s*%/iu', '/he\s*so\s*1[.,]5/iu']) => 'ot_weekday',
            str_contains($normalizedName, 'thu bay')
                && $hasFactor(['/\b150\s*%/iu', '/\b200\s*%/iu', '/he\s*so\s*(?:1[.,]5|2(?:[.,]0)?)/iu']) => 'ot_saturday',
            str_contains($normalizedName, 'chu nhat')
                && $hasFactor(['/\b200\s*%/iu', '/he\s*so\s*2(?:[.,]0)?/iu']) => 'ot_sunday',
            (str_contains($normalizedName, 'ngay le') || str_contains($normalizedName, 'le tet'))
                && $hasFactor(['/\b300\s*%/iu', '/he\s*so\s*3(?:[.,]0)?/iu']) => 'ot_holiday',
            (str_contains($normalizedName, 'ca dem') || str_contains($normalizedName, 'ban dem'))
                && $hasFactor(['/\b[0-9]+(?:[.,][0-9]+)?\s*%/iu', '/he\s*so\s*[0-9]+(?:[.,][0-9]+)?/iu']) => 'shift_3',
            default => 'other_income',
        };
    }

    private function formatRows(array $rows, string $separator): array
    {
        foreach ($rows as &$row) {
            $row['quantity_formatted'] = $row['quantity'] > 0
                ? rtrim(rtrim(number_format($row['quantity'], 2, ',', ''), '0'), ',')
                : '0';
            $row['amount_formatted'] = $this->formatMoney($row['amount'], $separator);
        }

        return $rows;
    }

    private function formatMoney(float $amount, string $separator): string
    {
        return number_format(round($amount), 0, '', $separator);
    }
}

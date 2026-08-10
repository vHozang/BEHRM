<?php

namespace App\Services;

use App\Models\SalaryPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayslipReadinessService
{
    private const TOLERANCE = 1.0;

    public function analyze(SalaryPeriod $period): array
    {
        $employees = $this->eligibleEmployees($period);
        $details = DB::table('salary_details')
            ->where('tenant_id', $period->tenant_id)
            ->where('legal_entity_id', $period->legal_entity_id)
            ->where('period_id', $period->id)
            ->get()
            ->keyBy('employee_id');

        $breakdowns = DB::table('salary_breakdowns')
            ->whereIn('salary_detail_id', $details->pluck('id')->all())
            ->get()
            ->groupBy('salary_detail_id');

        $ready = [];
        $issues = [];

        foreach ($employees as $employee) {
            $detail = $details->get($employee->id);
            $employeeIssues = $this->validateEmployee($employee, $detail, $breakdowns);

            if ($employeeIssues === []) {
                $ready[] = [
                    'employee_id' => (int) $employee->id,
                    'salary_detail_id' => (int) $detail->id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'department_name' => $employee->department_name,
                ];

                continue;
            }

            array_push($issues, ...$employeeIssues);
        }

        $failedEmployees = collect($issues)->pluck('employee_id')->unique()->count();

        $analysis = [
            'period_id' => (int) $period->id,
            'period_code' => $period->period_code,
            'total_employees' => $employees->count(),
            'pass_count' => count($ready),
            'fail_count' => $failedEmployees,
            'can_publish_partially' => count($ready) > 0,
            'ready' => $ready,
            'issues' => $issues,
        ];

        return $period->isClosed()
            ? $this->applyClosedPeriodExclusions($period, $analysis)
            : $analysis;
    }

    private function eligibleEmployees(SalaryPeriod $period): Collection
    {
        return DB::table('employees as e')
            ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.tenant_id', $period->tenant_id)
            ->where('e.legal_entity_id', $period->legal_entity_id)
            ->whereIn('e.status', ['ACTIVE', 'PROBATION'])
            ->where(fn ($query) => $query->whereNull('e.hire_date')->orWhere('e.hire_date', '<=', $period->end_date))
            ->where(fn ($query) => $query->whereNull('e.profile->system_account')->orWhere('e.profile->system_account', false))
            ->orderBy('e.employee_code')
            ->get([
                'e.id',
                'e.employee_code',
                'e.full_name',
                'd.department_name',
            ]);
    }

    private function validateEmployee(object $employee, ?object $detail, Collection $breakdowns): array
    {
        if (! $detail) {
            return [$this->issue(
                $employee,
                null,
                'MISSING_SALARY_DETAIL',
                'Nhân viên chưa có dữ liệu lương trong kỳ.',
                'Kiểm tra lương cơ bản/hợp đồng rồi chạy lại tính lương; nếu kỳ đã chốt, xử lý điều chỉnh ở kỳ sau.'
            )];
        }

        $issues = [];
        $gross = (float) $detail->gross_salary;
        $net = (float) $detail->net_salary;
        $rows = $breakdowns->get($detail->id, collect());

        if (! is_numeric($detail->gross_salary) || ! is_numeric($detail->net_salary) || $gross < 0 || $net < 0) {
            $issues[] = $this->issue(
                $employee,
                $detail,
                'INVALID_SALARY_AMOUNT',
                'Gross hoặc thực lĩnh không hợp lệ.',
                'Kế toán cần kiểm tra lại dữ liệu chi tiết lương.'
            );
        }

        $base = $rows->first(fn ($row) => $row->item_type === 'EARNING' && $row->item_code === 'BASE');
        if (! $base) {
            $issues[] = $this->issue(
                $employee,
                $detail,
                'MISSING_BASE_BREAKDOWN',
                'Phiếu lương thiếu dòng lương cơ bản.',
                'Chạy lại engine lương hoặc bổ sung breakdown BASE.'
            );
        }

        $netRow = $rows->first(fn ($row) => $row->item_type === 'NET' && $row->item_code === 'NET');
        if (! $netRow) {
            $issues[] = $this->issue(
                $employee,
                $detail,
                'MISSING_NET_BREAKDOWN',
                'Phiếu lương thiếu dòng thực lĩnh.',
                'Chạy lại engine lương hoặc bổ sung breakdown NET.'
            );
        }

        $earnings = (float) $rows->where('item_type', 'EARNING')->sum('amount');
        if (abs($earnings - $gross) > self::TOLERANCE) {
            $issues[] = $this->issue(
                $employee,
                $detail,
                'GROSS_MISMATCH',
                'Tổng các khoản thu không khớp gross.',
                'Kế toán cần đối soát các breakdown thu nhập.'
            );
        }

        $deductions = (float) $rows->where('item_type', 'DEDUCTION')->sum('amount');
        $expectedNet = $gross - $deductions;
        if (abs($expectedNet - $net) > self::TOLERANCE) {
            $issues[] = $this->issue(
                $employee,
                $detail,
                'NET_MISMATCH',
                'Thực lĩnh không khớp gross trừ các khoản khấu trừ.',
                'Kế toán cần đối soát bảo hiểm, thuế và các khoản khấu trừ.'
            );
        }

        if ($netRow && abs((float) $netRow->amount - $net) > self::TOLERANCE) {
            $issues[] = $this->issue(
                $employee,
                $detail,
                'NET_BREAKDOWN_MISMATCH',
                'Dòng NET không khớp thực lĩnh của salary detail.',
                'Kế toán cần chạy lại engine hoặc sửa dữ liệu nguồn.'
            );
        }

        return $issues;
    }

    private function issue(
        object $employee,
        ?object $detail,
        string $code,
        string $message,
        string $hint
    ): array {
        return [
            'employee_id' => (int) $employee->id,
            'salary_detail_id' => $detail ? (int) $detail->id : null,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'department_name' => $employee->department_name,
            'issue_type' => 'PAYROLL',
            'issue_code' => $code,
            'message' => $message,
            'resolution_hint' => $hint,
        ];
    }

    private function applyClosedPeriodExclusions(SalaryPeriod $period, array $analysis): array
    {
        $meta = is_string($period->meta)
            ? (json_decode($period->meta, true) ?: [])
            : (array) ($period->meta ?? []);
        $audit = is_array($meta['payslip_readiness_audit'] ?? null)
            ? $meta['payslip_readiness_audit']
            : [];

        $snapshot = null;
        foreach (array_reverse($audit) as $entry) {
            if (is_array($entry) && ($entry['phase'] ?? null) === 'CLOSE') {
                $snapshot = $entry;
                break;
            }
        }
        if (! $snapshot && is_array($meta['payslip_readiness'] ?? null)
            && ($meta['payslip_readiness']['phase'] ?? null) === 'CLOSE') {
            $snapshot = $meta['payslip_readiness'];
        }

        $excludedIds = collect($snapshot['excluded_employee_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($excludedIds->isEmpty()) {
            return $analysis;
        }

        $analysis['ready'] = collect($analysis['ready'])
            ->reject(fn (array $row) => $excludedIds->contains((int) $row['employee_id']))
            ->values()
            ->all();

        $issueKeys = collect($analysis['issues'])->mapWithKeys(
            fn (array $issue) => [$issue['employee_id'].'|'.$issue['issue_code'] => true]
        );
        foreach ($snapshot['exclusions'] ?? [] as $exclusion) {
            if (! is_array($exclusion) || ! $excludedIds->contains((int) ($exclusion['employee_id'] ?? 0))) {
                continue;
            }

            $key = ((int) $exclusion['employee_id']).'|'.($exclusion['issue_code'] ?? 'CLOSE_EXCLUSION');
            if ($issueKeys->has($key)) {
                continue;
            }

            $analysis['issues'][] = [
                'employee_id' => (int) $exclusion['employee_id'],
                'salary_detail_id' => isset($exclusion['salary_detail_id'])
                    ? (int) $exclusion['salary_detail_id']
                    : null,
                'employee_code' => $exclusion['employee_code'] ?? null,
                'full_name' => $exclusion['full_name'] ?? null,
                'department_name' => $exclusion['department_name'] ?? null,
                'issue_type' => 'PAYROLL',
                'issue_code' => $exclusion['issue_code'] ?? 'CLOSE_EXCLUSION',
                'message' => $exclusion['message'] ?? 'Nhân viên đã được loại khỏi lần phát hành của kỳ này.',
                'resolution_hint' => $exclusion['resolution_hint'] ?? 'Xử lý bằng điều chỉnh ở kỳ sau.',
            ];
            $issueKeys->put($key, true);
        }

        $analysis['pass_count'] = count($analysis['ready']);
        $analysis['fail_count'] = collect($analysis['issues'])->pluck('employee_id')->unique()->count();
        $analysis['can_publish_partially'] = $analysis['pass_count'] > 0;

        return $analysis;
    }
}

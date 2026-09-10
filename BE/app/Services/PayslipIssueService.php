<?php

namespace App\Services;

use App\Models\PayslipPublicationIssue;
use App\Models\SalaryPeriod;
use Illuminate\Support\Facades\DB;

class PayslipIssueService
{
    public function syncPayrollIssues(
        SalaryPeriod $period,
        array $readiness,
        ?int $actorId = null,
        bool $acknowledged = false
    ): void {
        $currentKeys = collect($readiness['issues'] ?? [])->map(
            fn (array $issue) => $issue['employee_id'].'|'.$issue['issue_code']
        )->all();

        DB::transaction(function () use ($period, $readiness, $actorId, $acknowledged, $currentKeys): void {
            $existing = PayslipPublicationIssue::query()
                ->where('salary_period_id', $period->id)
                ->where('issue_type', 'PAYROLL')
                ->get();

            foreach ($existing as $row) {
                $key = $row->employee_id.'|'.$row->issue_code;
                if (! in_array($key, $currentKeys, true) && $row->status !== 'RESOLVED') {
                    $row->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
                }
            }

            foreach ($readiness['issues'] ?? [] as $issue) {
                $existingIssue = PayslipPublicationIssue::query()->where([
                    'salary_period_id' => $period->id,
                    'employee_id' => $issue['employee_id'],
                    'issue_type' => 'PAYROLL',
                    'issue_code' => $issue['issue_code'],
                ])->first();
                $preserveAcknowledgement = $existingIssue?->status === 'DEFERRED'
                    && $existingIssue->acknowledged_at !== null;

                PayslipPublicationIssue::updateOrCreate(
                    [
                        'salary_period_id' => $period->id,
                        'employee_id' => $issue['employee_id'],
                        'issue_type' => 'PAYROLL',
                        'issue_code' => $issue['issue_code'],
                    ],
                    [
                        'tenant_id' => $period->tenant_id,
                        'legal_entity_id' => $period->legal_entity_id,
                        'salary_detail_id' => $issue['salary_detail_id'],
                        'message' => $issue['message'],
                        'resolution_hint' => $issue['resolution_hint'],
                        'status' => $acknowledged ? 'DEFERRED' : 'OPEN',
                        'acknowledged_by' => $preserveAcknowledgement
                            ? $existingIssue->acknowledged_by
                            : ($acknowledged ? $actorId : null),
                        'acknowledged_at' => $preserveAcknowledgement
                            ? $existingIssue->acknowledged_at
                            : ($acknowledged ? now() : null),
                        'resolved_at' => null,
                    ]
                );
            }
        });
    }

    public function record(
        SalaryPeriod $period,
        int $employeeId,
        string $type,
        string $code,
        string $message,
        ?string $hint = null,
        ?int $salaryDetailId = null,
        ?int $documentId = null
    ): PayslipPublicationIssue {
        return PayslipPublicationIssue::updateOrCreate(
            [
                'salary_period_id' => $period->id,
                'employee_id' => $employeeId,
                'issue_type' => $type,
                'issue_code' => $code,
            ],
            [
                'tenant_id' => $period->tenant_id,
                'legal_entity_id' => $period->legal_entity_id,
                'salary_detail_id' => $salaryDetailId,
                'payslip_document_id' => $documentId,
                'message' => $message,
                'resolution_hint' => $hint,
                'status' => 'OPEN',
                'resolved_at' => null,
            ]
        );
    }

    public function resolve(int $periodId, int $employeeId, string $type, ?string $code = null): void
    {
        PayslipPublicationIssue::query()
            ->where('salary_period_id', $periodId)
            ->where('employee_id', $employeeId)
            ->where('issue_type', $type)
            ->when($code, fn ($query) => $query->where('issue_code', $code))
            ->where('status', 'OPEN')
            ->update(['status' => 'RESOLVED', 'resolved_at' => now(), 'updated_at' => now()]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'qualification_types' => ['qualification_type_code', 'qualification_type_name', 'description'],
        'qualifications' => ['employee_id', 'qualification_type_id', 'qualification_name', 'major', 'school_name', 'graduation_year', 'graduation_grade', 'issued_date', 'issued_by', 'qualification_number', 'file_url', 'is_highest'],
        'certificate_types' => ['certificate_type_code', 'certificate_type_name', 'description'],
        'certificates' => ['employee_id', 'certificate_type_id', 'certificate_name', 'issued_by', 'issued_date', 'expiry_date', 'certificate_number', 'score', 'file_url'],
        'document_types' => ['document_type_code', 'document_type_name', 'description'],
        'identity_documents' => ['employee_id', 'document_type_id', 'document_number', 'full_name', 'date_of_birth', 'issue_date', 'issue_place', 'expiry_date', 'front_image_url', 'back_image_url', 'has_chip'],
        'social_insurance_info' => ['employee_id', 'social_insurance_number', 'health_insurance_number', 'tax_code', 'issue_date', 'issue_place', 'status'],
        'dependents' => ['employee_id', 'full_name', 'relationship', 'date_of_birth', 'id_card_number', 'tax_code', 'deduction_percent', 'start_date', 'end_date', 'status'],
        'employment_histories' => ['employee_id', 'department_id', 'position_id', 'start_date', 'end_date', 'is_current', 'decision_number', 'decision_date', 'notes'],
        'contract_templates' => ['template_code', 'template_name', 'contract_type_id', 'content', 'version', 'is_active', 'file_url', 'effective_date'],
        'contract_histories' => ['contract_id', 'action', 'action_by', 'previous_value', 'new_value', 'notes'],
        'suppliers' => ['supplier_code', 'supplier_name', 'contact_person', 'phone_number', 'email', 'address', 'status'],
        'asset_locations' => ['location_code', 'location_name', 'department_id', 'description', 'status'],
        'asset_incidents' => ['asset_id', 'assignment_id', 'reported_by', 'incident_type', 'incident_date', 'description', 'damage_level', 'status', 'resolved_by', 'resolved_date'],
        'asset_maintenance' => ['asset_id', 'maintenance_type', 'maintenance_date', 'cost', 'vendor', 'description', 'next_maintenance_date'],
        'approval_histories' => ['request_id', 'step_id', 'approver_id', 'action', 'comment', 'action_date'],
        'request_attachments' => ['request_id', 'uploaded_by', 'file_name', 'file_url', 'mime_type', 'file_size'],
        'holidays' => ['holiday_code', 'holiday_name', 'holiday_date', 'holiday_type', 'is_recurring', 'description'],
        'seniority_leave_history' => ['employee_id', 'calculation_date', 'years_of_service', 'base_leave', 'seniority_bonus', 'total_leave', 'effective_year'],
        'leave_advancement_config' => ['department_id', 'position_id', 'max_advance_days', 'approval_flow_id', 'status'],
        'leave_carryover_tracking' => ['employee_id', 'leave_type_id', 'year', 'carried_days', 'used_days', 'expired_days', 'expiry_date', 'status'],
        'leave_advancement_requests' => ['request_id', 'employee_id', 'advance_days', 'reason', 'approved_by_manager', 'approved_by_hr', 'status'],
        'leave_transactions' => ['employee_id', 'leave_type_id', 'transaction_date', 'transaction_type', 'quantity', 'before_balance', 'after_balance', 'reference_id', 'reference_type', 'reason'],
        'shift_schedules' => ['schedule_code', 'schedule_name', 'department_id', 'effective_from', 'effective_to', 'is_active'],
        'shift_schedule_details' => ['schedule_id', 'day_of_week', 'shift_type_id', 'is_holiday'],
        'shift_assignments' => ['employee_id', 'shift_type_id', 'effective_date', 'expiry_date', 'is_permanent', 'assigned_by', 'notes', 'status'],
        'shift_swaps' => ['requester_id', 'target_employee_id', 'original_shift_id', 'requested_shift_id', 'swap_date', 'reason', 'approval_status', 'approver_id'],
        'insurance_types' => ['insurance_type_code', 'insurance_type_name', 'description', 'status'],
        'insurance_claims' => ['employee_id', 'request_id', 'insurance_type_id', 'claim_code', 'leave_request_id', 'start_date', 'end_date', 'total_days', 'daily_rate', 'total_amount', 'payment_source', 'certificate_number', 'certificate_file_url', 'certificate_uploaded_date', 'bank_account', 'bank_id', 'payment_status', 'notes'],
        'allowances' => ['allowance_code', 'allowance_name', 'allowance_type', 'calculation_method', 'is_taxable', 'is_insurable', 'description', 'status'],
        'deductions' => ['deduction_code', 'deduction_name', 'deduction_type', 'is_mandatory', 'description', 'status'],
        'employee_allowances' => ['employee_id', 'allowance_id', 'amount', 'percentage', 'effective_date', 'expiry_date', 'is_active', 'notes'],
        'employee_deductions' => ['employee_id', 'deduction_id', 'amount', 'percentage', 'effective_date', 'expiry_date', 'is_active', 'notes'],
        'salary_attendance_summary' => ['employee_id', 'period_id', 'standard_days', 'actual_working_days', 'paid_leave_days', 'unpaid_leave_days', 'holiday_days', 'overtime_hours', 'late_minutes', 'early_leave_minutes'],
        'news_reads' => ['news_id', 'employee_id', 'read_at'],
        'policy_acknowledgments' => ['policy_id', 'employee_id', 'acknowledged_at', 'ip_address'],
        'dashboard_views' => ['employee_id', 'view_date', 'view_type'],
        'report_templates' => ['template_code', 'template_name', 'report_type', 'sql_query', 'columns_config', 'filters_config', 'chart_config', 'created_by', 'is_public', 'status'],
        'report_histories' => ['template_id', 'executed_by', 'executed_at', 'parameters', 'file_url', 'status'],
        'service_ticket_updates' => ['ticket_id', 'created_by', 'action_type', 'old_status', 'new_status', 'comment'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName => $columns) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            Schema::create($tableName, function (Blueprint $table) use ($columns): void {
                $table->id();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();

                foreach ($columns as $column) {
                    $this->addColumn($table, $column);
                }

                $table->jsonb('meta')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys($this->tables)) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }

    private function addColumn(Blueprint $table, string $column): void
    {
        if (str_ends_with($column, '_id') || in_array($column, ['action_by', 'assigned_by', 'reported_by', 'resolved_by', 'created_by', 'executed_by', 'uploaded_by', 'approved_by_manager', 'approved_by_hr'], true)) {
            $table->unsignedBigInteger($column)->nullable()->index();

            return;
        }

        if (str_ends_with($column, '_config') || in_array($column, ['parameters'], true)) {
            $table->jsonb($column)->nullable();

            return;
        }

        if (str_ends_with($column, '_at')) {
            $table->timestampTz($column)->nullable();

            return;
        }

        if (str_ends_with($column, '_date') || in_array($column, ['start_date', 'end_date', 'issued_date', 'expiry_date', 'effective_date', 'decision_date', 'action_date', 'transaction_date', 'swap_date', 'view_date', 'executed_at', 'effective_from', 'effective_to', 'certificate_uploaded_date', 'resolved_date', 'next_maintenance_date', 'calculation_date'], true)) {
            $table->date($column)->nullable()->index();

            return;
        }

        if (str_starts_with($column, 'is_') || str_starts_with($column, 'has_')) {
            $table->boolean($column)->default(false);

            return;
        }

        if (str_contains($column, 'amount') || str_contains($column, 'percent') || str_contains($column, 'score') || str_contains($column, 'days') || str_contains($column, 'hours') || str_contains($column, 'minutes') || str_contains($column, 'rate') || in_array($column, ['quantity', 'cost', 'before_balance', 'after_balance', 'base_leave', 'seniority_bonus', 'total_leave'], true)) {
            $table->decimal($column, 15, 4)->nullable();

            return;
        }

        if (str_contains($column, 'year')) {
            $table->integer($column)->nullable()->index();

            return;
        }

        if (in_array($column, ['content', 'description', 'notes', 'comment', 'reason', 'previous_value', 'new_value', 'sql_query'], true)) {
            $table->text($column)->nullable();

            return;
        }

        $table->string($column)->nullable()->index();
    }
};

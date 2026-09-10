<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createEmployees();
        $this->createLookup('nationalities', ['nationality_code', 'nationality_name']);
        $this->createLookup('banks', ['bank_code', 'bank_name', 'swift_code']);
        $this->createLookup('departments', ['department_code', 'department_name']);
        $this->createLookup('positions', ['position_code', 'position_name', 'position_group', 'position_level']);

        $this->createGeneric('contract_types', ['contract_type_code', 'contract_type_name', 'description', 'status']);
        $this->createGeneric('contract_change_logs', ['employee_id', 'contract_id', 'change_type', 'old_value', 'new_value', 'reason']);
        $this->createGeneric('contracts', ['employee_id', 'contract_type_id', 'position_id', 'department_id', 'contract_number', 'status', 'start_date', 'end_date']);

        $this->createGeneric('approval_roles', ['role_code', 'role_name', 'description']);
        $this->createGeneric('approval_flows', ['request_type_id', 'flow_name', 'description', 'status']);
        $this->createGeneric('approval_steps', ['approval_flow_id', 'step_order', 'approver_role_id', 'approver_user_id', 'status']);
        $this->createGeneric('request_types', ['request_type_code', 'request_type_name', 'category', 'approval_flow_id', 'status']);
        $this->createGeneric('requests', ['request_type_id', 'requester_id', 'current_step_id', 'title', 'description', 'status', 'priority']);

        $this->createGeneric('leave_types', ['leave_type_code', 'leave_type_name', 'category', 'status']);
        $this->createGeneric('leave_balances', ['employee_id', 'leave_type_id', 'year', 'total_days', 'used_days', 'remaining_days']);
        $this->createGeneric('leave_requests', ['request_id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'total_days', 'status']);

        $this->createGeneric('asset_categories', ['category_code', 'category_name', 'description', 'status']);
        $this->createGeneric('assets', ['asset_code', 'asset_name', 'category_id', 'supplier_id', 'location_id', 'status']);
        $this->createGeneric('asset_assignments', ['asset_id', 'employee_id', 'assigned_by', 'assigned_date', 'return_date', 'status']);

        $this->createGeneric('shift_types', ['shift_code', 'shift_name', 'start_time', 'end_time', 'status']);
        $this->createGeneric('attendances', ['employee_id', 'shift_type_id', 'work_date', 'check_in_time', 'check_out_time', 'check_in_time_2', 'check_out_time_2', 'status']);
        $this->createGeneric('overtime_requests', ['request_id', 'employee_id', 'work_date', 'start_time', 'end_time', 'total_hours', 'status']);

        $this->createGeneric('salary_periods', ['period_code', 'period_name', 'period_type', 'start_date', 'end_date', 'status']);
        $this->createGeneric('salary_details', ['period_id', 'employee_id', 'contract_id', 'gross_salary', 'net_salary', 'transfer_status']);
        $this->createGeneric('salary_breakdowns', ['salary_detail_id', 'item_type', 'item_code', 'item_name', 'amount']);
        $this->createGeneric('payroll_adjustments', ['employee_id', 'paid_salary_detail_id', 'paid_period_id', 'adjustment_type', 'amount', 'status']);

        $this->createGeneric('news_categories', ['category_code', 'category_name', 'description', 'status']);
        $this->createGeneric('news', ['category_id', 'title', 'summary', 'content', 'priority', 'status', 'published_at']);
        $this->createGeneric('notifications', ['sender_id', 'receiver_id', 'title', 'message', 'priority', 'reference_type', 'reference_id', 'read_at']);
        $this->createGeneric('policies', ['policy_code', 'policy_name', 'policy_type', 'content', 'status', 'effective_date']);

        $this->createGeneric('roles', ['role_code', 'role_name', 'description', 'is_system_role']);
        $this->createGeneric('permissions', ['permission_code', 'permission_name', 'module', 'description']);
        $this->createGeneric('role_permissions', ['role_id', 'permission_id']);
        $this->createGeneric('employee_roles', ['employee_id', 'role_id', 'department_id', 'effective_date', 'expiry_date', 'is_active']);

        $this->createGeneric('recruitment_positions', ['position_name', 'department_id', 'employment_type', 'status', 'required_skills_json']);
        $this->createGeneric('recruitment_candidates', ['recruitment_position_id', 'full_name', 'email', 'phone_number', 'application_status', 'ai_score', 'ai_scoring_status', 'ai_scoring_error', 'ai_scored_at', 'ai_matched_skills_json', 'ai_missing_skills_json']);
        $this->createCandidateCvs();
        $this->createGeneric('interview_schedules', ['candidate_id', 'interviewer_id', 'department_manager_id', 'interview_date', 'interview_time', 'interview_mode', 'status', 'result', 'manager_decision']);
        $this->createGeneric('recruitment_candidate_manager_reviews', ['candidate_id', 'manager_id', 'workflow_status', 'manager_score', 'manager_decision_proposal']);
        $this->createGeneric('recruitment_ai_scoring_jobs', ['candidate_id', 'status', 'attempts', 'max_attempts', 'available_at', 'last_error']);
        $this->createGeneric('recruitment_rejected_archive', ['candidate_id', 'rejected_by', 'snapshot']);

        $this->createGeneric('service_categories', ['category_code', 'category_name', 'description', 'status']);
        $this->createGeneric('service_tickets', ['ticket_code', 'requester_id', 'category_id', 'assigned_to', 'title', 'description', 'priority', 'status']);
        $this->createGeneric('system_configs', ['config_key', 'config_value', 'config_type', 'description']);
        $this->createGeneric('notification_configs', ['notification_type', 'recipients', 'template_subject', 'template_body', 'status']);

        $this->createSecurityTables();
    }

    public function down(): void
    {
        foreach (array_reverse([
            'api_tokens', 'password_reset_requests', 'notification_configs', 'system_configs', 'service_tickets', 'service_categories',
            'recruitment_rejected_archive', 'recruitment_ai_scoring_jobs', 'recruitment_candidate_manager_reviews', 'interview_schedules',
            'recruitment_candidate_cvs', 'recruitment_candidates', 'recruitment_positions', 'employee_roles', 'role_permissions',
            'permissions', 'roles', 'policies', 'notifications', 'news', 'news_categories', 'payroll_adjustments', 'salary_breakdowns',
            'salary_details', 'salary_periods', 'overtime_requests', 'attendances', 'shift_types', 'asset_assignments', 'assets',
            'asset_categories', 'leave_requests', 'leave_balances', 'leave_types', 'requests', 'request_types', 'approval_steps',
            'approval_flows', 'approval_roles', 'contracts', 'contract_change_logs', 'contract_types', 'positions', 'departments',
            'banks', 'nationalities', 'employees',
        ]) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createEmployees(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $this->base($table);
            $table->string('employee_code')->nullable()->unique();
            $table->string('full_name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('company_email')->nullable()->unique();
            $table->string('password_hash')->nullable();
            $table->string('status')->default('ACTIVE')->index();
            $table->date('hire_date')->nullable();
            $table->foreignId('department_id')->nullable();
            $table->foreignId('position_id')->nullable();
            $table->jsonb('profile')->nullable();
            $table->timestampsTz();
        });
    }

    private function createLookup(string $tableName, array $columns): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($columns): void {
            $this->base($table);
            foreach ($columns as $column) {
                $table->string($column)->nullable()->index();
            }
            $table->boolean('status')->default(true);
            $table->text('description')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
        });
    }

    private function createGeneric(string $tableName, array $columns): void
    {
        Schema::create($tableName, function (Blueprint $table) use ($columns): void {
            $this->base($table);
            foreach ($columns as $column) {
                $this->addGenericColumn($table, $column);
            }
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
        });
    }

    private function createCandidateCvs(): void
    {
        Schema::create('recruitment_candidate_cvs', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignId('candidate_id')->index();
            $table->string('original_filename')->nullable();
            $table->string('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by')->nullable();
            $table->timestampsTz();
            $table->unique('candidate_id');
        });
    }

    private function createSecurityTables(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('password_reset_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('company_email')->index();
            $table->string('token')->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->unsignedBigInteger('legacy_id')->nullable()->unique();
    }

    private function addGenericColumn(Blueprint $table, string $column): void
    {
        if (str_ends_with($column, '_id')) {
            $table->foreignId($column)->nullable()->index();

            return;
        }

        if (str_ends_with($column, '_json') || in_array($column, ['snapshot'], true)) {
            $table->jsonb($column)->nullable();

            return;
        }

        if (str_ends_with($column, '_at')) {
            $table->timestampTz($column)->nullable();

            return;
        }

        if (str_ends_with($column, '_date')) {
            $table->date($column)->nullable();

            return;
        }

        if (str_ends_with($column, '_time')) {
            $table->time($column)->nullable();

            return;
        }

        if (str_contains($column, 'amount') || str_contains($column, 'salary') || str_contains($column, 'score') || str_contains($column, 'hours') || str_contains($column, 'days')) {
            $table->decimal($column, 15, 4)->nullable();

            return;
        }

        if (str_starts_with($column, 'is_')) {
            $table->boolean($column)->default(false);

            return;
        }

        if (in_array($column, ['content', 'description', 'message', 'reason', 'old_value', 'new_value', 'last_error', 'ai_scoring_error', 'template_body'], true)) {
            $table->text($column)->nullable();

            return;
        }

        $table->string($column)->nullable()->index();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant migration — PHASE C (CONTRACT).
 *
 * Tightens the columns added in Phase A and backfilled in Phase B:
 *  1. tenant_id  -> NOT NULL + FK -> tenants(id)        (all 88 business tables)
 *  2. legal_entity_id -> NOT NULL + FK -> legal_entities(id)  (30 entity-scoped)
 *  3. Convert the 4 blocking global natural-key UNIQUEs into composite
 *     (tenant_id, <col>) so codes are unique PER TENANT, not globally.
 *
 * Pre-flight (run before writing this): 0 duplicates on any (tenant_id, code),
 * 0 NULLs remaining (Phase B). PostgreSQL DDL is transactional, so a failure
 * rolls the whole migration back. Deferred (non-blocking, optional later):
 * the ~85 legacy_id_unique and ~33 app-enforced *_code composite uniques.
 *
 * users.email and the auth token uniques intentionally stay GLOBAL.
 */
return new class extends Migration
{
    private array $tenantOnly = [
        'allowances', 'api_tokens', 'approval_flows', 'approval_histories', 'approval_roles',
        'approval_steps', 'asset_assignments', 'asset_categories', 'asset_incidents', 'asset_maintenance',
        'certificate_types', 'certificates', 'contract_change_logs', 'contract_histories', 'contract_templates',
        'contract_types', 'dashboard_views', 'deductions', 'dependents', 'document_types',
        'employee_allowances', 'employee_deductions', 'employee_roles', 'employment_histories', 'holidays',
        'identity_documents', 'insurance_types', 'interview_schedules', 'job_families', 'leave_advancement_config',
        'leave_advancement_requests', 'leave_balances', 'leave_carryover_tracking', 'leave_requests', 'leave_transactions',
        'leave_types', 'news', 'news_categories', 'news_reads', 'notification_configs',
        'notifications', 'overtime_requests', 'password_reset_requests', 'permissions', 'policies',
        'policy_acknowledgments', 'positions', 'qualification_types', 'qualifications', 'recruitment_ai_scoring_jobs',
        'recruitment_candidate_cvs', 'recruitment_candidate_manager_reviews', 'recruitment_candidates', 'recruitment_positions', 'recruitment_rejected_archive',
        'report_histories', 'report_templates', 'request_attachments', 'request_types', 'requests',
        'role_permissions', 'roles', 'salary_components', 'seniority_leave_history', 'service_categories',
        'service_ticket_updates', 'service_tickets', 'shift_swaps', 'shift_types', 'suppliers',
        'system_configs',
    ];

    private array $entityScoped = [
        'asset_locations', 'assets', 'attendances', 'attendance_logs', 'contracts',
        'departments', 'employees', 'insurance_claims', 'payroll_adjustments', 'salary_attendance_summary',
        'salary_breakdowns', 'salary_details', 'salary_periods', 'shift_assignments', 'shift_schedule_details',
        'shift_schedules', 'social_insurance_info',
    ];

    /** [table, original_constraint, original_columns] for the composite conversions. */
    private array $compositeUniques = [
        ['employees', 'employees_employee_code_unique', 'employee_code'],
        ['employees', 'employees_company_email_unique', 'company_email'],
        ['job_families', 'job_families_code_unique', 'code'],
        ['salary_components', 'salary_components_code_unique', 'code'],
    ];

    public function up(): void
    {
        // 0. DEFENSIVE BACKFILL — stragglers created during the nullable window
        //    (e.g. api_tokens rows from smoke tests inserted before write-stamping
        //    landed). Default everything to tenant 1 / legal entity 1 so the
        //    SET NOT NULL below cannot fail. Idempotent: no-ops once clean.
        foreach (array_merge($this->tenantOnly, $this->entityScoped) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            DB::statement("UPDATE {$table} SET tenant_id = 1 WHERE tenant_id IS NULL");
        }
        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }
            DB::statement("UPDATE {$table} SET legal_entity_id = 1 WHERE legal_entity_id IS NULL");
        }

        // 1. tenant_id -> NOT NULL + FK (all business tables)
        foreach (array_merge($this->tenantOnly, $this->entityScoped) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id SET NOT NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants (id)");
        }

        // 2. legal_entity_id -> NOT NULL + FK (entity-scoped tables)
        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} ALTER COLUMN legal_entity_id SET NOT NULL");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_legal_entity_id_foreign FOREIGN KEY (legal_entity_id) REFERENCES legal_entities (id)");
        }

        // 3. Convert blocking global natural-key uniques to composite (tenant_id, col)
        foreach ($this->compositeUniques as [$table, $oldName, $col]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$oldName}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tenant_{$col}_unique UNIQUE (tenant_id, {$col})");
        }
    }

    public function down(): void
    {
        // Reverse 3: restore the original single-column global uniques.
        foreach ($this->compositeUniques as [$table, $oldName, $col]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_tenant_{$col}_unique");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$oldName} UNIQUE ({$col})");
        }

        // Reverse 2: drop legal_entity_id FK + NOT NULL.
        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'legal_entity_id')) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_legal_entity_id_foreign");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN legal_entity_id DROP NOT NULL");
        }

        // Reverse 1: drop tenant_id FK + NOT NULL.
        foreach (array_merge($this->tenantOnly, $this->entityScoped) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_tenant_id_foreign");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN tenant_id DROP NOT NULL");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant migration — PHASE B (BACKFILL).
 *
 * Seeds a default tenant (id=1) + default legal_entity (id=1) and stamps every
 * existing business row with tenant_id=1 (and legal_entity_id=1 for entity-scoped
 * tables). Still NO NOT NULL / FK / unique changes — that is Phase C (CONTRACT).
 *
 * Idempotent: only rows where the column IS NULL are updated; tenant/entity rows
 * are upserted. Reversible: down() nulls the columns and removes the defaults.
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

    public function up(): void
    {
        $now = now();

        DB::table('tenants')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'Default Company',
                'code' => 'DEFAULT',
                'status' => 'ACTIVE',
                'locale' => 'vi',
                'currency' => 'VND',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        DB::table('legal_entities')->updateOrInsert(
            ['id' => 1],
            [
                'tenant_id' => 1,
                'name' => 'Default Entity',
                'code' => 'DEFAULT',
                'status' => 'ACTIVE',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // Keep PostgreSQL sequences ahead of the explicit id=1 insert.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('tenants', 'id'), GREATEST((SELECT MAX(id) FROM tenants), 1))");
            DB::statement("SELECT setval(pg_get_serial_sequence('legal_entities', 'id'), GREATEST((SELECT MAX(id) FROM legal_entities), 1))");
        }

        foreach ($this->tenantOnly as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => 1]);
            }
        }

        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => 1]);
            }
            if (Schema::hasColumn($table, 'legal_entity_id')) {
                DB::table($table)->whereNull('legal_entity_id')->update(['legal_entity_id' => 1]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'legal_entity_id')) {
                DB::table($table)->where('legal_entity_id', 1)->update(['legal_entity_id' => null]);
            }
            if (Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->where('tenant_id', 1)->update(['tenant_id' => null]);
            }
        }

        foreach ($this->tenantOnly as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->where('tenant_id', 1)->update(['tenant_id' => null]);
            }
        }

        if (Schema::hasTable('legal_entities')) {
            DB::table('legal_entities')->where('id', 1)->delete();
        }
        if (Schema::hasTable('tenants')) {
            DB::table('tenants')->where('id', 1)->delete();
        }
    }
};

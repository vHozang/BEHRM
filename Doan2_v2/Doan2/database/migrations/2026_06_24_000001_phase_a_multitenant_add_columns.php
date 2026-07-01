<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant migration — PHASE A (EXPAND).
 *
 * Creates the `tenants` and `legal_entities` tables and adds NULLABLE
 * `tenant_id` (and `legal_entity_id` for entity-scoped tables) columns to
 * every business table. NO NOT NULL, NO foreign keys, NO unique changes yet
 * — those land in Phase C (CONTRACT) after the Phase B backfill.
 *
 * Source of truth: D:\HRM-System-2\MT_tenant_classification.md
 * - 71 TENANT-only tables          -> tenant_id
 * - 17 TENANT+ENTITY tables        -> tenant_id + legal_entity_id
 *   (attendance_logs is RANGE-partitioned; one ALTER on the parent cascades
 *    to all 13 partition children, so children are NOT listed here.)
 * - 11 GLOBAL / PLATFORM-GLOBAL    -> left untouched.
 *
 * Idempotent & reversible: guarded by hasTable()/hasColumn().
 */
return new class extends Migration
{
    /** Tables that receive tenant_id only. */
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

    /** Tables that receive tenant_id + legal_entity_id. */
    private array $entityScoped = [
        'asset_locations', 'assets', 'attendances', 'attendance_logs', 'contracts',
        'departments', 'employees', 'insurance_claims', 'payroll_adjustments', 'salary_attendance_summary',
        'salary_breakdowns', 'salary_details', 'salary_periods', 'shift_assignments', 'shift_schedule_details',
        'shift_schedules', 'social_insurance_info',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('name');
                $t->string('code')->nullable();
                $t->string('status')->default('ACTIVE');
                $t->string('locale')->default('vi');
                $t->string('currency', 8)->default('VND');
                $t->string('timezone')->default('Asia/Ho_Chi_Minh');
                $t->jsonb('meta')->nullable();
                $t->timestamps();
                $t->unique('code');
            });
        }

        if (! Schema::hasTable('legal_entities')) {
            Schema::create('legal_entities', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('tenant_id');
                $t->string('name');
                $t->string('code')->nullable();
                $t->string('tax_code')->nullable();
                $t->string('address')->nullable();
                $t->string('status')->default('ACTIVE');
                $t->jsonb('meta')->nullable();
                $t->timestamps();
                $t->index('tenant_id');
                $t->unique(['tenant_id', 'code']);
            });
        }

        foreach ($this->tenantOnly as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->unsignedBigInteger('tenant_id')->nullable();
                    $t->index('tenant_id');
                });
            }
        }

        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // attendance_logs is a partitioned parent: add the column (it cascades
            // to children) but skip the parent index to avoid per-partition index
            // churn during EXPAND — indexes are added in the CONTRACT phase.
            $isPartitioned = $table === 'attendance_logs';

            if (! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t) use ($isPartitioned): void {
                    $t->unsignedBigInteger('tenant_id')->nullable();
                    if (! $isPartitioned) {
                        $t->index('tenant_id');
                    }
                });
            }

            if (! Schema::hasColumn($table, 'legal_entity_id')) {
                Schema::table($table, function (Blueprint $t) use ($isPartitioned): void {
                    $t->unsignedBigInteger('legal_entity_id')->nullable();
                    if (! $isPartitioned) {
                        $t->index('legal_entity_id');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->entityScoped as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['legal_entity_id', 'tenant_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $t) use ($column): void {
                        $t->dropColumn($column);
                    });
                }
            }
        }

        foreach ($this->tenantOnly as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropColumn('tenant_id');
                });
            }
        }

        Schema::dropIfExists('legal_entities');
        Schema::dropIfExists('tenants');
    }
};

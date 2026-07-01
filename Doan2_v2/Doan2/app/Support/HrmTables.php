<?php

namespace App\Support;

class HrmTables
{
    /**
     * Route resource names mapped to PostgreSQL table names.
     *
     * CHỈ chứa các bảng NHÓM A (danh mục / lookup / config) — xử lý qua GenericResourceController.
     *
     * Các bảng NHÓM B (nghiệp vụ cốt lõi) đã có controller riêng:
     * - employees         → EmployeeController
     * - contracts          → ContractController
     * - leave-requests     → LeaveController
     * - attendances        → AttendanceController
     * - overtime-requests  → AttendanceController
     * - salary-periods     → PayrollController
     * - salary-details     → PayrollController
     * - recruitment-*      → RecruitmentController
     * - interviews         → RecruitmentController
     * - requests           → RequestApprovalController
     */
    public static function resourceMap(): array
    {
        return [
            // ─── Tài sản ─────────────────────────────────────
            'asset-assignments' => 'asset_assignments',
            'asset-categories' => 'asset_categories',
            'asset-incidents' => 'asset_incidents',
            'asset-locations' => 'asset_locations',
            'asset-maintenance' => 'asset_maintenance',
            'assets' => 'assets',

            // ─── Phụ cấp / Khấu trừ ─────────────────────────
            'allowances' => 'allowances',
            'deductions' => 'deductions',
            'employee-allowances' => 'employee_allowances',
            'employee-deductions' => 'employee_deductions',

            // ─── Phê duyệt (cấu hình) ───────────────────────
            'approval-flows' => 'approval_flows',
            'approval-histories' => 'approval_histories',
            'approval-roles' => 'approval_roles',
            'approval-steps' => 'approval_steps',

            // ─── Hồ sơ NV chi tiết ──────────────────────────
            'certificate-types' => 'certificate_types',
            'certificates' => 'certificates',
            'dependents' => 'dependents',
            'document-types' => 'document_types',
            'employment-histories' => 'employment_histories',
            'identity-documents' => 'identity_documents',
            'qualification-types' => 'qualification_types',
            'qualifications' => 'qualifications',
            'social-insurance-info' => 'social_insurance_info',

            // ─── Hợp đồng (phụ / log) ───────────────────────
            'contract-change-logs' => 'contract_change_logs',
            'contract-histories' => 'contract_histories',
            'contract-templates' => 'contract_templates',
            'contract-types' => 'contract_types',

            // ─── Truyền thông ────────────────────────────────
            'dashboard-views' => 'dashboard_views',
            'news' => 'news',
            'news-categories' => 'news_categories',
            'news-reads' => 'news_reads',
            'notifications' => 'notifications',
            'notification-configs' => 'notification_configs',
            'policies' => 'policies',
            'policy-acknowledgments' => 'policy_acknowledgments',

            // ─── Nghỉ phép (cấu hình / phụ) ─────────────────
            'holidays' => 'holidays',
            'leave-advancement-config' => 'leave_advancement_config',
            'leave-advancement-requests' => 'leave_advancement_requests',
            'leave-balances' => 'leave_balances',
            'leave-carryover-tracking' => 'leave_carryover_tracking',
            'leave-transactions' => 'leave_transactions',
            'leave-types' => 'leave_types',
            'seniority-leave-history' => 'seniority_leave_history',

            // ─── Lương (phụ) ─────────────────────────────────
            'payroll-adjustments' => 'payroll_adjustments',
            'salary-attendance-summary' => 'salary_attendance_summary',
            'salary-breakdowns' => 'salary_breakdowns',
            'salary-components' => 'salary_components',

            // ─── Bảo hiểm ───────────────────────────────────
            'insurance-claims' => 'insurance_claims',
            'insurance-types' => 'insurance_types',

            // ─── Tuyển dụng (phụ) ────────────────────────────
            'recruitment-positions' => 'recruitment_positions',
            'recruitment-posts' => 'recruitment_posts',

            // ─── IAM ─────────────────────────────────────────
            'employee-roles' => 'employee_roles',
            'permissions' => 'permissions',
            'role-permissions' => 'role_permissions',
            'roles' => 'roles',
            'users' => 'users',

            // ─── Yêu cầu (phụ) ──────────────────────────────
            'request-attachments' => 'request_attachments',
            'request-types' => 'request_types',

            // ─── Báo cáo / Cấu hình ─────────────────────────
            'job-families' => 'job_families',
            'report-histories' => 'report_histories',
            'report-templates' => 'report_templates',
            'settings' => 'system_configs',

            // ─── Dịch vụ nội bộ ──────────────────────────────
            'service-categories' => 'service_categories',
            'service-tickets' => 'service_tickets',
            'service-ticket-updates' => 'service_ticket_updates',

            // ─── Chấm công (cấu hình / phụ) ─────────────────
            'shift-assignments' => 'shift_assignments',
            'shift-schedule-details' => 'shift_schedule_details',
            'shift-schedules' => 'shift_schedules',
            'shift-swaps' => 'shift_swaps',
            'shift-types' => 'shift_types',

            // ─── Nhân sự (danh mục) ─────────────────────────
            'banks' => 'banks',
            'departments' => 'departments',
            'nationalities' => 'nationalities',
            'positions' => 'positions',
            'suppliers' => 'suppliers',
        ];
    }

    public static function tableFor(string $resource): ?string
    {
        return self::resourceMap()[$resource] ?? null;
    }
}

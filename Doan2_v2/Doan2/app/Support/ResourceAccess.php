<?php

namespace App\Support;

use Illuminate\Http\Request;

class ResourceAccess
{
    private const HIDDEN_RESOURCES = [
        'approval-flows',
        'approval-histories',
        'approval-roles',
        'approval-steps',
        'certificates',
        'contract-histories',
        'contract-templates',
        'dashboard-views',
        'insurance-claims',
        'leave-advancement-requests',
        'leave-balances',
        'news-reads',
        'notification-configs',
        'notifications',
        'payroll-adjustments',
        'policy-acknowledgments',
        'report-histories',
        'report-templates',
        'request-attachments',
        'request-types',
        'service-ticket-updates',
        'service-tickets',
        'shift-schedule-details',
        'shift-schedules',
        'users',
    ];

    private const READ_ONLY_RESOURCES = [
        'certificate-types',
        'contract-change-logs',
        'leave-carryover-tracking',
        'leave-transactions',
        'salary-attendance-summary',
        'salary-breakdowns',
        'seniority-leave-history',
        'shift-swaps',
    ];

    private const CREATE_ONLY_RESOURCES = [
        'employment-histories',
    ];

    /** Generic writes that already have a dedicated policy in the controller. */
    private const SPECIAL_WRITE_RESOURCES = [
        'settings',
        'shift-assignments',
        'shift-types',
    ];

    private const WRITE_CAPABILITY = [
        'allowances' => 'payroll.inputs.manage',
        'deductions' => 'payroll.inputs.manage',
        'employee-allowances' => 'payroll.inputs.manage',
        'employee-deductions' => 'payroll.inputs.manage',
        'salary-components' => 'payroll.inputs.manage',
        'insurance-types' => 'payroll.inputs.manage',
        'asset-assignments' => 'assets.manage',
        'asset-categories' => 'assets.manage',
        'asset-incidents' => 'assets.manage',
        'asset-locations' => 'assets.manage',
        'asset-maintenance' => 'assets.manage',
        'assets' => 'assets.manage',
        'certificate-types' => 'employee.records.manage',
        'contract-types' => 'employee.records.manage',
        'departments' => 'employee.records.manage',
        'dependents' => 'employee.records.manage',
        'document-types' => 'employee.records.manage',
        'employee-roles' => 'roles.permissions.manage',
        'employment-histories' => 'employee.records.manage',
        'identity-documents' => 'employee.records.manage',
        'qualification-types' => 'employee.records.manage',
        'qualifications' => 'employee.records.manage',
        'social-insurance-info' => 'employee.records.manage',
        'leave-advancement-config' => 'leave.manage',
        'leave-types' => 'leave.manage',
        'holidays' => 'leave.manage',
        'news' => 'communications.manage',
        'news-categories' => 'communications.manage',
        'policies' => 'communications.manage',
        'service-categories' => 'communications.manage',
        'approval-flows' => 'requests.flows.manage',
        'approval-steps' => 'requests.flows.manage',
        'request-types' => 'requests.types.manage',
        'report-templates' => 'reports.templates.manage',
        'banks' => 'settings.catalogs.manage',
        'nationalities' => 'settings.catalogs.manage',
        'job-families' => 'settings.catalogs.manage',
        'positions' => 'settings.catalogs.manage',
        'suppliers' => 'assets.manage',
        'permissions' => 'roles.permissions.manage',
        'role-permissions' => 'roles.permissions.manage',
        'roles' => 'roles.permissions.manage',
        'recruitment-positions' => 'recruitment.positions.manage',
    ];

    private const READ_CAPABILITY = [
        'contract-change-logs' => 'employee.records.manage',
        'leave-carryover-tracking' => 'leave.manage',
        'leave-transactions' => 'leave.manage',
        'permissions' => 'roles.permissions.manage',
        'role-permissions' => 'roles.permissions.manage',
        'salary-attendance-summary' => 'payroll.amounts.view',
        'salary-breakdowns' => 'payroll.amounts.view',
        'seniority-leave-history' => 'leave.manage',
    ];

    public static function assertAllowed(Request $request, string $resource, string $action): void
    {
        if (in_array($resource, self::HIDDEN_RESOURCES, true)) {
            abort(404, 'Resource not found');
        }

        if ($action !== 'read' && in_array($resource, self::READ_ONLY_RESOURCES, true)) {
            abort(405, 'Resource is read-only');
        }

        if ($action !== 'read'
            && in_array($resource, self::CREATE_ONLY_RESOURCES, true)
            && strtoupper($request->method()) !== 'POST') {
            abort(405, 'Resource only supports append operations');
        }

        $required = $action === 'read'
            ? (self::READ_CAPABILITY[$resource] ?? null)
            : (self::WRITE_CAPABILITY[$resource] ?? null);

        if ($required === null && $action === 'read') {
            return;
        }

        if ($required === null && in_array($resource, self::SPECIAL_WRITE_RESOURCES, true)) {
            return;
        }

        abort_if($required === null, 403, 'Resource action is not allowed');

        $access = (array) $request->attributes->get('access', []);
        abort_unless(AccessControl::accessHasCapability($access, $required), 403, 'Bạn không có quyền thực hiện thao tác này');
    }
}

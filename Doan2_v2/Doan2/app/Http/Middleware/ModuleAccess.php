<?php

namespace App\Http\Middleware;

use App\Support\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces role/module-based access on the authenticated API. Runs AFTER
 * hrm.auth (which populates auth_employee). Super-admins and full-admin roles
 * pass everything; otherwise the request's path must map to a module the
 * employee's roles grant (see App\Support\AccessControl). Unmapped paths and
 * shared lookup reads are always allowed, so this never blocks core flows.
 */
class ModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->attributes->get('auth_employee');

        // No auth context (shouldn't happen behind hrm.auth) → let it through;
        // hrm.auth already rejects unauthenticated requests.
        if (! is_array($employee) || empty($employee['id'])) {
            return $next($request);
        }

        $access = AccessControl::forEmployee((int) $employee['id'], ! empty($employee['is_super_admin']));
        $request->attributes->set('access', $access);

        $path = preg_replace('#^api/v1/?#', '', $request->path());

        // Self-service: an employee may always reach their OWN personal data
        // (attendance, leave, payslip, OT...) even without the admin module —
        // but only when the request is explicitly scoped to themselves. This
        // keeps the Employee Portal working without exposing others' rows.
        if ($this->selfServiceAllowed($request, (int) $employee['id'], (string) $path)) {
            return $next($request);
        }

        if (! AccessControl::allows($access, $request->method(), (string) $path)) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn không có quyền truy cập chức năng này',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }

    /**
     * Personal-data endpoints an employee may use when the request targets their
     * own employee_id (query or body). Anything broader falls through to the
     * normal module check.
     */
    private const SELF_SERVICE = [
        'attendances', 'leave-requests', 'leave-balances', 'leave-transactions',
        'salary-details', 'overtime-requests', 'attendance-adjustments', 'shift-swaps',
        'shift-assignments',
    ];

    private function selfServiceAllowed(Request $request, int $employeeId, string $path): bool
    {
        $segment = strtolower(explode('/', ltrim($path, '/'))[0] ?? '');

        // Onboarding/offboarding: employees may READ their own checklists and
        // TICK their own tasks. The controller enforces row ownership for
        // non-managers; create/add-task/delete/cancel still need the hr module.
        if ($segment === 'onboarding-checklists') {
            $method = strtoupper($request->method());
            if ($method === 'GET') {
                return true;
            }
            if ($method === 'PATCH' && str_contains($path, '/tasks/')) {
                return true;
            }

            return false;
        }

        // Profile change requests (đơn đổi thông tin): employees may read the
        // requestable-field catalog, list/view their OWN requests, file a new
        // request scoped to themselves, and cancel their own. approve/reject are
        // management actions and need the hr module. The controller additionally
        // self-scopes list/detail and enforces requester ownership on cancel.
        if ($segment === 'profile-change-requests') {
            $method = strtoupper($request->method());
            if (str_contains($path, '/approve') || str_contains($path, '/reject')) {
                return false;
            }
            if ($method === 'GET') {
                return true;
            }
            if (str_contains($path, '/cancel')) {
                return true;
            }
            $target = $request->input('employee_id', $request->query('employee_id'));

            return $target !== null && (int) $target === $employeeId;
        }

        // Contracts: a regular employee may VIEW/RENDER and SIGN their OWN
        // contract (and request its OTP), but not create/edit/activate/terminate
        // or send-for-sign (those need the hr module). Ownership is resolved from
        // the contract row; list is allowed only when scoped to self.
        if ($segment === 'contracts') {
            $parts = explode('/', ltrim($path, '/'));
            $idPart = $parts[1] ?? '';
            $action = $parts[2] ?? '';

            if (is_numeric($idPart)) {
                $ownerId = (int) \Illuminate\Support\Facades\DB::table('contracts')->where('id', (int) $idPart)->value('employee_id');
                if ($ownerId === $employeeId && in_array($action, ['', 'render', 'request-otp', 'sign'], true)) {
                    return true;
                }

                return false;
            }

            // GET /contracts?employee_id=self — list own contracts.
            $target = $request->input('employee_id', $request->query('employee_id'));

            return $target !== null && (int) $target === $employeeId;
        }

        // Cancel-by-id của đơn tự phục vụ (nghỉ phép / điều chỉnh công): route
        // /{id}/cancel KHÔNG mang employee_id nên kiểm tra self-service tổng quát
        // bên dưới sẽ chặn nhầm nhân viên huỷ đơn CỦA CHÍNH MÌNH. Phân giải chủ
        // sở hữu từ bản ghi (controller vẫn kiểm tra lại danh tính người huỷ).
        if (in_array($segment, ['leave-requests', 'attendance-adjustments'], true)
            && str_contains($path, '/cancel')) {
            $parts = explode('/', ltrim($path, '/'));
            $rid = $parts[1] ?? '';
            if (is_numeric($rid)) {
                $table = $segment === 'leave-requests' ? 'leave_requests' : 'attendance_adjustment_requests';
                $ownerId = (int) \Illuminate\Support\Facades\DB::table($table)
                    ->where('id', (int) $rid)->value('employee_id');

                return $ownerId === $employeeId;
            }
        }

        // Shift coverage offers (lời mời phủ ca): an employee may LIST offers
        // addressed to them (employee_id=self) and ACCEPT/DECLINE an offer that
        // belongs to them. Creating coverage requests/offers is a manager action
        // (needs the time module).
        if ($segment === 'shift-coverage-offers') {
            $method = strtoupper($request->method());
            if ($method === 'GET') {
                $target = $request->input('employee_id', $request->query('employee_id'));

                return $target !== null && (int) $target === $employeeId;
            }
            if (str_contains($path, '/respond')) {
                $parts = explode('/', ltrim($path, '/'));
                $offerId = $parts[1] ?? '';
                if (is_numeric($offerId)) {
                    $ownerId = (int) \Illuminate\Support\Facades\DB::table('shift_coverage_offers')
                        ->where('id', (int) $offerId)->value('employee_id');

                    return $ownerId === $employeeId;
                }
            }

            return false;
        }

        // Shift swaps (đổi ca với đồng nghiệp): an employee may LIST their own
        // swaps (as requester or target) and FILE a new swap where they are the
        // requester. Approve/reject are management actions (need the time module).
        // The swap table keys on requester_id/target_employee_id (not employee_id),
        // so the generic self-service check below would wrongly block them.
        if ($segment === 'shift-swaps') {
            $method = strtoupper($request->method());
            if (str_contains($path, '/approve') || str_contains($path, '/reject')) {
                return false;
            }
            if ($method === 'GET') {
                $req = $request->input('requester_id', $request->query('requester_id'));
                $tgt = $request->input('target_employee_id', $request->query('target_employee_id'));

                return ((int) $req === $employeeId) || ((int) $tgt === $employeeId);
            }
            if ($method === 'POST') {
                $req = $request->input('requester_id', $request->query('requester_id'));

                return $req !== null && (int) $req === $employeeId;
            }

            return false;
        }

        if (! in_array($segment, self::SELF_SERVICE, true)) {
            return false;
        }

        $target = $request->input('employee_id', $request->query('employee_id'));

        return $target !== null && (int) $target === $employeeId;
    }
}

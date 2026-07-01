<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class HrmAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'status' => 401,
                'message' => 'Unauthenticated',
                'data' => null,
            ], 401);
        }

        $row = DB::table('api_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $row) {
            return response()->json([
                'status' => 401,
                'message' => 'Invalid or expired token',
                'data' => null,
            ], 401);
        }

        $employee = DB::table('employees')->where('id', $row->employee_id)->first();

        if (! $employee) {
            return response()->json([
                'status' => 401,
                'message' => 'Authenticated employee not found',
                'data' => null,
            ], 401);
        }

        $request->attributes->set('auth_employee', (array) $employee);
        $request->attributes->set('auth_employee_id', $employee->id);

        $isSuperAdmin = ! empty($employee->is_super_admin);

        // Impersonation: a super-admin may act inside any tenant by sending a
        // numeric X-Tenant-Id header. Non-super-admins NEVER get to spoof a
        // tenant — the header is ignored and their own tenant is enforced.
        $impersonatedTenantId = null;
        if ($isSuperAdmin) {
            $header = $request->header('X-Tenant-Id');
            if ($header !== null && is_numeric($header)) {
                $impersonatedTenantId = (int) $header;
            }
        }

        if ($impersonatedTenantId !== null) {
            // Resolve the chosen tenant's default (lowest-id) legal entity, if any.
            $legalEntityId = DB::table('legal_entities')
                ->where('tenant_id', $impersonatedTenantId)
                ->orderBy('id')
                ->value('id');

            TenantContext::set(
                $impersonatedTenantId,
                $legalEntityId !== null ? (int) $legalEntityId : null
            );
        } else {
            TenantContext::set(
                (int) $employee->tenant_id,
                $employee->legal_entity_id !== null ? (int) $employee->legal_entity_id : null
            );
        }

        return $next($request);
    }
}

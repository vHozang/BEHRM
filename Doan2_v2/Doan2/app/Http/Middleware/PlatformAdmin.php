<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to platform super-admins (cross-tenant operators).
 *
 * MUST run AFTER `hrm.auth`, which populates the `auth_employee` request
 * attribute. Any employee whose `is_super_admin` flag is falsy gets a 403.
 */
class PlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->attributes->get('auth_employee');

        if (! is_array($employee) || empty($employee['is_super_admin'])) {
            return response()->json([
                'status' => 403,
                'message' => 'Forbidden — super admin only',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}

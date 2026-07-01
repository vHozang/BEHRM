<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform (cross-tenant) super-admin endpoints. Guarded by the
 * 'platform.admin' middleware which runs AFTER 'hrm.auth'.
 *
 * All reads here are deliberately cross-tenant: they use raw DB::table()
 * queries (which carry no tenant scope) rather than tenant-scoped Eloquent.
 */
class PlatformController extends Controller
{
    /**
     * List all tenants across the platform, with entity/employee counts.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $paginator = DB::table('tenants')
            ->select('tenants.*')
            ->selectSub(
                DB::table('legal_entities')->selectRaw('count(*)')->whereColumn('legal_entities.tenant_id', 'tenants.id'),
                'legal_entities_count'
            )
            ->selectSub(
                DB::table('employees')->selectRaw('count(*)')->whereColumn('employees.tenant_id', 'tenants.id'),
                'employees_count'
            )
            ->orderBy('tenants.id')
            ->paginate($perPage);

        return response()->json([
            'status' => 200,
            'message' => 'Tenants',
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Provision a new tenant + its first admin.
     */
    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'tenant.name' => ['required', 'string', 'max:255'],
            'tenant.code' => ['nullable', 'string', 'max:255', Rule::unique('tenants', 'code')],
            'admin.full_name' => ['required', 'string', 'max:255'],
            'admin.company_email' => ['required', 'email', 'max:255'],
            'admin.password' => ['required', 'string', 'min:6'],
        ]);

        // Admin email must be unique within the (new) tenant — and since a new
        // tenant has no employees yet, a platform-wide check on the email is a
        // safe, friendly guard against typos / re-provisioning.
        $emailTaken = DB::table('employees')
            ->where('company_email', $data['admin']['company_email'])
            ->exists();

        if ($emailTaken) {
            return response()->json([
                'status' => 422,
                'message' => 'The admin company email is already in use.',
                'data' => null,
            ], 422);
        }

        $result = $provisioner->provision($data);

        return response()->json([
            'status' => 201,
            'message' => 'Tenant provisioned',
            'data' => $result,
        ], 201);
    }

    /**
     * Tenant detail + its legal entities + counts (cross-tenant read).
     */
    public function show(int $id): JsonResponse
    {
        $tenant = DB::table('tenants')->where('id', $id)->first();

        if (! $tenant) {
            return response()->json([
                'status' => 404,
                'message' => 'Tenant not found',
                'data' => null,
            ], 404);
        }

        $legalEntities = DB::table('legal_entities')
            ->where('tenant_id', $id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => 200,
            'message' => 'Tenant detail',
            'data' => [
                'tenant' => $tenant,
                'legal_entities' => $legalEntities,
                'counts' => [
                    'legal_entities' => $legalEntities->count(),
                    'employees' => DB::table('employees')->where('tenant_id', $id)->count(),
                    'departments' => DB::table('departments')->where('tenant_id', $id)->count(),
                ],
            ],
        ]);
    }
}

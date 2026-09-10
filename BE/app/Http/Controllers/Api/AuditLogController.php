<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = DB::table('audit_logs')
            ->leftJoin('employees', 'employees.id', '=', 'audit_logs.actor_employee_id')
            ->where('audit_logs.tenant_id', TenantContext::id());

        if ($request->filled('table_name')) {
            $query->where('audit_logs.table_name', $request->query('table_name'));
        }

        if ($request->filled('record_id')) {
            $query->where('audit_logs.record_id', (int) $request->query('record_id'));
        }

        if ($request->filled('actor_employee_id')) {
            $query->where('audit_logs.actor_employee_id', (int) $request->query('actor_employee_id'));
        }

        if ($request->filled('action')) {
            $query->where('audit_logs.action', $request->query('action'));
        }

        $page = $query
            ->orderByDesc('audit_logs.created_at')
            ->paginate($perPage, [
                'audit_logs.id',
                'audit_logs.action',
                'audit_logs.table_name',
                'audit_logs.record_id',
                'audit_logs.actor_employee_id',
                'audit_logs.changes',
                'audit_logs.ip_address',
                'audit_logs.created_at',
                'employees.full_name as actor_name',
            ]);

        $items = array_map(function ($row) {
            if (isset($row->changes) && is_string($row->changes)) {
                $row->changes = json_decode($row->changes, true);
            }

            return $row;
        }, $page->items());

        return response()->json([
            'status' => 200,
            'message' => 'Audit logs list',
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ],
        ]);
    }
}

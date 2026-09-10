<?php

namespace App\Services;

use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftRosterAccess
{
    public function actorId(Request $request): int
    {
        return (int) $request->attributes->get('auth_employee_id');
    }

    public function canManageAll(Request $request): bool
    {
        $access = (array) $request->attributes->get('access', []);
        if (! empty($access['full'])) {
            return true;
        }

        $codes = array_map(
            fn ($role) => strtoupper((string) ($role['role_code'] ?? '')),
            $access['roles'] ?? []
        );

        return count(array_intersect(['ADMIN', 'TENANT_ADMIN', 'HR'], $codes)) > 0
            || AccessControl::hasAnyRole($this->actorId($request), ['ADMIN', 'TENANT_ADMIN', 'HR']);
    }

    public function canManageShiftTypes(Request $request): bool
    {
        return $this->canManageAll($request);
    }

    /** @return array<int, object> */
    public function accessibleDepartments(Request $request): array
    {
        $tenantId = TenantContext::id();
        $rows = DB::table('departments')
            ->where('tenant_id', $tenantId)
            ->orderBy('department_code')
            ->get(['id', 'department_code', 'department_name', 'status', 'meta'])
            ->filter(fn ($department): bool => $this->isActive($department->status ?? true))
            ->values();

        if ($this->canManageAll($request)) {
            return $rows->all();
        }

        $actorId = $this->actorId($request);

        return $rows->filter(function ($department) use ($actorId): bool {
            $meta = $this->decodeMeta($department->meta ?? null);

            return (int) ($meta['manager_id'] ?? 0) === $actorId;
        })->values()->all();
    }

    /** @return array<int, int> */
    public function accessibleDepartmentIds(Request $request): array
    {
        return array_map(fn ($department) => (int) $department->id, $this->accessibleDepartments($request));
    }

    public function department(Request $request, int $departmentId): object
    {
        foreach ($this->accessibleDepartments($request) as $department) {
            if ((int) $department->id === $departmentId) {
                return $department;
            }
        }

        abort(403, 'Bạn không có quyền xếp ca cho phòng ban này');
    }

    public function canManageEmployee(Request $request, int $employeeId): bool
    {
        $employee = DB::table('employees')
            ->where('id', $employeeId)
            ->where('tenant_id', TenantContext::id())
            ->first(['id', 'department_id']);

        if (! $employee) {
            return false;
        }

        return $this->canManageAll($request)
            || in_array((int) $employee->department_id, $this->accessibleDepartmentIds($request), true);
    }

    public function assertEmployee(Request $request, int $employeeId): void
    {
        if (! $this->canManageEmployee($request, $employeeId)) {
            abort(403, 'Bạn không có quyền sửa lịch của nhân viên này');
        }
    }

    public function assertAssignment(Request $request, int $assignmentId): object
    {
        $assignment = DB::table('shift_assignments')
            ->where('id', $assignmentId)
            ->where('tenant_id', TenantContext::id())
            ->first();

        if (! $assignment) {
            abort(404, 'Không tìm thấy phân ca');
        }

        $this->assertEmployee($request, (int) $assignment->employee_id);

        return $assignment;
    }

    public function scopeAssignments(Builder $query, Request $request, string $table = 'shift_assignments'): Builder
    {
        if ($this->canManageAll($request)) {
            return $query;
        }

        $actorId = $this->actorId($request);
        $requestedEmployeeId = (int) $request->query('employee_id', $request->input('employee_id', 0));

        // Portal employees may only read their own schedule.
        if ($requestedEmployeeId === $actorId && $request->isMethod('GET')) {
            return $query->where($table.'.employee_id', $actorId);
        }

        $departmentIds = $this->accessibleDepartmentIds($request);
        if ($departmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($table.'.employee_id', function ($employees) use ($departmentIds): void {
            $employees->select('id')->from('employees')
                ->where('tenant_id', TenantContext::id())
                ->whereIn('department_id', $departmentIds);
        });
    }

    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_object($meta)) {
            return (array) $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function isActive(mixed $status): bool
    {
        return ! in_array($status, [false, 0, '0', 'false', 'FALSE', 'INACTIVE'], true);
    }
}

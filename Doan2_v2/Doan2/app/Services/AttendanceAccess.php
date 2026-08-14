<?php

namespace App\Services;

use App\Models\Attendance;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceAccess
{
    public function actorId(Request $request): int
    {
        return (int) $request->attributes->get('auth_employee_id');
    }

    /** @return array<int, string> */
    public function roleCodes(Request $request): array
    {
        $access = (array) $request->attributes->get('access', []);

        return array_values(array_unique(array_map(
            fn (array $role): string => strtoupper((string) ($role['role_code'] ?? '')),
            array_filter($access['roles'] ?? [], 'is_array'),
        )));
    }

    public function isAdmin(Request $request): bool
    {
        $access = (array) $request->attributes->get('access', []);

        return ! empty($access['full'])
            || count(array_intersect(['ADMIN', 'TENANT_ADMIN'], $this->roleCodes($request))) > 0;
    }

    public function isHr(Request $request): bool
    {
        return in_array('HR', $this->roleCodes($request), true);
    }

    public function isAccountant(Request $request): bool
    {
        return in_array('ACCOUNTANT', $this->roleCodes($request), true);
    }

    public function isDepartmentManager(Request $request): bool
    {
        return count(array_intersect(['MANAGER', 'DEPT_HEAD'], $this->roleCodes($request))) > 0;
    }

    public function canReadOrganization(Request $request): bool
    {
        return $this->isAdmin($request) || $this->isHr($request) || $this->isDepartmentManager($request);
    }

    public function canModifyAttendance(Request $request): bool
    {
        return $this->isAdmin($request) || $this->isHr($request);
    }

    public function canRunRecompute(Request $request): bool
    {
        return $this->canModifyAttendance($request);
    }

    public function canRunSummary(Request $request): bool
    {
        return $this->isAdmin($request) || $this->isAccountant($request);
    }

    public function requestedLegalEntity(Request $request, bool $fromBody = false, bool $requireOne = false): ?int
    {
        if (! $this->isAdmin($request)) {
            return (int) TenantContext::legalEntityId();
        }

        $value = $fromBody ? $request->input('legal_entity_id') : $request->query('legal_entity_id');
        if ($value === null || $value === '') {
            return $requireOne ? (int) TenantContext::legalEntityId() : null;
        }

        $id = (int) $value;

        return TenantContext::ownsRow('legal_entities', $id)
            ? $id
            : ($requireOne ? (int) TenantContext::legalEntityId() : null);
    }

    /** @return array<int, int> */
    public function managedDepartmentIds(Request $request): array
    {
        if (! $this->isDepartmentManager($request)) {
            return [];
        }

        $actorId = $this->actorId($request);
        $tenantId = (int) TenantContext::id();
        $entityId = (int) TenantContext::legalEntityId();
        $ids = DB::table('departments')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->get(['id', 'meta'])
            ->filter(function (object $department) use ($actorId): bool {
                $meta = $this->decodeMeta($department->meta ?? null);

                return (int) ($meta['manager_id'] ?? $meta['head_employee_id'] ?? 0) === $actorId;
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $roleDepartmentIds = DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.tenant_id', $tenantId)
            ->whereColumn('r.tenant_id', 'er.tenant_id')
            ->where('er.employee_id', $actorId)
            ->whereRaw('er.is_active IS TRUE')
            ->whereIn('r.role_code', ['MANAGER', 'DEPT_HEAD'])
            ->whereNotNull('er.department_id')
            ->pluck('er.department_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($ids, $roleDepartmentIds)));
    }

    public function canAccessEmployee(Request $request, int $employeeId, bool $managementAction = false): bool
    {
        $employee = DB::table('employees')
            ->where('tenant_id', TenantContext::id())
            ->where('id', $employeeId)
            ->first(['id', 'legal_entity_id', 'department_id']);
        if (! $employee) {
            return false;
        }

        if ($this->isAdmin($request)) {
            return true;
        }
        if ($this->isHr($request)) {
            return (int) $employee->legal_entity_id === (int) TenantContext::legalEntityId();
        }
        if ($this->isDepartmentManager($request)) {
            if ((int) $employee->id === $this->actorId($request)) {
                return ! $managementAction;
            }
            if (in_array((int) $employee->department_id, $this->managedDepartmentIds($request), true)) {
                return true;
            }

            return DB::table('employees')
                ->where('tenant_id', TenantContext::id())
                ->where('id', $employeeId)
                ->where('manager_id', $this->actorId($request))
                ->exists();
        }

        return ! $managementAction && (int) $employee->id === $this->actorId($request);
    }

    public function canAccessAttendance(Request $request, Attendance $attendance, bool $managementAction = false): bool
    {
        return $this->canAccessEmployee($request, (int) $attendance->employee_id, $managementAction);
    }

    public function canFilterTimesheetEmployee(Request $request, int $employeeId, int $legalEntityId): bool
    {
        if ($this->isAccountant($request)) {
            return DB::table('employees')
                ->where('tenant_id', TenantContext::id())
                ->where('legal_entity_id', $legalEntityId)
                ->where('id', $employeeId)
                ->exists();
        }

        return $this->canAccessEmployee($request, $employeeId);
    }

    public function scopeAttendances(EloquentBuilder $query, Request $request, string $table = 'attendances'): EloquentBuilder
    {
        $query->where("{$table}.tenant_id", TenantContext::id());
        if ($this->isAdmin($request)) {
            return $query;
        }
        if ($this->isHr($request)) {
            return $query->where("{$table}.legal_entity_id", TenantContext::legalEntityId());
        }
        if ($this->isDepartmentManager($request)) {
            $departmentIds = $this->managedDepartmentIds($request);
            $actorId = $this->actorId($request);

            return $query->where(function (EloquentBuilder $scope) use ($table, $departmentIds, $actorId): void {
                $scope->where("{$table}.employee_id", $actorId)
                    ->orWhereIn("{$table}.employee_id", function (QueryBuilder $employees) use ($departmentIds, $actorId): void {
                        $employees->select('id')->from('employees')
                            ->where('tenant_id', TenantContext::id())
                            ->where('legal_entity_id', TenantContext::legalEntityId())
                            ->where(function (QueryBuilder $managed) use ($departmentIds, $actorId): void {
                                if ($departmentIds !== []) {
                                    $managed->whereIn('department_id', $departmentIds)->orWhere('manager_id', $actorId);
                                } else {
                                    $managed->where('manager_id', $actorId);
                                }
                            });
                    });
            });
        }

        return $query->where("{$table}.employee_id", $this->actorId($request));
    }

    public function scopeEmployeeResource(
        EloquentBuilder $query,
        Request $request,
        string $table,
        string $employeeColumn = 'employee_id',
    ): EloquentBuilder {
        $query->where("{$table}.tenant_id", TenantContext::id());
        if ($this->isAdmin($request)) {
            return $query;
        }
        if ($this->isHr($request)) {
            if (DB::getSchemaBuilder()->hasColumn($table, 'legal_entity_id')) {
                return $query->where("{$table}.legal_entity_id", TenantContext::legalEntityId());
            }

            return $query->whereIn("{$table}.{$employeeColumn}", function (QueryBuilder $employees): void {
                $employees->select('id')->from('employees')
                    ->where('tenant_id', TenantContext::id())
                    ->where('legal_entity_id', TenantContext::legalEntityId());
            });
        }
        if ($this->isDepartmentManager($request)) {
            $departmentIds = $this->managedDepartmentIds($request);
            $actorId = $this->actorId($request);

            return $query->where(function (EloquentBuilder $scope) use ($table, $employeeColumn, $departmentIds, $actorId): void {
                $scope->where("{$table}.{$employeeColumn}", $actorId)
                    ->orWhereIn("{$table}.{$employeeColumn}", function (QueryBuilder $employees) use ($departmentIds, $actorId): void {
                        $employees->select('id')->from('employees')
                            ->where('tenant_id', TenantContext::id())
                            ->where('legal_entity_id', TenantContext::legalEntityId())
                            ->where(function (QueryBuilder $managed) use ($departmentIds, $actorId): void {
                                if ($departmentIds !== []) {
                                    $managed->whereIn('department_id', $departmentIds)->orWhere('manager_id', $actorId);
                                } else {
                                    $managed->where('manager_id', $actorId);
                                }
                            });
                    });
            });
        }

        return $query->where("{$table}.{$employeeColumn}", $this->actorId($request));
    }

    public function scopeEmployeeQuery(QueryBuilder $query, Request $request, string $table = 'employees'): QueryBuilder
    {
        $query->where("{$table}.tenant_id", TenantContext::id());
        if ($this->isAdmin($request)) {
            return $query;
        }
        if ($this->isHr($request) || $this->isAccountant($request)) {
            return $query->where("{$table}.legal_entity_id", TenantContext::legalEntityId());
        }
        if ($this->isDepartmentManager($request)) {
            $departmentIds = $this->managedDepartmentIds($request);
            $actorId = $this->actorId($request);

            return $query->where("{$table}.legal_entity_id", TenantContext::legalEntityId())
                ->where(function (QueryBuilder $scope) use ($table, $departmentIds, $actorId): void {
                    $scope->where("{$table}.id", $actorId);
                    $scope->orWhere(function (QueryBuilder $managed) use ($table, $departmentIds, $actorId): void {
                        if ($departmentIds !== []) {
                            $managed->whereIn("{$table}.department_id", $departmentIds)
                                ->orWhere("{$table}.manager_id", $actorId);
                        } else {
                            $managed->where("{$table}.manager_id", $actorId);
                        }
                    });
                });
        }

        return $query->where("{$table}.id", $this->actorId($request));
    }

    public function scopeChangeEvents(QueryBuilder $query, Request $request, string $table = 'attendance_change_events'): QueryBuilder
    {
        $query->where("{$table}.tenant_id", TenantContext::id());
        if ($this->isAdmin($request)) {
            return $query;
        }
        if ($this->isHr($request)) {
            return $query->where("{$table}.legal_entity_id", TenantContext::legalEntityId());
        }
        if ($this->isDepartmentManager($request)) {
            $departmentIds = $this->managedDepartmentIds($request);

            return $query->where(function (QueryBuilder $scope) use ($table, $departmentIds): void {
                if ($departmentIds !== []) {
                    $scope->whereIn("{$table}.department_id", $departmentIds);
                } else {
                    $scope->whereRaw('1 = 0');
                }
                $scope->orWhere(function (QueryBuilder $refresh) use ($table, $departmentIds): void {
                    if ($departmentIds === []) {
                        $refresh->whereRaw('1 = 0');

                        return;
                    }
                    $refresh->whereNull("{$table}.employee_id")
                        ->where("{$table}.legal_entity_id", TenantContext::legalEntityId())
                        ->where(function (QueryBuilder $audience) use ($table, $departmentIds): void {
                            foreach ($departmentIds as $index => $departmentId) {
                                $index === 0
                                    ? $audience->whereJsonContains("{$table}.audience_department_ids", (int) $departmentId)
                                    : $audience->orWhereJsonContains("{$table}.audience_department_ids", (int) $departmentId);
                            }
                        });
                });
            });
        }

        return $query->where(function (QueryBuilder $scope) use ($table, $request): void {
            $scope->where("{$table}.employee_id", $this->actorId($request))
                ->orWhere(function (QueryBuilder $refresh) use ($table, $request): void {
                    $refresh->whereNull("{$table}.employee_id")
                        ->where("{$table}.legal_entity_id", TenantContext::legalEntityId())
                        ->whereJsonContains("{$table}.audience_employee_ids", $this->actorId($request));
                });
        });
    }

    /**
     * Null means the caller may read the whole selected legal entity. Otherwise
     * the returned IDs are the immutable upper bound for grids and queued exports.
     *
     * @return array<int, int>|null
     */
    public function timesheetEmployeeIds(Request $request, int $legalEntityId): ?array
    {
        if ($this->isAdmin($request) || $this->isHr($request) || $this->isAccountant($request)) {
            return null;
        }

        if (! $this->isDepartmentManager($request)) {
            return [$this->actorId($request)];
        }

        return $this->scopeEmployeeQuery(DB::table('employees'), $request)
            ->where('employees.legal_entity_id', $legalEntityId)
            ->whereIn('employees.status', ['ACTIVE', 'PROBATION'])
            ->orderBy('employees.id')
            ->pluck('employees.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function assertDepartmentFilter(Request $request, ?int $departmentId): bool
    {
        if (! $departmentId) {
            return true;
        }
        if ($this->isAdmin($request)) {
            return DB::table('departments')
                ->where('tenant_id', TenantContext::id())
                ->where('id', $departmentId)
                ->exists();
        }
        if ($this->isHr($request) || $this->isAccountant($request)) {
            return DB::table('departments')
                ->where('tenant_id', TenantContext::id())
                ->where('legal_entity_id', TenantContext::legalEntityId())
                ->where('id', $departmentId)
                ->exists();
        }

        return $this->isDepartmentManager($request)
            && in_array($departmentId, $this->managedDepartmentIds($request), true);
    }

    /** @return array<int, string> */
    public function realtimeChannels(Request $request): array
    {
        $tenantId = (int) TenantContext::id();
        if ($this->isAdmin($request)) {
            return ["attendance.tenant.{$tenantId}.all"];
        }
        if ($this->isHr($request)) {
            return ["attendance.tenant.{$tenantId}.entity.".(int) TenantContext::legalEntityId()];
        }
        if ($this->isDepartmentManager($request)) {
            return array_map(
                fn (int $departmentId): string => "attendance.tenant.{$tenantId}.department.{$departmentId}",
                $this->managedDepartmentIds($request),
            );
        }

        return ['attendance.employee.'.$this->actorId($request)];
    }

    public function canViewOperation(Request $request, object $operation): bool
    {
        if ((int) $operation->tenant_id !== (int) TenantContext::id()) {
            return false;
        }
        if ($this->isAdmin($request)) {
            return true;
        }

        return (int) $operation->requested_by === $this->actorId($request);
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
}

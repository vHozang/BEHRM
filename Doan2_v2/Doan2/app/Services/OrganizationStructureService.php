<?php

namespace App\Services;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OrganizationStructureService
{
    private const UNIT_TYPES = ['DEPARTMENT', 'WORKSHOP', 'TEAM'];

    /** @var array<int, array<string, mixed>> */
    private array $warnings = [];

    /** @var array<string, bool> */
    private array $warningKeys = [];

    /** @var array<int, object> */
    private array $entities = [];

    /** @var array<int, array<string, mixed>> */
    private array $entityMeta = [];

    /** @var array<int, object> */
    private array $departments = [];

    /** @var array<int, array<string, mixed>> */
    private array $departmentMeta = [];

    /** @var array<int, int|null> */
    private array $departmentParents = [];

    /** @var array<int, array<int, int>> */
    private array $departmentChildren = [];

    /** @var array<int, array<string, mixed>> */
    private array $employees = [];

    /** @var array<int, array<int, int>> */
    private array $employeeIdsByDepartment = [];

    /** @var array<int, array<int, int>> */
    private array $employeeIdsByEntity = [];

    /** @var array<int, array<int, int>> */
    private array $descendantCache = [];

    private int $headOfficeId = 0;

    /**
     * @return array<string, mixed>
     */
    public function structure(string $scope, ?int $legalEntityId = null, ?int $departmentId = null): array
    {
        $this->reset();
        $this->loadData();

        $scope = strtolower($scope);
        if (! in_array($scope, ['company', 'branch', 'department'], true)) {
            throw new InvalidArgumentException('Phạm vi sơ đồ không hợp lệ');
        }

        if ($scope === 'branch' && (! $legalEntityId || ! isset($this->entities[$legalEntityId]))) {
            throw new InvalidArgumentException('Chi nhánh không tồn tại trong công ty hiện tại');
        }
        if ($scope === 'department' && (! $departmentId || ! isset($this->departments[$departmentId]))) {
            throw new InvalidArgumentException('Phòng ban không tồn tại trong công ty hiện tại');
        }

        $roots = match ($scope) {
            'branch' => [$this->buildBranchNode((int) $legalEntityId, false)],
            'department' => [$this->buildDepartmentNode((int) $departmentId, 'department')],
            default => $this->buildCompanyRoots(),
        };

        $roots = array_values(array_filter($roots));
        $currentEntityId = $scope === 'branch'
            ? $legalEntityId
            : ($scope === 'department' ? (int) $this->departments[$departmentId]->legal_entity_id : null);

        return [
            'scope' => [
                'type' => $scope,
                'legal_entity_id' => $currentEntityId,
                'department_id' => $scope === 'department' ? $departmentId : null,
                'label' => $this->scopeLabel($scope, $legalEntityId, $departmentId),
            ],
            'executives' => $scope === 'company' ? $this->executives() : [],
            'roots' => $roots,
            'breadcrumbs' => $this->breadcrumbs($scope, $legalEntityId, $departmentId),
            'filters' => $this->filters(),
            'warnings' => array_values($this->warnings),
            'summary' => [
                'headcount_total' => $this->scopeHeadcount($scope, $legalEntityId, $departmentId),
                'unit_count' => $this->countNodes($roots),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function reset(): void
    {
        $this->warnings = [];
        $this->warningKeys = [];
        $this->entities = [];
        $this->entityMeta = [];
        $this->departments = [];
        $this->departmentMeta = [];
        $this->departmentParents = [];
        $this->departmentChildren = [];
        $this->employees = [];
        $this->employeeIdsByDepartment = [];
        $this->employeeIdsByEntity = [];
        $this->descendantCache = [];
        $this->headOfficeId = 0;
    }

    private function loadData(): void
    {
        $tenantId = (int) TenantContext::id();

        foreach (DB::table('legal_entities')->where('tenant_id', $tenantId)->orderBy('id')->get() as $entity) {
            $this->entities[(int) $entity->id] = $entity;
            $this->entityMeta[(int) $entity->id] = $this->decodeMeta($entity->meta ?? null);
        }

        foreach ($this->entities as $id => $entity) {
            if (strtoupper((string) ($this->entityMeta[$id]['branch_type'] ?? '')) === 'HEAD_OFFICE') {
                $this->headOfficeId = $id;
                break;
            }
        }
        if ($this->headOfficeId === 0 && $this->entities !== []) {
            $this->headOfficeId = (int) array_key_first($this->entities);
        }

        foreach (DB::table('departments')->where('tenant_id', $tenantId)->orderBy('id')->get() as $department) {
            $entity = $this->entities[(int) $department->legal_entity_id] ?? null;
            if (! $entity || ! $this->isActiveValue($entity->status ?? 'ACTIVE') || ! $this->isActiveValue($department->status ?? true)) {
                continue;
            }
            $id = (int) $department->id;
            $this->departments[$id] = $department;
            $this->departmentMeta[$id] = $this->decodeMeta($department->meta ?? null);
        }

        $this->normalizeDepartmentParents();

        $rows = DB::table('employees as e')
            ->leftJoin('positions as p', 'p.id', '=', 'e.position_id')
            ->where('e.tenant_id', $tenantId)
            ->orderBy('e.id')
            ->get([
                'e.id', 'e.employee_code', 'e.full_name', 'e.status', 'e.legal_entity_id',
                'e.department_id', 'e.manager_id', 'e.profile', 'p.position_code', 'p.position_name',
            ]);

        foreach ($rows as $row) {
            $status = strtoupper((string) $row->status);
            $profile = $this->decodeMeta($row->profile ?? null);
            if (! in_array($status, ['ACTIVE', 'PROBATION'], true) || $this->truthy($profile['system_account'] ?? false)) {
                continue;
            }

            $employee = [
                'id' => (int) $row->id,
                'employee_code' => (string) ($row->employee_code ?? ''),
                'full_name' => (string) ($row->full_name ?? ''),
                'status' => $status,
                'legal_entity_id' => (int) $row->legal_entity_id,
                'department_id' => $row->department_id ? (int) $row->department_id : null,
                'manager_id' => $row->manager_id ? (int) $row->manager_id : null,
                'position_code' => (string) ($row->position_code ?? ''),
                'position_name' => (string) ($row->position_name ?? ''),
            ];
            $this->employees[$employee['id']] = $employee;
            $this->employeeIdsByEntity[$employee['legal_entity_id']][] = $employee['id'];
            if ($employee['department_id']
                && isset($this->departments[$employee['department_id']])
                && (int) $this->departments[$employee['department_id']]->legal_entity_id === $employee['legal_entity_id']) {
                $this->employeeIdsByDepartment[$employee['department_id']][] = $employee['id'];
            }
        }
    }

    private function normalizeDepartmentParents(): void
    {
        foreach ($this->departments as $id => $department) {
            $meta = $this->departmentMeta[$id];
            $parentId = (int) ($meta['parent_id'] ?? $meta['parent_department_id'] ?? 0);
            if ($parentId === 0) {
                $this->departmentParents[$id] = null;

                continue;
            }
            if ($parentId === $id || ! isset($this->departments[$parentId])) {
                $this->departmentParents[$id] = null;
                $this->warn(
                    "invalid-parent:{$id}",
                    'INVALID_DEPARTMENT_PARENT',
                    'department',
                    $id,
                    (string) $department->department_name,
                    'Phòng ban có đơn vị cha không hợp lệ và được hiển thị ở cấp gốc.'
                );

                continue;
            }
            if ((int) $this->departments[$parentId]->legal_entity_id !== (int) $department->legal_entity_id) {
                $this->departmentParents[$id] = null;
                $this->warn(
                    "cross-entity-parent:{$id}",
                    'CROSS_ENTITY_DEPARTMENT_PARENT',
                    'department',
                    $id,
                    (string) $department->department_name,
                    'Đơn vị cha thuộc chi nhánh khác; phòng ban được hiển thị ở cấp gốc.'
                );

                continue;
            }
            $this->departmentParents[$id] = $parentId;
        }

        foreach (array_keys($this->departments) as $startId) {
            $path = [];
            $pathIndex = [];
            $current = $startId;
            while ($current && isset($this->departmentParents[$current])) {
                if (isset($pathIndex[$current])) {
                    $cycle = array_slice($path, $pathIndex[$current]);
                    $breakId = min($cycle);
                    $this->departmentParents[$breakId] = null;
                    $names = array_map(fn (int $id) => (string) $this->departments[$id]->department_name, $cycle);
                    $this->warn(
                        'department-cycle:'.implode('-', $cycle),
                        'DEPARTMENT_CYCLE',
                        'department',
                        $breakId,
                        (string) $this->departments[$breakId]->department_name,
                        'Phát hiện vòng lặp phòng ban: '.implode(' → ', $names).'. Một liên kết đã được ngắt khi dựng sơ đồ.'
                    );
                    break;
                }
                $pathIndex[$current] = count($path);
                $path[] = $current;
                $current = $this->departmentParents[$current] ?? null;
            }
        }

        foreach ($this->departmentParents as $id => $parentId) {
            if ($parentId) {
                $this->departmentChildren[$parentId][] = $id;
            }
        }
        foreach ($this->departmentChildren as &$children) {
            sort($children);
        }
        unset($children);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildCompanyRoots(): array
    {
        $roots = [];
        foreach ($this->rootDepartmentsForEntity($this->headOfficeId) as $departmentId) {
            $roots[] = $this->buildDepartmentNode($departmentId, 'company');
        }

        foreach ($this->entities as $entityId => $entity) {
            if ($entityId === $this->headOfficeId || ! $this->isActiveValue($entity->status ?? 'ACTIVE')) {
                continue;
            }
            $roots[] = $this->buildBranchNode($entityId, true);
        }

        return array_values(array_filter($roots));
    }

    /** @return array<string, mixed> */
    private function buildBranchNode(int $entityId, bool $companyScope): array
    {
        $entity = $this->entities[$entityId];
        $meta = $this->entityMeta[$entityId];
        $isHeadOffice = $entityId === $this->headOfficeId;
        $head = null;

        if (! $isHeadOffice) {
            $head = $this->resolveHead(
                isset($meta['head_employee_id']) ? (int) $meta['head_employee_id'] : null,
                $entityId,
                'branch',
                $entityId,
                (string) $entity->name,
                'Chi nhánh chưa có người đứng đầu.'
            );
        }

        $children = array_map(
            fn (int $departmentId) => $this->buildDepartmentNode($departmentId, $companyScope ? 'company' : 'branch'),
            $this->rootDepartmentsForEntity($entityId)
        );

        $employeeIds = array_values(array_unique($this->employeeIdsByEntity[$entityId] ?? []));
        $assignedIds = [];
        foreach ($employeeIds as $employeeId) {
            $departmentId = $this->employees[$employeeId]['department_id'] ?? null;
            if ($departmentId
                && isset($this->departments[$departmentId])
                && (int) $this->departments[$departmentId]->legal_entity_id === $entityId) {
                $assignedIds[] = $employeeId;
            }
        }

        return [
            'key' => 'branch:'.$entityId,
            'node_type' => 'UNIT',
            'unit_type' => $isHeadOffice ? 'HEAD_OFFICE' : 'BRANCH',
            'unit_type_label' => $isHeadOffice ? 'Trụ sở chính' : 'Chi nhánh',
            'id' => $entityId,
            'code' => (string) ($entity->code ?? ''),
            'name' => (string) $entity->name,
            'legal_entity_id' => $entityId,
            'parent_id' => null,
            'head' => $head,
            'head_label' => $isHeadOffice ? null : 'Người đứng đầu chi nhánh',
            'headcount_total' => count($employeeIds),
            'unassigned_headcount' => count(array_diff($employeeIds, $assignedIds)),
            'collapsed_by_default' => false,
            'drilldown' => ['scope' => 'branch', 'legal_entity_id' => $entityId, 'department_id' => null],
            'children' => array_values(array_filter($children)),
        ];
    }

    /** @return array<string, mixed> */
    private function buildDepartmentNode(int $departmentId, string $viewScope): array
    {
        $department = $this->departments[$departmentId];
        $meta = $this->departmentMeta[$departmentId];
        $unitType = $this->unitType($department, $meta);
        $children = array_map(
            fn (int $childId) => $this->buildDepartmentNode($childId, $viewScope),
            $this->departmentChildren[$departmentId] ?? []
        );
        $head = $this->resolveHead(
            isset($meta['manager_id']) ? (int) $meta['manager_id'] : null,
            (int) $department->legal_entity_id,
            'department',
            $departmentId,
            (string) $department->department_name,
            $this->unitTypeLabel($unitType).' chưa có người phụ trách.'
        );

        $collapse = $viewScope === 'company'
            && collect($children)->contains(fn (array $child) => in_array($child['unit_type'], ['WORKSHOP', 'TEAM'], true));

        return [
            'key' => 'department:'.$departmentId,
            'node_type' => 'UNIT',
            'unit_type' => $unitType,
            'unit_type_label' => $this->unitTypeLabel($unitType),
            'id' => $departmentId,
            'code' => (string) ($department->department_code ?? ''),
            'name' => (string) ($department->department_name ?? ''),
            'legal_entity_id' => (int) $department->legal_entity_id,
            'parent_id' => $this->departmentParents[$departmentId] ?? null,
            'head' => $head,
            'head_label' => $unitType === 'WORKSHOP' ? 'Trưởng phân xưởng' : ($unitType === 'TEAM' ? 'Tổ trưởng / Phụ trách' : 'Trưởng phòng / Phụ trách'),
            'headcount_total' => count($this->employeeIdsForDepartmentTree($departmentId)),
            'collapsed_by_default' => $collapse,
            'drilldown' => ['scope' => 'department', 'legal_entity_id' => (int) $department->legal_entity_id, 'department_id' => $departmentId],
            'children' => array_values(array_filter($children)),
        ];
    }

    /** @return array<int, int> */
    private function rootDepartmentsForEntity(int $entityId): array
    {
        $roots = [];
        foreach ($this->departments as $id => $department) {
            if ((int) $department->legal_entity_id === $entityId && empty($this->departmentParents[$id])) {
                $roots[] = $id;
            }
        }
        sort($roots);

        return $roots;
    }

    /** @return array<int, int> */
    private function employeeIdsForDepartmentTree(int $departmentId): array
    {
        $ids = [];
        foreach ($this->descendantDepartmentIds($departmentId) as $id) {
            $ids = array_merge($ids, $this->employeeIdsByDepartment[$id] ?? []);
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, int> */
    private function descendantDepartmentIds(int $departmentId): array
    {
        if (isset($this->descendantCache[$departmentId])) {
            return $this->descendantCache[$departmentId];
        }

        $ids = [$departmentId];
        foreach ($this->departmentChildren[$departmentId] ?? [] as $childId) {
            $ids = array_merge($ids, $this->descendantDepartmentIds($childId));
        }

        return $this->descendantCache[$departmentId] = array_values(array_unique($ids));
    }

    /** @return array<string, mixed>|null */
    private function resolveHead(?int $employeeId, int $entityId, string $unitKind, int $unitId, string $unitName, string $missingMessage): ?array
    {
        if (! $employeeId) {
            $this->warn("missing-head:{$unitKind}:{$unitId}", 'MISSING_UNIT_HEAD', $unitKind, $unitId, $unitName, $missingMessage);

            return null;
        }

        $employee = $this->employees[$employeeId] ?? null;
        if (! $employee || (int) $employee['legal_entity_id'] !== $entityId) {
            $this->warn(
                "invalid-head:{$unitKind}:{$unitId}",
                'INVALID_UNIT_HEAD',
                $unitKind,
                $unitId,
                $unitName,
                'Người phụ trách không còn làm việc hoặc thuộc chi nhánh khác.'
            );

            return null;
        }

        return $this->safeEmployee($employee);
    }

    /** @return array<int, array<string, mixed>> */
    private function executives(): array
    {
        $executives = [];
        $ceo = collect($this->employees)
            ->filter(fn (array $employee) => strtoupper($employee['position_code']) === 'GD')
            ->sortBy(fn (array $employee) => [
                $employee['legal_entity_id'] === $this->headOfficeId ? 0 : 1,
                $employee['manager_id'] === null ? 0 : 1,
                $employee['id'],
            ])
            ->first();

        if ($ceo) {
            $executives[] = [
                'key' => 'executive:GD:'.$ceo['id'],
                'node_type' => 'EXECUTIVE',
                'role_code' => 'GD',
                'display_role' => 'Tổng giám đốc',
                'employee' => $this->safeEmployee($ceo, 'Tổng giám đốc'),
            ];
        } else {
            $this->warn('missing-executive:GD', 'MISSING_EXECUTIVE', 'executive', 0, 'Tổng giám đốc', 'Chưa xác định được Tổng giám đốc từ chức danh GD.');
        }

        $deputy = collect($this->employees)
            ->filter(fn (array $employee) => strtoupper($employee['position_code']) === 'PGD')
            ->sortBy(fn (array $employee) => [
                $employee['legal_entity_id'] === $this->headOfficeId ? 0 : 1,
                $ceo && $employee['manager_id'] === $ceo['id'] ? 0 : 1,
                $employee['id'],
            ])
            ->first();

        if ($deputy) {
            $executives[] = [
                'key' => 'executive:PGD:'.$deputy['id'],
                'node_type' => 'EXECUTIVE',
                'role_code' => 'PGD',
                'display_role' => 'Phó giám đốc',
                'employee' => $this->safeEmployee($deputy, 'Phó giám đốc'),
            ];
        } else {
            $this->warn('missing-executive:PGD', 'MISSING_EXECUTIVE', 'executive', 0, 'Phó giám đốc', 'Chưa xác định được Phó giám đốc từ chức danh PGD.');
        }

        return $executives;
    }

    /** @return array<string, mixed> */
    private function safeEmployee(array $employee, ?string $displayRole = null): array
    {
        return [
            'id' => (int) $employee['id'],
            'employee_code' => (string) $employee['employee_code'],
            'full_name' => (string) $employee['full_name'],
            'position_code' => (string) $employee['position_code'],
            'position_name' => (string) $employee['position_name'],
            'display_role' => $displayRole ?: (string) $employee['position_name'],
        ];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function filters(): array
    {
        $entities = [];
        foreach ($this->entities as $id => $entity) {
            if (! $this->isActiveValue($entity->status ?? 'ACTIVE')) {
                continue;
            }
            $entities[] = [
                'id' => $id,
                'code' => (string) ($entity->code ?? ''),
                'name' => (string) $entity->name,
                'branch_type' => $id === $this->headOfficeId ? 'HEAD_OFFICE' : strtoupper((string) ($this->entityMeta[$id]['branch_type'] ?? 'BRANCH')),
            ];
        }

        $departments = [];
        foreach ($this->departments as $id => $department) {
            $departments[] = [
                'id' => $id,
                'code' => (string) ($department->department_code ?? ''),
                'name' => (string) ($department->department_name ?? ''),
                'legal_entity_id' => (int) $department->legal_entity_id,
                'parent_id' => $this->departmentParents[$id] ?? null,
                'unit_type' => $this->unitType($department, $this->departmentMeta[$id]),
            ];
        }

        return ['legal_entities' => $entities, 'departments' => $departments];
    }

    /** @return array<int, array<string, mixed>> */
    private function breadcrumbs(string $scope, ?int $legalEntityId, ?int $departmentId): array
    {
        $items = [['label' => 'Toàn công ty', 'scope' => 'company', 'legal_entity_id' => null, 'department_id' => null]];
        if ($scope === 'company') {
            return $items;
        }

        $entityId = $scope === 'branch' ? (int) $legalEntityId : (int) $this->departments[$departmentId]->legal_entity_id;
        $entity = $this->entities[$entityId];
        $items[] = [
            'label' => (string) $entity->name,
            'scope' => 'branch',
            'legal_entity_id' => $entityId,
            'department_id' => null,
        ];

        if ($scope === 'department') {
            $chain = [];
            $current = (int) $departmentId;
            while ($current && isset($this->departments[$current])) {
                array_unshift($chain, $current);
                $current = $this->departmentParents[$current] ?? 0;
            }
            foreach ($chain as $id) {
                $items[] = [
                    'label' => (string) $this->departments[$id]->department_name,
                    'scope' => 'department',
                    'legal_entity_id' => $entityId,
                    'department_id' => $id,
                ];
            }
        }

        return $items;
    }

    private function scopeLabel(string $scope, ?int $legalEntityId, ?int $departmentId): string
    {
        return match ($scope) {
            'branch' => (string) $this->entities[$legalEntityId]->name,
            'department' => (string) $this->departments[$departmentId]->department_name,
            default => 'Toàn công ty',
        };
    }

    private function scopeHeadcount(string $scope, ?int $legalEntityId, ?int $departmentId): int
    {
        return match ($scope) {
            'branch' => count(array_unique($this->employeeIdsByEntity[$legalEntityId] ?? [])),
            'department' => count($this->employeeIdsForDepartmentTree((int) $departmentId)),
            default => count($this->employees),
        };
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function countNodes(array $nodes): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            $count++;
            $count += $this->countNodes($node['children'] ?? []);
        }

        return $count;
    }

    private function unitType(object $department, array $meta): string
    {
        $type = strtoupper((string) ($meta['unit_type'] ?? ''));
        if (in_array($type, self::UNIT_TYPES, true)) {
            return $type;
        }

        $code = strtoupper((string) ($department->department_code ?? ''));
        $name = mb_strtolower(trim((string) ($department->department_name ?? '')));
        if (str_starts_with($code, 'PX-') || str_starts_with($name, 'phân xưởng')) {
            return 'WORKSHOP';
        }
        if (str_starts_with($name, 'tổ')) {
            return 'TEAM';
        }

        return 'DEPARTMENT';
    }

    private function unitTypeLabel(string $type): string
    {
        return match ($type) {
            'WORKSHOP' => 'Phân xưởng',
            'TEAM' => 'Tổ / Bộ phận',
            default => 'Phòng ban',
        };
    }

    private function warn(string $key, string $code, string $unitType, int $unitId, string $unitName, string $message): void
    {
        if (isset($this->warningKeys[$key])) {
            return;
        }
        $this->warningKeys[$key] = true;
        $this->warnings[] = [
            'code' => $code,
            'unit_type' => $unitType,
            'unit_id' => $unitId ?: null,
            'unit_name' => $unitName,
            'message' => $message,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isActiveValue(mixed $value): bool
    {
        return ! in_array(strtoupper((string) $value), ['0', 'F', 'FALSE', 'INACTIVE', 'DELETED'], true);
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);
    }
}

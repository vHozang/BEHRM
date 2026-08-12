<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepartmentManagerRoleService;
use App\Services\ShiftRosterAccess;
use App\Support\AuditLogger;
use App\Support\HrmTables;
use App\Support\ResourceBusinessRules;
use App\Support\TenantContext;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generic Resource Controller — xử lý CRUD đơn giản cho các bảng cơ bản / danh mục.
 *
 * Các bảng nghiệp vụ cốt lõi (employees, contracts, leave_requests, attendances,
 * salary_periods, recruitment_candidates, requests) đã có controller riêng.
 */
class GenericResourceController extends Controller
{
    public function __construct(
        private readonly ShiftRosterAccess $shiftRosterAccess,
        private readonly DepartmentManagerRoleService $departmentManagerRoles,
    ) {}

    public function root(): JsonResponse
    {
        return $this->ok([
            'health' => '/api/v1/health',
            'login' => '/api/v1/auth/login',
            'me' => '/api/v1/auth/me',
            'version' => 'v1',
        ], 'HRM API is running');
    }

    public function health(): JsonResponse
    {
        return $this->ok([
            'app' => config('app.name'),
            'database' => config('database.default'),
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
        ], 'HRM API is healthy');
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $table = $this->tableOrFail($resource);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $query = DB::table($table)->orderByDesc('id');

        if (TenantContext::hasTenant() && Schema::hasColumn($table, 'tenant_id')) {
            $query->where($table.'.tenant_id', TenantContext::id());
        }

        if ($table === 'shift_assignments') {
            $query = $this->shiftRosterAccess->scopeAssignments($query, $request, $table);
        }

        if ($table === 'service_tickets' && ! $this->canManageServiceTickets($request)) {
            $query->where('requester_id', $request->attributes->get('auth_employee_id'));
        }

        foreach ($request->query() as $key => $value) {
            if (Schema::hasColumn($table, $key) && ! in_array($key, ['page', 'per_page'], true)) {
                $query->where($key, $value);
            }
        }

        $page = $query->paginate($perPage);

        $items = array_map(function ($item) use ($table) {
            return $this->unpackMeta($item, $table);
        }, $page->items());
        if ($table === 'shift_assignments' && $items) {
            $employeeIds = collect($items)->pluck('employee_id')->filter()->unique()->values();
            $employees = DB::table('employees')
                ->where('tenant_id', TenantContext::id())
                ->whereIn('id', $employeeIds)
                ->get(['id', 'employee_code', 'full_name'])
                ->keyBy('id');
            foreach ($items as $item) {
                $employee = $employees->get((int) $item->employee_id);
                $item->employee_code = $employee->employee_code ?? null;
                $item->employee_name = $employee->full_name ?? null;
            }
        }

        // Policies: đính trạng thái ĐÃ KÝ của chính người xem + số lượt ký THẬT.
        // Thiếu cái này thì NV ký xong (POST acknowledge 200) list vẫn "Chưa xác
        // nhận" mãi — và acknowledgments_count chỉ là số demo tĩnh trong meta.
        if ($table === 'policies' && $items) {
            $empId = $request->attributes->get('auth_employee_id');
            $ids = array_column($items, 'id');
            $mine = $empId
                ? DB::table('policy_acknowledgments')->whereIn('policy_id', $ids)
                    ->where('employee_id', $empId)->pluck('acknowledged_at', 'policy_id')
                : collect();
            $counts = DB::table('policy_acknowledgments')->whereIn('policy_id', $ids)
                ->selectRaw('policy_id, count(*) n')->groupBy('policy_id')->pluck('n', 'policy_id');
            foreach ($items as &$it) {
                $pid = is_array($it) ? $it['id'] : $it->id;
                $ack = $mine[$pid] ?? null;
                if (is_array($it)) {
                    $it['is_acknowledged'] = $ack !== null;
                    $it['acknowledged_at'] = $ack;
                    $it['acknowledgments_count'] = (int) ($counts[$pid] ?? 0);
                } else {
                    $it->is_acknowledged = $ack !== null;
                    $it->acknowledged_at = $ack;
                    $it->acknowledgments_count = (int) ($counts[$pid] ?? 0);
                }
            }
            unset($it);
        }

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], Str::headline($resource).' list');
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        $table = $this->tableOrFail($resource);
        $query = DB::table($table)->where('id', $id);

        if (TenantContext::hasTenant() && Schema::hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', TenantContext::id());
        }

        if ($table === 'shift_assignments') {
            $query = $this->shiftRosterAccess->scopeAssignments($query, $request, $table);
        }

        $record = $query->first();

        if (! $record) {
            return $this->notFound();
        }

        if ($table === 'service_tickets'
            && ! $this->canManageServiceTickets($request)
            && (int) $record->requester_id !== (int) $request->attributes->get('auth_employee_id')) {
            return $this->notFound();
        }

        if ($table === 'shift_assignments') {
            $employee = DB::table('employees')
                ->where('id', $record->employee_id)
                ->where('tenant_id', TenantContext::id())
                ->first(['employee_code', 'full_name']);
            $record->employee_code = $employee->employee_code ?? null;
            $record->employee_name = $employee->full_name ?? null;
        }

        return $this->ok($this->unpackMeta($record, $table), Str::headline($resource).' detail');
    }

    public function store(Request $request, string $resource, int|string|null $id = null, ?string $child = null): JsonResponse
    {
        // Resolve route parameters by name (the route may supply some via defaults()).
        $resource = (string) $request->route('resource', $resource);
        $child = $request->route('child', $child);
        $routeId = $request->route('id');
        $id = $routeId !== null ? (int) $routeId : $id;

        // Special actions kept for simple resources
        if ($resource === 'news' && $child === 'read' && $id) {
            $empId = $request->attributes->get('auth_employee_id');

            if ($empId !== null) {
                $now = now();
                DB::table('news_reads')->updateOrInsert(
                    ['news_id' => $id, 'employee_id' => $empId],
                    TenantContext::stamp(['read_at' => $now, 'updated_at' => $now, 'created_at' => $now])
                );
            }

            return $this->ok(['news_id' => $id, 'read' => true], 'News marked as read');
        }

        $table = $this->tableOrFail($resource);
        if ($table === 'shift_types' && ! $this->shiftRosterAccess->canManageShiftTypes($request)) {
            abort(403, 'Chỉ Admin hoặc HR được quản lý định nghĩa ca');
        }
        $payload = $this->payloadFor($request, $table);

        if ($table === 'departments' && ($errors = $this->departmentReferenceErrors($payload)) !== []) {
            return $this->validationError($errors);
        }

        if ($table === 'shift_assignments') {
            $employeeId = (int) ($payload['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $this->shiftRosterAccess->assertEmployee($request, $employeeId);
            }
            $payload['assigned_by'] = $this->shiftRosterAccess->actorId($request);
            $payload = $this->normalizeManualShiftAssignment($payload, true);
        }

        if ($table === 'service_tickets') {
            $payload['requester_id'] = (int) $request->attributes->get('auth_employee_id');
            $payload['status'] = 'pending';
        }

        // Validate business rules before creating
        $validation = ResourceBusinessRules::validateStore($table, $payload);

        if (! $validation['valid']) {
            return $this->validationError($validation['errors']);
        }

        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        if (Schema::hasColumn($table, 'tenant_id')) {
            $payload = TenantContext::stamp($payload, $this->isEntityScoped($table));
        }

        $newId = DB::table($table)->insertGetId($payload);

        if ($table === 'departments') {
            $this->departmentManagerRoles->syncTenant((int) TenantContext::id());
        }

        $afterRow = DB::table($table)->where('id', $newId)->first();

        AuditLogger::log('create', $table, $newId, null, $afterRow ? (array) $afterRow : null);

        return response()->json([
            'status' => 201,
            'message' => Str::headline($resource).' created',
            'data' => $this->unpackMeta($afterRow, $table),
        ], 201);
    }

    public function update(Request $request, string $resource, int|string $id, ?string $child = null): JsonResponse
    {
        // Resolve route parameters by name (the route may supply some via defaults()).
        $resource = (string) $request->route('resource', $resource);
        $child = $request->route('child', $child);
        $id = (int) $request->route('id', $id);

        // Policy acknowledgment (kept for simple resource)
        if ($child === 'acknowledge') {
            $empId = $request->attributes->get('auth_employee_id');

            if ($empId !== null) {
                $now = now();
                DB::table('policy_acknowledgments')->updateOrInsert(
                    ['policy_id' => $id, 'employee_id' => $empId],
                    TenantContext::stamp(['acknowledged_at' => $now, 'ip_address' => $request->ip(), 'updated_at' => $now, 'created_at' => $now])
                );
            }

            return $this->ok(['id' => $id, 'acknowledged' => true], 'Policy acknowledged');
        }

        $table = $this->tableOrFail($resource);

        if ($table === 'shift_types' && ! $this->shiftRosterAccess->canManageShiftTypes($request)) {
            abort(403, 'Chỉ Admin hoặc HR được quản lý định nghĩa ca');
        }
        if ($table === 'shift_assignments') {
            $this->shiftRosterAccess->assertAssignment($request, $id);
            if ($request->filled('employee_id')) {
                $this->shiftRosterAccess->assertEmployee($request, (int) $request->input('employee_id'));
            }
        }

        if ($table === 'service_tickets' && ! $this->canManageServiceTickets($request)) {
            $ticket = DB::table($table)->where('id', $id)
                ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
                ->first(['requester_id', 'status']);
            $fields = array_keys($request->except(['id']));
            if (! $ticket
                || (int) $ticket->requester_id !== (int) $request->attributes->get('auth_employee_id')
                || strtolower((string) $ticket->status) !== 'pending'
                || $fields !== ['status'] || strtolower((string) $request->input('status')) !== 'cancelled') {
                return response()->json(['status' => 403, 'message' => 'Bạn chỉ có thể hủy ticket đang chờ của mình', 'data' => null], 403);
            }
        }

        if ($table === 'departments' && DB::getDriverName() === 'pgsql') {
            return DB::transaction(function () use ($request, $resource, $table, $id) {
                DB::select('SELECT pg_advisory_xact_lock(483072, CAST(? AS integer))', [(int) TenantContext::id()]);

                return $this->updateRecord($request, $resource, $table, $id);
            });
        }

        return $this->updateRecord($request, $resource, $table, $id);
    }

    private function updateRecord(Request $request, string $resource, string $table, int $id): JsonResponse
    {

        $payload = $this->payloadFor($request, $table, $id);

        if ($table === 'shift_assignments') {
            $payload['assigned_by'] = $this->shiftRosterAccess->actorId($request);
            $payload = $this->normalizeManualShiftAssignment($payload);
        }

        // parent_id may arrive either flat or inside meta; payloadFor normalizes both.
        if ($table === 'departments' && isset($payload['meta'])) {
            $departmentMeta = json_decode($payload['meta'], true) ?: [];
            $newParent = (int) ($departmentMeta['parent_id'] ?? 0);
            if ($newParent === (int) $id) {
                return $this->validationError(['parent_id' => ['Phòng ban không thể là cha của chính nó']]);
            }
            if ($newParent && $this->departmentAncestryContains($newParent, (int) $id)) {
                return $this->validationError(['parent_id' => ['Không thể chọn phòng ban con/cháu làm phòng ban cha (tạo vòng lặp)']]);
            }
            if (($errors = $this->departmentReferenceErrors($payload, $id)) !== []) {
                return $this->validationError($errors);
            }
        }

        // Validate business rules before updating
        $validation = ResourceBusinessRules::validateUpdate($table, $id, $payload);

        if (! $validation['valid']) {
            return $this->validationError($validation['errors']);
        }

        $payload['updated_at'] = now();

        $scoped = TenantContext::hasTenant() && Schema::hasColumn($table, 'tenant_id');
        $tenantId = TenantContext::id();

        $applyScope = function ($query) use ($scoped, $tenantId) {
            if ($scoped) {
                $query->where('tenant_id', $tenantId);
            }

            return $query;
        };

        $beforeRow = $applyScope(DB::table($table)->where('id', $id))->first();

        $updated = $applyScope(DB::table($table)->where('id', $id))->update($payload);

        if (! $updated && ! $applyScope(DB::table($table)->where('id', $id))->exists()) {
            return $this->notFound();
        }

        $afterRow = $applyScope(DB::table($table)->where('id', $id))->first();

        if ($beforeRow && $afterRow) {
            $diff = AuditLogger::diff((array) $beforeRow, (array) $afterRow);
            AuditLogger::log('update', $table, $id, $diff['before'], $diff['after']);
        }

        if ($table === 'departments') {
            $this->departmentManagerRoles->syncTenant((int) TenantContext::id());
        }

        return $this->ok($this->unpackMeta($afterRow, $table), Str::headline($resource).' updated');
    }

    public function destroy(Request $request, string $resource, int $id): JsonResponse
    {
        $table = $this->tableOrFail($resource);

        if ($table === 'shift_types' && ! $this->shiftRosterAccess->canManageShiftTypes($request)) {
            abort(403, 'Chỉ Admin hoặc HR được quản lý định nghĩa ca');
        }
        if ($table === 'shift_assignments') {
            $this->shiftRosterAccess->assertAssignment($request, $id);
        }

        if ($table === 'service_tickets') {
            return $this->conflict(['Ticket cần được chuyển trạng thái hủy để giữ lịch sử xử lý'], Str::headline($resource));
        }

        $scoped = TenantContext::hasTenant() && Schema::hasColumn($table, 'tenant_id');
        $tenantId = TenantContext::id();

        $applyScope = function ($query) use ($scoped, $tenantId) {
            if ($scoped) {
                $query->where('tenant_id', $tenantId);
            }

            return $query;
        };

        if (! $applyScope(DB::table($table)->where('id', $id))->exists()) {
            return $this->notFound();
        }

        // Check business rules before deleting
        $result = ResourceBusinessRules::canDelete($table, $id);

        if (! $result['can_delete']) {
            return $this->conflict($result['violations'], Str::headline($resource));
        }

        $beforeRow = $applyScope(DB::table($table)->where('id', $id))->first();

        $applyScope(DB::table($table)->where('id', $id))->delete();

        AuditLogger::log('delete', $table, $id, $beforeRow ? (array) $beforeRow : null, null);

        if ($table === 'departments') {
            $this->departmentManagerRoles->syncTenant((int) TenantContext::id());
        }

        return $this->ok(['id' => $id], Str::headline($resource).' deleted');
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════

    private function canManageServiceTickets(Request $request): bool
    {
        $access = (array) $request->attributes->get('access', []);

        return ! empty($access['full']) || in_array('communications', $access['modules'] ?? [], true);
    }

    private function normalizeManualShiftAssignment(array $payload, bool $creating = false): array
    {
        if ($creating || array_key_exists('is_day_off', $payload)) {
            $raw = $payload['is_day_off'] ?? false;
            if ($raw instanceof Expression) {
                $raw = $raw->getValue(DB::connection()->getQueryGrammar());
            }
            $isDayOff = in_array($raw, [true, 1, '1', 't', 'true', 'TRUE'], true);
            $payload['is_day_off'] = $isDayOff ? DB::raw('true') : DB::raw('false');
            if ($isDayOff) {
                $payload['shift_type_id'] = null;
            }
        }

        $meta = [];
        if (! empty($payload['meta'])) {
            $meta = is_string($payload['meta']) ? json_decode($payload['meta'], true) : (array) $payload['meta'];
            $meta = is_array($meta) ? $meta : [];
        }
        $meta['source'] = 'manual';
        $payload['meta'] = json_encode($meta, JSON_UNESCAPED_UNICODE);

        return $payload;
    }

    private function payloadFor(Request $request, string $table, ?int $id = null): array
    {
        $columns = Schema::getColumnListing($table);
        $payload = [];
        $meta = [];

        foreach ($request->except(['id', 'created_at', 'updated_at']) as $key => $value) {
            if (in_array($key, $columns, true)) {
                if (in_array(Schema::getColumnType($table, $key), ['boolean', 'bool'], true)) {
                    $payload[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? DB::raw('true') : DB::raw('false');
                } else {
                    $payload[$key] = $value;
                }
            } else {
                $meta[$key] = $value;
            }
        }

        // Keep legacy frontend field names compatible with canonical DB columns.
        if ($table === 'assets' && $request->filled('name') && ! $request->filled('asset_name')) {
            $payload['asset_name'] = $request->input('name');
        }
        if ($table === 'policies' && $request->filled('title') && ! $request->filled('policy_name')) {
            $payload['policy_name'] = $request->input('title');
        }

        if (in_array('meta', $columns, true)) {
            $existingMeta = [];
            if ($id) {
                $record = DB::table($table)->where('id', $id)->first();
                if ($record && isset($record->meta)) {
                    $existingMeta = is_string($record->meta) ? json_decode($record->meta, true) : (array) $record->meta;
                    if (! is_array($existingMeta)) {
                        $existingMeta = [];
                    }
                }
            }

            // Also check if request explicitly provided a 'meta' key
            $requestMeta = [];
            if ($request->has('meta')) {
                $reqMetaVal = $request->input('meta');
                $requestMeta = is_string($reqMetaVal) ? json_decode($reqMetaVal, true) : (array) $reqMetaVal;
                if (! is_array($requestMeta)) {
                    $requestMeta = [];
                }
            }

            $incomingMeta = array_merge($meta, $requestMeta);

            if ($table === 'departments') {
                if (array_key_exists('parent_id', $incomingMeta) || array_key_exists('parent_department_id', $incomingMeta)) {
                    $parent = array_key_exists('parent_id', $incomingMeta)
                        ? $incomingMeta['parent_id']
                        : $incomingMeta['parent_department_id'];
                    $parent = $this->departmentReferenceId($parent, 'parent_id', 'departments');
                    $incomingMeta['parent_id'] = $parent;
                    $incomingMeta['parent_department_id'] = $parent;
                }
                if (array_key_exists('manager_id', $incomingMeta)) {
                    $incomingMeta['manager_id'] = $this->departmentReferenceId($incomingMeta['manager_id'], 'manager_id', 'employees');
                }
                if (array_key_exists('unit_type', $incomingMeta)) {
                    $unitType = strtoupper(trim((string) $incomingMeta['unit_type']));
                    if (! in_array($unitType, ['DEPARTMENT', 'WORKSHOP', 'TEAM'], true)) {
                        throw ValidationException::withMessages(['unit_type' => ['Loại đơn vị không hợp lệ']]);
                    }
                    $incomingMeta['unit_type'] = $unitType;
                }
            }

            $mergedMeta = array_merge($existingMeta, $incomingMeta);

            if (! empty($mergedMeta)) {
                $payload['meta'] = json_encode($mergedMeta);
            }
        }

        return $payload;
    }

    private function departmentReferenceId(mixed $value, string $field, string $table): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ! TenantContext::ownsRow($table, $id)) {
            throw ValidationException::withMessages([$field => ['Giá trị tham chiếu không hợp lệ']]);
        }

        return $id;
    }

    /** @return array<string, array<int, string>> */
    private function departmentReferenceErrors(array $payload, ?int $departmentId = null): array
    {
        $meta = isset($payload['meta'])
            ? (is_string($payload['meta']) ? json_decode($payload['meta'], true) : (array) $payload['meta'])
            : [];
        $meta = is_array($meta) ? $meta : [];

        $requestedEntityId = isset($payload['legal_entity_id']) ? (int) $payload['legal_entity_id'] : 0;
        $legalEntityId = $requestedEntityId;
        if ($departmentId) {
            $currentEntityId = (int) DB::table('departments')
                ->where('id', $departmentId)
                ->where('tenant_id', TenantContext::id())
                ->value('legal_entity_id');
            if ($requestedEntityId && $requestedEntityId !== $currentEntityId) {
                return ['legal_entity_id' => ['Không thể đổi chi nhánh trực tiếp từ màn hình phòng ban']];
            }
            $legalEntityId = $currentEntityId;
        }
        if (! $legalEntityId) {
            $legalEntityId = (int) TenantContext::legalEntityId();
        }

        $errors = [];
        $entityExists = DB::table('legal_entities')
            ->where('id', $legalEntityId)
            ->where('tenant_id', TenantContext::id())
            ->exists();
        if (! $entityExists) {
            $errors['legal_entity_id'][] = 'Chi nhánh không thuộc công ty hiện tại';
        }
        $parentId = (int) ($meta['parent_id'] ?? 0);
        if ($parentId) {
            $parentEntityId = (int) DB::table('departments')
                ->where('id', $parentId)
                ->where('tenant_id', TenantContext::id())
                ->value('legal_entity_id');
            if ($parentEntityId !== $legalEntityId) {
                $errors['parent_id'][] = 'Đơn vị cha phải thuộc cùng chi nhánh';
            }
        }

        $managerId = (int) ($meta['manager_id'] ?? 0);
        if ($managerId) {
            $manager = DB::table('employees')
                ->where('id', $managerId)
                ->where('tenant_id', TenantContext::id())
                ->first(['legal_entity_id', 'status', 'profile']);
            if (! $manager || (int) $manager->legal_entity_id !== $legalEntityId) {
                $errors['manager_id'][] = 'Người phụ trách phải thuộc cùng chi nhánh';
            } elseif (! in_array(strtoupper((string) $manager->status), ['ACTIVE', 'PROBATION'], true)) {
                $errors['manager_id'][] = 'Người phụ trách phải đang làm việc hoặc thử việc';
            } else {
                $profile = is_string($manager->profile ?? null) ? json_decode($manager->profile, true) : (array) ($manager->profile ?? []);
                if (in_array($profile['system_account'] ?? false, [true, 1, '1', 't', 'true'], true)) {
                    $errors['manager_id'][] = 'Không thể chọn tài khoản hệ thống làm người phụ trách';
                }
            }
        }

        return $errors;
    }

    /**
     * Sensitive column names that must never be returned in API responses.
     * Matching is case-insensitive.
     */
    private const SECRET_COLUMNS = [
        'password',
        'password_hash',
        'remember_token',
        'token',
        'token_hash',
        'secret',
        'api_key',
    ];

    /**
     * Strip any sensitive columns from a single record (object or array).
     */
    private function hideSecrets(mixed $record): mixed
    {
        if (! $record) {
            return $record;
        }

        if (is_object($record)) {
            foreach (array_keys(get_object_vars($record)) as $key) {
                if (in_array(strtolower((string) $key), self::SECRET_COLUMNS, true)) {
                    unset($record->$key);
                }
            }
        } elseif (is_array($record)) {
            foreach (array_keys($record) as $key) {
                if (in_array(strtolower((string) $key), self::SECRET_COLUMNS, true)) {
                    unset($record[$key]);
                }
            }
        }

        return $record;
    }

    private function unpackMeta(mixed $record, ?string $table = null): mixed
    {
        if (! $record) {
            return $record;
        }

        $record = $this->hideSecrets($record);

        $isObject = is_object($record);
        $metaVal = $isObject ? ($record->meta ?? null) : ($record['meta'] ?? null);

        $metaData = [];
        if ($metaVal) {
            $metaData = is_string($metaVal) ? json_decode($metaVal, true) : (array) $metaVal;
            if (! is_array($metaData)) {
                $metaData = [];
            }
        }

        if ($table === 'departments') {
            foreach (['parent_id', 'parent_department_id', 'manager_id', 'unit_type'] as $key) {
                if (! array_key_exists($key, $metaData)) {
                    $metaData[$key] = $key === 'unit_type' ? 'DEPARTMENT' : null;
                }
            }
        }

        foreach ($metaData as $key => $value) {
            if ($isObject) {
                if (! property_exists($record, $key)) {
                    $record->$key = $value;
                }
            } else {
                if (! array_key_exists($key, $record)) {
                    $record[$key] = $value;
                }
            }
        }

        return $record;
    }

    /**
     * Walk up the department parent chain (parent_id lives in meta) starting at
     * $startParentId; return true if $target is reached — i.e. making $target a
     * child of $startParentId would create a cycle. Tenant-scoped + bounded.
     */
    private function departmentAncestryContains(int $startParentId, int $target): bool
    {
        $current = $startParentId;
        $guard = 0;
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        while ($current && $guard++ < 100) {
            if ($current === $target) {
                return true;
            }
            $row = DB::table('departments')
                ->where('id', $current)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();
            if (! $row) {
                break;
            }
            $meta = is_string($row->meta ?? null) ? json_decode($row->meta, true) : (array) ($row->meta ?? []);
            $current = (int) ($meta['parent_id'] ?? $meta['parent_department_id'] ?? 0);
        }

        return false;
    }

    private function tableOrFail(string $resource): string
    {
        $table = HrmTables::tableFor($resource);

        abort_unless($table && Schema::hasTable($table), 404, 'Resource not found');

        return $table;
    }

    /**
     * Tables that are entity-scoped (need legal_entity_id stamped in addition
     * to tenant_id). Kept in sync with the multi-tenant entity-scoped list.
     */
    private const ENTITY_SCOPED_TABLES = [
        'asset_locations',
        'assets',
        'attendances',
        'attendance_logs',
        'contracts',
        'departments',
        'employees',
        'insurance_claims',
        'payroll_adjustments',
        'salary_attendance_summary',
        'salary_breakdowns',
        'salary_details',
        'salary_periods',
        'shift_assignments',
        'shift_schedule_details',
        'shift_schedules',
        'social_insurance_info',
    ];

    private function isEntityScoped(string $table): bool
    {
        return in_array($table, self::ENTITY_SCOPED_TABLES, true);
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json([
            'status' => 404,
            'message' => $message,
            'data' => null,
        ], 404);
    }

    private function conflict(array $violations, string $resourceName): JsonResponse
    {
        return response()->json([
            'status' => 409,
            'message' => "Không thể xóa {$resourceName} do vi phạm ràng buộc nghiệp vụ",
            'data' => [
                'violations' => $violations,
            ],
        ], 409);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => [
                'errors' => $errors,
            ],
        ], 422);
    }
}

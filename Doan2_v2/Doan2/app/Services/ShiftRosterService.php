<?php

namespace App\Services;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShiftRosterService
{
    private const ROTATION_CODES = ['CA1', 'CA2', 'CA3'];

    private const GENERATED_SOURCES = ['rotation', 'roster-gen', 'excel-import'];

    public function __construct(
        private readonly ShiftResolver $resolver,
        private readonly ShiftRosterAccess $access,
        private readonly ShiftRosterWorkbook $workbook,
    ) {}

    public function calendar(Request $request, ?int $departmentId, string $weekStart): array
    {
        $weekStart = CarbonImmutable::parse($weekStart)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $departments = $this->access->accessibleDepartments($request);
        if ($departments === []) {
            abort(403, 'Tài khoản chưa được gán quản lý phòng ban nào');
        }

        $departmentId ??= (int) $departments[0]->id;
        $department = $this->access->department($request, $departmentId);
        $employees = $this->departmentEmployees($departmentId);
        $employeeIds = array_map(fn ($employee) => (int) $employee->id, $employees);
        $rangeStart = CarbonImmutable::parse($weekStart)->subWeek()->toDateString();
        $rangeEnd = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();
        $rows = $this->resolver->rowsForRange($employeeIds, $rangeStart, $rangeEnd, TenantContext::id());

        $eligible = [];
        $skipped = [];
        foreach ($employees as $employee) {
            $employeeRows = $rows[(int) $employee->id] ?? [];
            $base = $this->resolver->rotationBase($employeeRows, $weekStart);
            if (! $base['code']) {
                $skipped[] = [
                    'id' => (int) $employee->id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'reason' => $base['error'],
                ];

                continue;
            }

            $cells = [];
            for ($offset = 0; $offset < 7; $offset++) {
                $date = CarbonImmutable::parse($weekStart)->addDays($offset)->toDateString();
                $assignment = $this->resolver->resolveFromRows($employeeRows, $date);
                $cell = $this->resolver->cellForDate($assignment, $date);
                $cell['date'] = $date;
                $cell['override_assignment_id'] = $this->exactDayOverrideId($employeeRows, $date);
                $cells[] = $cell;
            }

            $eligible[] = [
                'id' => (int) $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'department_id' => (int) $employee->department_id,
                'base_shift_code' => $base['code'],
                'cells' => $cells,
            ];
        }

        return [
            'departments' => array_map(fn ($row) => $this->departmentPayload($row), $departments),
            'department' => $this->departmentPayload($department),
            'week_start' => $weekStart,
            'employees' => $eligible,
            'skipped_employees' => $skipped,
            'shift_types' => array_map(fn ($shift) => $this->shiftPayload($shift), $this->activeShifts()),
            'permissions' => [
                'manage_all_departments' => $this->access->canManageAll($request),
                'manage_shift_types' => $this->access->canManageShiftTypes($request),
            ],
        ];
    }

    /** @return array{path:string,filename:string,snapshot_hash:string} */
    public function template(Request $request, int $departmentId, string $weekStart): array
    {
        $weekStart = $this->validateFutureMonday($weekStart);
        $department = $this->access->department($request, $departmentId);
        $employees = $this->eligibleEmployees($departmentId, $weekStart);
        if ($employees === []) {
            throw ValidationException::withMessages([
                'employees' => ['Không có nhân viên CA1/CA2/CA3 hợp lệ để tạo mẫu'],
            ]);
        }

        return $this->workbook->create(
            $department,
            $weekStart,
            $employees,
            $this->activeShifts(),
            (int) TenantContext::id(),
        );
    }

    public function previewRotation(Request $request, array $input): array
    {
        $preview = $this->buildRotationPreview($request, $input);
        $token = $this->storePreview($request, 'rotation', [
            'input' => $preview['input'],
            'state_hash' => $preview['state_hash'],
            'employee_snapshot_hash' => $preview['employee_snapshot_hash'],
        ]);

        unset($preview['rows'], $preview['state_hash'], $preview['employee_snapshot_hash'], $preview['input']);
        $preview['preview_token'] = $token;
        $preview['expires_at'] = now()->addMinutes(30)->toIso8601String();

        return $preview;
    }

    public function applyRotation(Request $request, string $token, bool $overwriteManual): array
    {
        $cached = $this->getPreview($request, $token, 'rotation');
        $preview = $this->buildRotationPreview($request, $cached['input']);
        if (! hash_equals((string) $cached['employee_snapshot_hash'], (string) $preview['employee_snapshot_hash'])) {
            abort(409, 'Danh sách nhân viên đã thay đổi. Hãy preview lại trước khi áp dụng.');
        }
        if (! hash_equals((string) $cached['state_hash'], (string) $preview['state_hash'])) {
            abort(409, 'Lịch đã thay đổi sau khi preview. Hãy kiểm tra lại trước khi áp dụng.');
        }

        $batchId = (string) Str::uuid();
        $employeeIds = array_values(array_unique(array_column($preview['rows'], 'employee_id')));
        $range = $preview['range'];
        $manualConflicts = $preview['manual_conflicts'];

        DB::transaction(function () use ($request, $preview, $employeeIds, $range, $overwriteManual, $batchId): void {
            $this->removeGeneratedAssignments($employeeIds, $range['start'], $range['end']);
            if ($overwriteManual) {
                $this->removeManualAssignments($employeeIds, $range['start'], $range['end']);
            }

            $now = now();
            $actorId = $this->access->actorId($request);
            $rows = array_map(function ($row) use ($actorId, $batchId, $now): array {
                $row['assigned_by'] = $actorId;
                $row['meta'] = json_encode([
                    'source' => 'rotation',
                    'batch_id' => $batchId,
                    'department_id' => $row['department_id'],
                    'base_shift_code' => $row['base_shift_code'],
                    'week_index' => $row['week_index'],
                ], JSON_UNESCAPED_UNICODE);
                unset($row['department_id'], $row['base_shift_code'], $row['week_index']);
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $preview['rows']);

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('shift_assignments')->insert($chunk);
            }
        });

        Cache::forget($this->previewCacheKey($token));

        return [
            'batch_id' => $batchId,
            'employees' => count(array_unique($employeeIds)),
            'weeks' => $preview['weeks'],
            'assignments_created' => count($preview['rows']),
            'manual_conflicts_preserved' => $overwriteManual ? 0 : count($manualConflicts),
            'range' => [$range['start'], $range['end']],
        ];
    }

    public function previewImport(Request $request, int $departmentId, string $path): array
    {
        try {
            $parsed = $this->workbook->parse($path);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => [$exception->getMessage()]]);
        }

        $meta = $parsed['meta'];
        if ((int) ($meta['tenant_id'] ?? 0) !== (int) TenantContext::id()) {
            throw ValidationException::withMessages(['file' => ['File thuộc công ty khác']]);
        }
        if ((int) ($meta['department_id'] ?? 0) !== $departmentId) {
            throw ValidationException::withMessages(['file' => ['File không thuộc phòng ban đang chọn']]);
        }

        $department = $this->access->department($request, $departmentId);
        $weekStart = $this->validateFutureMonday((string) ($meta['week_start'] ?? ''));
        $employees = $this->eligibleEmployees($departmentId, $weekStart);
        $currentSnapshot = $this->workbook->employeeSnapshotHash($employees);
        $hiddenSnapshot = $this->workbook->employeeSnapshotHash($parsed['employees']);
        $expectedSnapshot = (string) ($meta['employee_snapshot_hash'] ?? '');

        if (! hash_equals($expectedSnapshot, $hiddenSnapshot)) {
            throw ValidationException::withMessages(['file' => ['Dữ liệu ẩn trong file đã bị thay đổi']]);
        }
        if (! hash_equals($expectedSnapshot, $currentSnapshot)) {
            throw ValidationException::withMessages([
                'file' => ['Danh sách nhân viên đã thay đổi. Hãy tải file mẫu mới trước khi xếp ca.'],
            ]);
        }

        $shiftMap = $this->shiftMapByCode();
        $employeeByCode = collect($employees)->keyBy(fn ($employee) => strtoupper((string) $employee->employee_code));
        $errors = [];
        $warnings = [];
        $entries = [];
        $seenCodes = [];

        foreach ($parsed['rows'] as $row) {
            $code = strtoupper(trim((string) $row['employee_code']));
            if ($code === '') {
                $errors[] = ['row' => $row['row'], 'message' => 'Thiếu mã nhân viên'];

                continue;
            }
            if (isset($seenCodes[$code])) {
                $errors[] = ['row' => $row['row'], 'message' => "Trùng mã nhân viên {$code}"];

                continue;
            }
            $seenCodes[$code] = true;

            $employee = $employeeByCode->get($code);
            if (! $employee) {
                $errors[] = ['row' => $row['row'], 'message' => "Mã {$code} không thuộc danh sách hợp lệ của phòng"];

                continue;
            }
            if (trim((string) $row['entered_name']) !== trim((string) $employee->full_name)) {
                $warnings[] = [
                    'row' => $row['row'],
                    'employee_code' => $code,
                    'message' => 'Họ tên trong file khác hệ thống; hệ thống sẽ dùng tên hiện tại',
                ];
            }

            foreach ($row['days'] as $offset => $rawCode) {
                $shiftCode = $this->normalizeImportedShiftCode($rawCode);
                if ($shiftCode === '') {
                    $errors[] = [
                        'row' => $row['row'],
                        'day' => $offset + 1,
                        'message' => 'Phải nhập mã ca hoặc OFF cho đủ bảy ngày',
                    ];

                    continue;
                }
                if ($shiftCode !== 'OFF' && ! isset($shiftMap[$shiftCode])) {
                    $errors[] = [
                        'row' => $row['row'],
                        'day' => $offset + 1,
                        'message' => "Mã ca {$rawCode} không tồn tại hoặc đã ngưng hoạt động",
                    ];

                    continue;
                }

                $date = CarbonImmutable::parse($weekStart)->addDays($offset)->toDateString();
                $entries[] = [
                    'employee_id' => (int) $employee->id,
                    'employee_code' => (string) $employee->employee_code,
                    'full_name' => (string) $employee->full_name,
                    'legal_entity_id' => (int) $employee->legal_entity_id,
                    'date' => $date,
                    'shift_code' => $shiftCode,
                    'shift_type_id' => $shiftCode === 'OFF' ? null : (int) $shiftMap[$shiftCode]->id,
                    'is_day_off' => $shiftCode === 'OFF',
                ];
            }
        }

        $expectedCodes = $employeeByCode->keys()->sort()->values()->all();
        $actualCodes = array_keys($seenCodes);
        sort($actualCodes);
        if ($expectedCodes !== $actualCodes) {
            $errors[] = ['row' => null, 'message' => 'Không được xóa hoặc thay đổi dòng nhân viên trong file mẫu'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'file' => array_map(fn ($error) => $this->formatImportError($error), $errors),
            ]);
        }

        $employeeIds = array_values(array_unique(array_column($entries, 'employee_id')));
        $weekEnd = CarbonImmutable::parse($weekStart)->addDays(6)->toDateString();
        $manualConflicts = $this->manualConflicts($employeeIds, $weekStart, $weekEnd);
        $currentRows = $this->resolver->rowsForRange(
            $employeeIds,
            $weekStart,
            $weekEnd,
            TenantContext::id(),
        );
        foreach ($entries as &$entry) {
            $current = $this->resolver->cellForDate(
                $this->resolver->resolveFromRows($currentRows[$entry['employee_id']] ?? [], $entry['date']),
                $entry['date'],
            );
            $entry['current_shift_code'] = $current['shift_code'];
            $entry['current_source'] = $current['source'];
            $entry['changed'] = $current['shift_code'] !== $entry['shift_code'];
        }
        unset($entry);
        $stateHash = $this->assignmentStateHash($employeeIds, $weekStart, $weekEnd);

        $token = $this->storePreview($request, 'import', [
            'department_id' => $departmentId,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'employee_snapshot_hash' => $currentSnapshot,
            'state_hash' => $stateHash,
            'entries' => $entries,
        ]);

        return [
            'preview_token' => $token,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
            'department' => $this->departmentPayload($department),
            'week_start' => $weekStart,
            'employees' => count($employees),
            'entries' => $entries,
            'warnings' => $warnings,
            'manual_conflicts' => $manualConflicts,
        ];
    }

    public function applyImport(Request $request, string $token, bool $overwriteManual): array
    {
        $cached = $this->getPreview($request, $token, 'import');
        $departmentId = (int) $cached['department_id'];
        $weekStart = (string) $cached['week_start'];
        $weekEnd = (string) $cached['week_end'];
        $this->access->department($request, $departmentId);

        $employees = $this->eligibleEmployees($departmentId, $weekStart);
        $snapshot = $this->workbook->employeeSnapshotHash($employees);
        if (! hash_equals((string) $cached['employee_snapshot_hash'], $snapshot)) {
            abort(409, 'Danh sách nhân viên đã thay đổi. Hãy tải mẫu và preview lại.');
        }

        $entries = $cached['entries'];
        $employeeIds = array_values(array_unique(array_column($entries, 'employee_id')));
        $stateHash = $this->assignmentStateHash($employeeIds, $weekStart, $weekEnd);
        if (! hash_equals((string) $cached['state_hash'], $stateHash)) {
            abort(409, 'Lịch đã thay đổi sau khi preview. Hãy kiểm tra lại trước khi áp dụng.');
        }

        $manualConflicts = $this->manualConflicts($employeeIds, $weekStart, $weekEnd);
        $manualDates = $this->manualConflictDateSet($manualConflicts, $weekStart, $weekEnd);
        $batchId = (string) Str::uuid();
        $created = 0;
        $preserved = 0;

        DB::transaction(function () use (
            $request,
            $employeeIds,
            $weekStart,
            $weekEnd,
            $overwriteManual,
            $entries,
            $manualDates,
            $departmentId,
            $batchId,
            &$created,
            &$preserved
        ): void {
            $this->removeGeneratedAssignments($employeeIds, $weekStart, $weekEnd);
            if ($overwriteManual) {
                $this->removeManualAssignments($employeeIds, $weekStart, $weekEnd);
            }

            $actorId = $this->access->actorId($request);
            $now = now();
            $rows = [];
            foreach ($entries as $entry) {
                $key = $entry['employee_id'].'|'.$entry['date'];
                if (! $overwriteManual && isset($manualDates[$key])) {
                    $preserved++;

                    continue;
                }

                $rows[] = [
                    'tenant_id' => TenantContext::id(),
                    'legal_entity_id' => $entry['legal_entity_id'],
                    'employee_id' => $entry['employee_id'],
                    'shift_type_id' => $entry['shift_type_id'],
                    'is_day_off' => $entry['is_day_off'] ? DB::raw('true') : DB::raw('false'),
                    'effective_date' => $entry['date'],
                    'expiry_date' => $entry['date'],
                    'is_permanent' => DB::raw('false'),
                    'assigned_by' => $actorId,
                    'notes' => 'Lịch xếp ca nhập từ Excel',
                    'status' => 'ACTIVE',
                    'meta' => json_encode([
                        'source' => 'excel-import',
                        'batch_id' => $batchId,
                        'department_id' => $departmentId,
                        'shift_code' => $entry['shift_code'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('shift_assignments')->insert($chunk);
            }
            $created = count($rows);
        });

        Cache::forget($this->previewCacheKey($token));

        return [
            'batch_id' => $batchId,
            'employees' => count($employeeIds),
            'assignments_created' => $created,
            'manual_cells_preserved' => $preserved,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
        ];
    }

    private function buildRotationPreview(Request $request, array $input): array
    {
        $departmentId = (int) ($input['department_id'] ?? 0);
        $department = $this->access->department($request, $departmentId);
        $weekStart = $this->validateFutureMonday((string) ($input['start_date'] ?? ''));
        $weeks = max(1, min(26, (int) ($input['weeks'] ?? 12)));
        $employees = $this->departmentEmployees($departmentId);
        $employeeSnapshotHash = $this->workbook->employeeSnapshotHash($employees);
        $selectedIds = array_values(array_unique(array_map('intval', $input['employee_ids'] ?? [])));
        if ($selectedIds !== []) {
            $availableIds = array_map(fn ($employee) => (int) $employee->id, $employees);
            if (array_diff($selectedIds, $availableIds) !== []) {
                throw ValidationException::withMessages([
                    'employee_ids' => ['Danh sách có nhân viên không thuộc phòng ban hoặc không còn hoạt động'],
                ]);
            }
            $employees = array_values(array_filter(
                $employees,
                fn ($employee) => in_array((int) $employee->id, $selectedIds, true)
            ));
        }

        if ($employees === []) {
            throw ValidationException::withMessages(['employee_ids' => ['Không có nhân viên để xếp ca']]);
        }

        $shiftMap = $this->shiftMapByCode();
        foreach (self::ROTATION_CODES as $code) {
            if (! isset($shiftMap[$code])) {
                throw ValidationException::withMessages(['shift_types' => ["Thiếu ca hoạt động {$code}"]]);
            }
        }

        $employeeIds = array_map(fn ($employee) => (int) $employee->id, $employees);
        $endDate = CarbonImmutable::parse($weekStart)->addDays($weeks * 7 - 1)->toDateString();
        $resolverRows = $this->resolver->rowsForRange(
            $employeeIds,
            CarbonImmutable::parse($weekStart)->subWeek()->toDateString(),
            $endDate,
            TenantContext::id(),
        );

        $rows = [];
        $transitions = [];
        $skipped = [];
        foreach ($employees as $employee) {
            $base = $this->resolver->rotationBase($resolverRows[(int) $employee->id] ?? [], $weekStart);
            if (! $base['code']) {
                $skipped[] = [
                    'id' => (int) $employee->id,
                    'employee_code' => $employee->employee_code,
                    'full_name' => $employee->full_name,
                    'reason' => $base['error'],
                ];

                continue;
            }

            $firstShift = $this->nextRotationCode($base['code'], 1);
            $transitions[] = [
                'id' => (int) $employee->id,
                'employee_code' => $employee->employee_code,
                'full_name' => $employee->full_name,
                'from' => $base['code'],
                'to' => $firstShift,
            ];

            for ($week = 0; $week < $weeks; $week++) {
                $code = $this->nextRotationCode($base['code'], $week + 1);
                $effective = CarbonImmutable::parse($weekStart)->addWeeks($week);
                $rows[] = [
                    'tenant_id' => TenantContext::id(),
                    'legal_entity_id' => (int) $employee->legal_entity_id,
                    'employee_id' => (int) $employee->id,
                    'shift_type_id' => (int) $shiftMap[$code]->id,
                    'is_day_off' => DB::raw('false'),
                    'effective_date' => $effective->toDateString(),
                    'expiry_date' => $effective->addDays(6)->toDateString(),
                    'is_permanent' => DB::raw('false'),
                    'notes' => 'Lịch ca xoay tự động',
                    'status' => 'ACTIVE',
                    'department_id' => $departmentId,
                    'base_shift_code' => $base['code'],
                    'week_index' => $week + 1,
                ];
            }
        }

        if ($rows === []) {
            throw ValidationException::withMessages([
                'employee_ids' => ['Không có nhân viên xác định được ca gốc CA1/CA2/CA3'],
            ]);
        }

        $validEmployeeIds = array_values(array_unique(array_column($rows, 'employee_id')));
        $manualConflicts = $this->manualConflicts($validEmployeeIds, $weekStart, $endDate);
        $stateHash = $this->assignmentStateHash($validEmployeeIds, $weekStart, $endDate);

        return [
            'input' => [
                'department_id' => $departmentId,
                'start_date' => $weekStart,
                'weeks' => $weeks,
                'employee_ids' => array_map(fn ($employee) => (int) $employee->id, $employees),
            ],
            'department' => $this->departmentPayload($department),
            'weeks' => $weeks,
            'range' => ['start' => $weekStart, 'end' => $endDate],
            'employees' => count($transitions),
            'assignments' => count($rows),
            'transitions' => $transitions,
            'skipped_employees' => $skipped,
            'manual_conflicts' => $manualConflicts,
            'rows' => $rows,
            'state_hash' => $stateHash,
            'employee_snapshot_hash' => $employeeSnapshotHash,
        ];
    }

    /** @return array<int, object> */
    private function eligibleEmployees(int $departmentId, string $weekStart): array
    {
        $employees = $this->departmentEmployees($departmentId);
        $employeeIds = array_map(fn ($employee) => (int) $employee->id, $employees);
        $rows = $this->resolver->rowsForRange(
            $employeeIds,
            CarbonImmutable::parse($weekStart)->subWeek()->toDateString(),
            CarbonImmutable::parse($weekStart)->subDay()->toDateString(),
            TenantContext::id(),
        );

        return array_values(array_filter($employees, function ($employee) use ($rows, $weekStart): bool {
            $base = $this->resolver->rotationBase($rows[(int) $employee->id] ?? [], $weekStart);

            return in_array($base['code'], self::ROTATION_CODES, true);
        }));
    }

    /** @return array<int, object> */
    private function departmentEmployees(int $departmentId): array
    {
        return DB::table('employees')
            ->where('tenant_id', TenantContext::id())
            ->where('department_id', $departmentId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->orderBy('employee_code')
            ->get(['id', 'employee_code', 'full_name', 'department_id', 'legal_entity_id', 'status', 'profile'])
            ->filter(function ($employee): bool {
                $profile = $this->resolver->decodeMeta($employee->profile ?? null);

                return empty($profile['system_account']);
            })
            ->values()
            ->all();
    }

    /** @return array<int, object> */
    private function activeShifts(): array
    {
        return DB::table('shift_types')
            ->where('tenant_id', TenantContext::id())
            ->orderBy('shift_code')
            ->get()
            ->filter(fn ($shift) => $this->isActiveStatus($shift->status ?? true))
            ->map(function ($shift): object {
                $meta = $this->resolver->decodeMeta($shift->meta ?? null);
                foreach ($meta as $key => $value) {
                    if (! property_exists($shift, $key)) {
                        $shift->$key = $value;
                    }
                }

                return $shift;
            })
            ->values()
            ->all();
    }

    /** @return array<string, object> */
    private function shiftMapByCode(): array
    {
        $map = [];
        foreach ($this->activeShifts() as $shift) {
            $map[strtoupper((string) $shift->shift_code)] = $shift;
        }

        return $map;
    }

    /** @return array<int, array<string, mixed>> */
    private function manualConflicts(array $employeeIds, string $startDate, string $endDate): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $employeeMap = DB::table('employees')
            ->whereIn('id', $employeeIds)
            ->get(['id', 'employee_code', 'full_name'])
            ->keyBy('id');

        return DB::table('shift_assignments')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('expiry_date')
            ->whereDate('effective_date', '<=', $endDate)
            ->whereDate('expiry_date', '>=', $startDate)
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', '!=', 'INACTIVE');
            })
            ->get()
            ->filter(fn ($assignment): bool => $this->resolver->isManualAssignment($assignment))
            ->map(function ($assignment) use ($employeeMap): array {
                $employee = $employeeMap->get($assignment->employee_id);

                return [
                    'assignment_id' => (int) $assignment->id,
                    'employee_id' => (int) $assignment->employee_id,
                    'employee_code' => $employee->employee_code ?? null,
                    'full_name' => $employee->full_name ?? null,
                    'effective_date' => CarbonImmutable::parse($assignment->effective_date)->toDateString(),
                    'expiry_date' => CarbonImmutable::parse($assignment->expiry_date)->toDateString(),
                    'source' => $this->resolver->assignmentSource($assignment),
                ];
            })
            ->values()
            ->all();
    }

    private function assignmentStateHash(array $employeeIds, string $startDate, string $endDate): string
    {
        $rows = DB::table('shift_assignments')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_date', '<=', $endDate)
            ->where(function ($query) use ($startDate): void {
                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $startDate);
            })
            ->orderBy('id')
            ->get(['id', 'employee_id', 'shift_type_id', 'is_day_off', 'effective_date', 'expiry_date', 'status', 'meta', 'updated_at'])
            ->map(fn ($row) => (array) $row)
            ->all();

        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function removeGeneratedAssignments(array $employeeIds, string $startDate, string $endDate): void
    {
        $this->removeAssignmentsWithinRange($employeeIds, $startDate, $endDate, true);
    }

    private function removeManualAssignments(array $employeeIds, string $startDate, string $endDate): void
    {
        $this->removeAssignmentsWithinRange($employeeIds, $startDate, $endDate, false);
    }

    private function removeAssignmentsWithinRange(
        array $employeeIds,
        string $startDate,
        string $endDate,
        bool $generated
    ): void {
        $rows = DB::table('shift_assignments')
            ->where('tenant_id', TenantContext::id())
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('expiry_date')
            ->whereDate('effective_date', '<=', $endDate)
            ->whereDate('expiry_date', '>=', $startDate)
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $isGenerated = in_array($this->resolver->assignmentSource($row), self::GENERATED_SOURCES, true);
            if ($isGenerated !== $generated) {
                continue;
            }

            $this->subtractRange($row, $startDate, $endDate);
        }
    }

    private function subtractRange(object $row, string $startDate, string $endDate): void
    {
        $rowStart = CarbonImmutable::parse($row->effective_date);
        $rowEnd = CarbonImmutable::parse($row->expiry_date);
        $removeStart = CarbonImmutable::parse($startDate);
        $removeEnd = CarbonImmutable::parse($endDate);

        if ($rowStart->gte($removeStart) && $rowEnd->lte($removeEnd)) {
            DB::table('shift_assignments')->where('id', $row->id)->delete();

            return;
        }

        if ($rowStart->lt($removeStart) && $rowEnd->gt($removeEnd)) {
            DB::table('shift_assignments')->where('id', $row->id)->update([
                'expiry_date' => $removeStart->subDay()->toDateString(),
                'updated_at' => now(),
            ]);

            $copy = (array) $row;
            unset($copy['id']);
            $copy['legacy_id'] = null;
            $copy['effective_date'] = $removeEnd->addDay()->toDateString();
            $copy['created_at'] = now();
            $copy['updated_at'] = now();
            DB::table('shift_assignments')->insert($copy);

            return;
        }

        if ($rowStart->lt($removeStart)) {
            DB::table('shift_assignments')->where('id', $row->id)->update([
                'expiry_date' => $removeStart->subDay()->toDateString(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('shift_assignments')->where('id', $row->id)->update([
            'effective_date' => $removeEnd->addDay()->toDateString(),
            'updated_at' => now(),
        ]);
    }

    private function manualConflictDateSet(array $conflicts, string $startDate, string $endDate): array
    {
        $set = [];
        $rangeStart = CarbonImmutable::parse($startDate);
        $rangeEnd = CarbonImmutable::parse($endDate);
        foreach ($conflicts as $conflict) {
            $start = CarbonImmutable::parse($conflict['effective_date'])->max($rangeStart);
            $end = CarbonImmutable::parse($conflict['expiry_date'])->min($rangeEnd);
            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $set[$conflict['employee_id'].'|'.$date->toDateString()] = true;
            }
        }

        return $set;
    }

    private function storePreview(Request $request, string $type, array $payload): string
    {
        $token = (string) Str::uuid();
        Cache::put($this->previewCacheKey($token), [
            'type' => $type,
            'tenant_id' => TenantContext::id(),
            'actor_id' => $this->access->actorId($request),
        ] + $payload, now()->addMinutes(30));

        return $token;
    }

    private function getPreview(Request $request, string $token, string $type): array
    {
        $cached = Cache::get($this->previewCacheKey($token));
        if (! is_array($cached)
            || ($cached['type'] ?? null) !== $type
            || (int) ($cached['tenant_id'] ?? 0) !== (int) TenantContext::id()
            || (int) ($cached['actor_id'] ?? 0) !== $this->access->actorId($request)) {
            throw ValidationException::withMessages([
                'preview_token' => ['Preview không tồn tại, đã hết hạn hoặc không thuộc tài khoản hiện tại'],
            ]);
        }

        return $cached;
    }

    private function previewCacheKey(string $token): string
    {
        return 'shift_roster_preview:'.$token;
    }

    private function validateFutureMonday(string $date): string
    {
        try {
            $parsed = CarbonImmutable::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['start_date' => ['Ngày bắt đầu không hợp lệ']]);
        }

        if ($parsed->dayOfWeekIso !== 1) {
            throw ValidationException::withMessages(['start_date' => ['Tuần bắt đầu phải là Thứ Hai']]);
        }

        $nextMonday = CarbonImmutable::today()->next(CarbonImmutable::MONDAY);
        if ($parsed->lt($nextMonday)) {
            throw ValidationException::withMessages([
                'start_date' => ['Chỉ được xếp lịch từ Thứ Hai tuần kế tiếp trở đi'],
            ]);
        }

        return $parsed->toDateString();
    }

    private function nextRotationCode(string $baseCode, int $offset): string
    {
        $index = array_search(strtoupper($baseCode), self::ROTATION_CODES, true);
        if ($index === false) {
            throw new \InvalidArgumentException("Mã ca {$baseCode} không thuộc vòng xoay");
        }

        return self::ROTATION_CODES[($index + $offset) % count(self::ROTATION_CODES)];
    }

    private function normalizeImportedShiftCode(string $code): string
    {
        $code = strtoupper(trim($code));

        return ['S1' => 'CA1', 'S2' => 'CA2', 'S3' => 'CA3'][$code] ?? $code;
    }

    private function exactDayOverrideId(array $rows, string $date): ?int
    {
        $exact = array_values(array_filter($rows, function ($row) use ($date): bool {
            return ! empty($row->expiry_date)
                && CarbonImmutable::parse($row->effective_date)->toDateString() === $date
                && CarbonImmutable::parse($row->expiry_date)->toDateString() === $date;
        }));
        $resolved = $this->resolver->resolveFromRows($exact, $date);

        return $resolved ? (int) $resolved->id : null;
    }

    private function isActiveStatus(mixed $value): bool
    {
        return ! in_array($value, [false, 0, '0', 'f', 'false', 'FALSE', 'INACTIVE'], true);
    }

    private function departmentPayload(object $department): array
    {
        $meta = $this->resolver->decodeMeta($department->meta ?? null);

        return [
            'id' => (int) $department->id,
            'code' => (string) ($department->department_code ?? ''),
            'name' => (string) ($department->department_name ?? ''),
            'manager_id' => isset($meta['manager_id']) ? (int) $meta['manager_id'] : null,
        ];
    }

    private function shiftPayload(object $shift): array
    {
        return [
            'id' => (int) $shift->id,
            'shift_code' => (string) $shift->shift_code,
            'shift_name' => (string) $shift->shift_name,
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'color_code' => $shift->color_code ?? '#64748b',
            'work_weekdays' => $shift->work_weekdays ?? null,
        ];
    }

    private function formatImportError(array $error): string
    {
        $prefix = $error['row'] ? 'Dòng '.$error['row'] : 'File';
        if (! empty($error['day'])) {
            $prefix .= ', ngày '.$error['day'];
        }

        return $prefix.': '.$error['message'];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LegalEntity;
use App\Repositories\OrganizationChartRepository;
use App\Support\EmployeeStatus;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * POST /employees/import — Nhập nhân viên hàng loạt từ file Excel/CSV.
     *
     * FE parse CSV phía client rồi gửi JSON rows (không cần multipart/phpspreadsheet).
     * Mỗi dòng độc lập: dòng lỗi bị bỏ qua kèm lý do, dòng hợp lệ vẫn vào — HR import
     * 200 người mà 3 dòng lỗi thì 197 người vẫn được tạo, sửa 3 dòng import lại
     * (chống trùng theo mã NV/email nên chạy lại an toàn).
     *
     * Mỗi NV tạo kèm: hợp đồng hiệu lực (payroll cần contract), phòng ban/chức danh
     * find-or-create theo TÊN (file Excel của khách ghi tên, không có id).
     */
    public function import(Request $request): JsonResponse
    {
        $rows = $request->input('rows');
        if (! is_array($rows) || ! count($rows) || count($rows) > 2000) {
            return $this->validationError(['rows' => ['Cần danh sách 1-2000 dòng nhân viên']]);
        }

        $tenantId = (int) TenantContext::id();
        $legalEntityId = (int) (DB::table('legal_entities')->where('tenant_id', $tenantId)->min('id') ?: 1);
        $contractTypeId = DB::table('contract_types')->where('tenant_id', $tenantId)
            ->where('contract_type_code', 'HDLD01')->value('id')
            ?? DB::table('contract_types')->where('tenant_id', $tenantId)->min('id');

        // Mã NV tự sinh: tiếp nối số NVxxxx lớn nhất hiện có.
        $maxCode = (int) DB::table('employees')->where('tenant_id', $tenantId)
            ->where('employee_code', '~', '^NV[0-9]+$')
            ->selectRaw("max(substring(employee_code from 3)::int) m")->value('m');

        $deptCache = [];
        $posCache = [];
        $findOrCreate = function (string $table, string $nameCol, string $codeCol, string $name, array &$cache) use ($tenantId, $legalEntityId) {
            $key = mb_strtolower(trim($name));
            if (isset($cache[$key])) {
                return $cache[$key];
            }
            $id = DB::table($table)->where('tenant_id', $tenantId)->whereRaw("lower({$nameCol}) = ?", [$key])->value('id');
            if (! $id) {
                $row = [
                    $codeCol => mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', $this->viSlug($name)), 0, 12)) ?: strtoupper(substr(md5($key), 0, 8)),
                    $nameCol => trim($name),
                    'tenant_id' => $tenantId,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                if (Schema::hasColumn($table, 'legal_entity_id')) {
                    $row['legal_entity_id'] = $legalEntityId;
                }
                if (Schema::hasColumn($table, 'status')) {
                    $row['status'] = DB::raw('true');
                }
                $id = DB::table($table)->insertGetId($row);
            }

            return $cache[$key] = (int) $id;
        };

        $created = 0;
        $results = [];
        foreach (array_values($rows) as $i => $r) {
            $line = $i + 2; // dòng Excel (sau header)
            $name = trim((string) ($r['full_name'] ?? ''));
            if ($name === '') {
                $results[] = ['line' => $line, 'status' => 'skipped', 'reason' => 'Thiếu họ tên'];

                continue;
            }

            $code = strtoupper(trim((string) ($r['employee_code'] ?? ''))) ?: 'NV'.str_pad((string) (++$maxCode), 4, '0', STR_PAD_LEFT);
            $email = strtolower(trim((string) ($r['company_email'] ?? '')));

            $dup = DB::table('employees')->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('employee_code', $code)->when($email, fn ($q2) => $q2->orWhere('company_email', $email)))
                ->first(['employee_code', 'company_email']);
            if ($dup) {
                $results[] = ['line' => $line, 'status' => 'skipped', 'reason' => "Trùng mã NV/email ({$dup->employee_code})"];

                continue;
            }

            $g = mb_strtoupper(trim((string) ($r['gender'] ?? '')));
            $gender = in_array($g, ['MALE', 'M', 'NAM'], true) ? 'MALE' : (in_array($g, ['FEMALE', 'F', 'NỮ', 'NU'], true) ? 'FEMALE' : null);
            $hire = $this->parseDateCell($r['hire_date'] ?? null) ?? now()->toDateString();
            $dob = $this->parseDateCell($r['date_of_birth'] ?? null);
            $salary = (float) preg_replace('/[^0-9.]/', '', (string) ($r['base_salary'] ?? 0));

            // Pre-check các ràng buộc DB để báo lỗi ĐỌC ĐƯỢC thay vì SQLSTATE.
            if ($hire > now()->addDays(7)->toDateString()) {
                $results[] = ['line' => $line, 'status' => 'skipped',
                    'reason' => "Ngày vào làm {$hire} quá xa tương lai (hệ thống cho tối đa hôm nay +7 ngày) — import lại gần ngày nhận việc"];

                continue;
            }
            if (! preg_match('/^[A-Z]{2,4}[0-9]{4,8}$/', $code)) {
                $results[] = ['line' => $line, 'status' => 'skipped', 'reason' => "Mã NV '{$code}' sai định dạng (2-4 chữ + 4-8 số, vd NV0123)"];

                continue;
            }

            try {
                DB::transaction(function () use ($r, $name, $code, $email, $gender, $hire, $dob, $salary, $tenantId, $legalEntityId, $contractTypeId, $findOrCreate, &$deptCache, &$posCache) {
                    $deptId = trim((string) ($r['department'] ?? '')) !== ''
                        ? $findOrCreate('departments', 'department_name', 'department_code', $r['department'], $deptCache) : null;
                    $posId = trim((string) ($r['position'] ?? '')) !== ''
                        ? $findOrCreate('positions', 'position_name', 'position_code', $r['position'], $posCache) : null;

                    $profile = array_filter([
                        'id_number' => trim((string) ($r['id_number'] ?? '')) ?: null,
                        'tax_number' => trim((string) ($r['tax_number'] ?? '')) ?: null,
                        'insurance_number' => trim((string) ($r['insurance_number'] ?? '')) ?: null,
                        'bank_name' => trim((string) ($r['bank_name'] ?? '')) ?: null,
                        'bank_account' => trim((string) ($r['bank_account'] ?? '')) ?: null,
                        'address' => trim((string) ($r['address'] ?? '')) ?: null,
                        'source' => 'excel-import',
                    ]);

                    $empId = DB::table('employees')->insertGetId([
                        'employee_code' => $code,
                        'full_name' => $name,
                        'company_email' => $email ?: null,
                        'password_hash' => null, // HR cấp mật khẩu sau — chưa đăng nhập được
                        'status' => 'ACTIVE',
                        'gender' => $gender,
                        'date_of_birth' => $dob,
                        'phone_number' => trim((string) ($r['phone_number'] ?? '')) ?: null,
                        'department_id' => $deptId,
                        'position_id' => $posId,
                        'base_salary' => $salary ?: null,
                        'hire_date' => $hire,
                        'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                        'tenant_id' => $tenantId,
                        'legal_entity_id' => $legalEntityId,
                        'is_super_admin' => DB::raw('false'),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);

                    DB::table('contracts')->insert([
                        'tenant_id' => $tenantId, 'legal_entity_id' => $legalEntityId,
                        'employee_id' => $empId, 'contract_type_id' => $contractTypeId,
                        'department_id' => $deptId, 'position_id' => $posId,
                        'contract_number' => 'HDLD/'.$code.'/'.date('Y'),
                        'status' => 'CÓ_HIỆU_LỰC', 'start_date' => $hire, 'end_date' => null,
                        'meta' => json_encode(['basic_salary' => $salary, 'source' => 'excel-import'], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
                $created++;
                $results[] = ['line' => $line, 'status' => 'created', 'employee_code' => $code, 'full_name' => $name];
            } catch (\Throwable $e) {
                // Không lộ SQL ra HR — quy về thông điệp ngắn.
                $msg = str_contains($e->getMessage(), 'SQLSTATE')
                    ? 'Dữ liệu không hợp lệ với ràng buộc hệ thống (kiểm tra mã NV, ngày, lương)'
                    : mb_substr($e->getMessage(), 0, 120);
                $results[] = ['line' => $line, 'status' => 'skipped', 'reason' => $msg];
            }
        }

        // Cấp số dư phép năm hiện tại cho người mới (idempotent).
        if ($created > 0) {
            try {
                app(\App\Services\LeavePolicyService::class)->recomputeBalances($tenantId, (int) now()->year);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Import: recomputeBalances failed: '.$e->getMessage());
            }
        }

        return $this->ok([
            'created' => $created,
            'skipped' => count($results) - $created,
            'results' => $results,
        ], "Đã nhập {$created} nhân viên");
    }

    /** Ngày từ ô Excel: nhận d/m/Y, Y-m-d, d-m-Y. */
    private function parseDateCell($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $v);
            if ($d && $d->format($fmt) === $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Bỏ dấu tiếng Việt để sinh mã code từ tên. */
    private function viSlug(string $s): string
    {
        $from = ['à','á','ả','ã','ạ','ă','ằ','ắ','ẳ','ẵ','ặ','â','ầ','ấ','ẩ','ẫ','ậ','đ','è','é','ẻ','ẽ','ẹ','ê','ề','ế','ể','ễ','ệ','ì','í','ỉ','ĩ','ị','ò','ó','ỏ','õ','ọ','ô','ồ','ố','ổ','ỗ','ộ','ơ','ờ','ớ','ở','ỡ','ợ','ù','ú','ủ','ũ','ụ','ư','ừ','ứ','ử','ữ','ự','ỳ','ý','ỷ','ỹ','ỵ'];
        $to = ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','d','e','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','u','u','y','y','y','y','y'];

        return str_replace($from, $to, mb_strtolower($s));
    }

    /**
     * GET /employees — Danh sách nhân viên kèm filter, enriched data.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 2000);

        $query = Employee::query()
            ->with(['department:id,department_name,department_code', 'position:id,position_name,position_code'])
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->orderByDesc('id');

        // Filter by query params
        $filterable = ['department_id', 'position_id', 'status', 'employee_code'];
        foreach ($filterable as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        // Search by name or email
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('company_email', 'ilike', "%{$search}%")
                    ->orWhere('employee_code', 'ilike', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);

        // Trạng thái hiển thị = suy ra từ HĐ + đơn nghỉ (nhất quán toàn hệ thống).
        $items = $page->items();
        $statuses = EmployeeStatus::resolveMany($items);
        foreach ($items as $emp) {
            $emp->status = $statuses[(int) $emp->id];
        }

        // Danh bạ nhân viên là SHARED_READ (mọi NV xem được để tra tên/liên hệ),
        // nhưng LƯƠNG + hồ sơ cá nhân (CMND, ngày sinh…) chỉ dành cho vai trò
        // HR/Payroll. Người xem thường → ẩn các cột nhạy cảm khỏi kết quả list.
        $access = $request->attributes->get('access');
        $privileged = is_array($access) && (! empty($access['full'])
            || array_intersect(['hr', 'payroll'], $access['modules'] ?? []));
        if (! $privileged) {
            foreach ($items as $emp) {
                unset($emp->base_salary, $emp->profile, $emp->personal_email, $emp->date_of_birth);
            }
        }

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Employees list');
    }

    /** Lightweight data for selectors; never returns profile or salary fields. */
    public function lookup(): JsonResponse
    {
        $employees = Employee::query()
            ->select(['id', 'employee_code', 'full_name', 'status', 'department_id', 'position_id', 'manager_id'])
            ->where(fn ($query) => $query->whereNull('profile->system_account')->orWhere('profile->system_account', false))
            ->orderBy('full_name')
            ->get();

        $statuses = EmployeeStatus::resolveMany($employees);
        foreach ($employees as $employee) {
            $employee->status = $statuses[(int) $employee->id];
        }

        return $this->ok($employees, 'Employee lookup');
    }

    public function orgChart(Request $request): JsonResponse
    {
        $repo = new OrganizationChartRepository;
        $rootId = $request->query('root_id') ? (int) $request->query('root_id') : null;

        // Trả về dạng cây phân cấp lồng nhau (nested tree)
        $tree = $repo->getNestedTree($rootId);

        return $this->ok($tree, 'Organization Chart');
    }

    /**
     * POST /employees — Tạo nhân viên mới.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('status') && is_string($request->input('status'))) {
            $request->merge(['status' => strtoupper($request->input('status'))]);
        }

        if ($request->has('gender') && is_string($request->input('gender'))) {
            $gender = trim($request->input('gender'));
            $upperGender = mb_strtoupper($gender, 'UTF-8');
            if (in_array($upperGender, ['MALE', 'M', 'NAM'])) {
                $request->merge(['gender' => 'MALE']);
            } elseif (in_array($upperGender, ['FEMALE', 'F', 'NỮ'])) {
                $request->merge(['gender' => 'FEMALE']);
            } elseif (in_array($upperGender, ['OTHER', 'O'])) {
                $request->merge(['gender' => 'OTHER']);
            }
        }

        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'company_email' => [
                'required',
                'email',
                Rule::unique('employees', 'company_email')->where('tenant_id', TenantContext::id()),
            ],
            'employee_code' => [
                'nullable',
                'string',
                Rule::unique('employees', 'employee_code')->where('tenant_id', TenantContext::id()),
                'regex:/^[A-Z]{2,4}[0-9]{4,8}$/',
            ],
            'phone' => 'nullable|string|max:20',
            'phone_number' => 'nullable|string|max:20',
            'personal_email' => 'nullable|email',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'profile' => 'nullable|array',
            'hire_date' => 'nullable|date',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,ON_LEAVE,TERMINATED,PROBATION',
        ], [
            'full_name.required' => 'Họ tên nhân viên là bắt buộc',
            'company_email.required' => 'Email công ty là bắt buộc',
            'company_email.unique' => 'Email công ty đã tồn tại',
            'employee_code.unique' => 'Mã nhân viên đã tồn tại',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'position_id.exists' => 'Chức vụ không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Entity targeting: if a legal_entity_id is supplied, it must belong to
        // the current tenant. If absent, the BelongsToTenant trait defaults it.
        if ($request->filled('legal_entity_id')) {
            $entityId = (int) $request->input('legal_entity_id');
            if (! LegalEntity::find($entityId)) {
                return $this->validationError([
                    'legal_entity_id' => ['legal_entity_id không thuộc công ty hiện tại'],
                ]);
            }
        }

        // department_id/position_id must belong to the current tenant. The bare
        // `exists:` rule is NOT tenant-scoped, so guard them explicitly.
        if ($request->filled('department_id') && ! TenantContext::ownsRow('departments', $request->input('department_id'))) {
            return $this->validationError(['department_id' => ['Phòng ban không thuộc công ty hiện tại']]);
        }
        if ($request->filled('position_id') && ! TenantContext::ownsRow('positions', $request->input('position_id'))) {
            return $this->validationError(['position_id' => ['Chức vụ không thuộc công ty hiện tại']]);
        }

        $data = $validator->validated();

        if ($request->filled('legal_entity_id')) {
            $data['legal_entity_id'] = (int) $request->input('legal_entity_id');
        }

        if (isset($data['phone']) && ! isset($data['phone_number'])) {
            $data['phone_number'] = $data['phone'];
        }
        unset($data['phone']);

        // Hash password if provided, else default to employee_code
        if ($request->filled('password')) {
            $data['password_hash'] = Hash::make($request->input('password'));
        } elseif (! empty($data['employee_code'])) {
            $data['password_hash'] = Hash::make($data['employee_code']);
        }

        if (isset($data['status'])) {
            $data['status'] = strtoupper($data['status']);
        } else {
            $data['status'] = 'ACTIVE';
        }

        // Reporting line (manager_id) is not part of the validation rules, so
        // carry it through explicitly — used when adding a subordinate on the
        // org chart. Must belong to the current tenant when provided.
        if ($request->filled('manager_id')) {
            $managerId = (int) $request->input('manager_id');
            if (! TenantContext::ownsRow('employees', $managerId)) {
                return $this->validationError(['manager_id' => ['Quản lý không thuộc công ty hiện tại']]);
            }
            $data['manager_id'] = $managerId;
        }

        $employee = Employee::create($data);

        // Open the initial job-history entry for the new hire.
        if (! empty($data['department_id']) || ! empty($data['position_id'])) {
            $this->recordEmploymentChange($employee->fresh());
        }

        $employee->load(['department:id,department_name', 'position:id,position_name']);

        return response()->json([
            'status' => 201,
            'message' => 'Nhân viên đã được tạo',
            'data' => $employee,
        ], 201);
    }

    /**
     * GET /employees/{id} — Chi tiết nhân viên kèm relations.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = Employee::with([
            'department:id,department_name,department_code',
            'position:id,position_name,position_code',
            'activeRoles',
            'activeContract',
        ])->find($id);

        if (! $employee) {
            return $this->notFound();
        }

        $employee->status = EmployeeStatus::resolve((int) $employee->id);

        // Lương + hợp đồng + hồ sơ cá nhân chỉ cho HR/Payroll hoặc chính chủ.
        if (! $this->canAccessPrivateEmployeeData($request, $id)) {
            unset($employee->base_salary, $employee->personal_email, $employee->date_of_birth);
            $employee->unsetRelation('activeContract');
            $employee->setAttribute('profile', null);
        }

        return $this->ok($employee, 'Employee detail');
    }

    /**
     * PUT/PATCH /employees/{id} — Cập nhật nhân viên.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return $this->notFound();
        }

        if ($request->has('status') && is_string($request->input('status'))) {
            $request->merge(['status' => strtoupper($request->input('status'))]);
        }

        if ($request->has('gender') && is_string($request->input('gender'))) {
            $gender = trim($request->input('gender'));
            $upperGender = mb_strtoupper($gender, 'UTF-8');
            if (in_array($upperGender, ['MALE', 'M', 'NAM'])) {
                $request->merge(['gender' => 'MALE']);
            } elseif (in_array($upperGender, ['FEMALE', 'F', 'NỮ'])) {
                $request->merge(['gender' => 'FEMALE']);
            } elseif (in_array($upperGender, ['OTHER', 'O'])) {
                $request->merge(['gender' => 'OTHER']);
            }
        }

        if ($request->has('phone') && ! $request->has('phone_number')) {
            $request->merge(['phone_number' => $request->input('phone')]);
        }

        $validator = Validator::make($request->all(), [
            'company_email' => [
                'nullable',
                'email',
                Rule::unique('employees', 'company_email')->where('tenant_id', TenantContext::id())->ignore($id),
            ],
            'employee_code' => [
                'nullable',
                'string',
                Rule::unique('employees', 'employee_code')->where('tenant_id', TenantContext::id())->ignore($id),
                'regex:/^[A-Z]{2,4}[0-9]{4,8}$/',
            ],
            'phone' => 'nullable|string|max:20',
            'phone_number' => 'nullable|string|max:20',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'profile' => 'nullable|array',
            'hire_date' => 'nullable|date',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE,ON_LEAVE,TERMINATED,PROBATION',
        ], [
            'company_email.unique' => 'Email công ty đã tồn tại',
            'employee_code.unique' => 'Mã nhân viên đã tồn tại',
            'department_id.exists' => 'Phòng ban không tồn tại',
            'position_id.exists' => 'Chức vụ không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Reject cross-tenant org units (bare `exists:` is not tenant-scoped).
        if ($request->filled('department_id') && ! TenantContext::ownsRow('departments', $request->input('department_id'))) {
            return $this->validationError(['department_id' => ['Phòng ban không thuộc công ty hiện tại']]);
        }
        if ($request->filled('position_id') && ! TenantContext::ownsRow('positions', $request->input('position_id'))) {
            return $this->validationError(['position_id' => ['Chức vụ không thuộc công ty hiện tại']]);
        }

        // Reassigning the reporting line (manager_id) — used by the org chart.
        // Guard against: cross-tenant manager, self-management, and CYCLES
        // (setting the manager to one of this employee's own subordinates would
        // create a loop in the org tree).
        if ($request->has('manager_id')) {
            $managerId = $request->input('manager_id');
            if ($managerId === null || $managerId === '' || (int) $managerId === 0) {
                $request->merge(['manager_id' => null]); // gỡ quản lý → đưa lên gốc
            } else {
                $managerId = (int) $managerId;
                if ($managerId === $id) {
                    return $this->validationError(['manager_id' => ['Nhân viên không thể là quản lý của chính mình']]);
                }
                if (! TenantContext::ownsRow('employees', $managerId)) {
                    return $this->validationError(['manager_id' => ['Quản lý không thuộc công ty hiện tại']]);
                }
                // Cycle check: manager mới không được nằm trong cây cấp dưới của NV này.
                $subtreeIds = (new OrganizationChartRepository)->getTree($id)->pluck('id')->all();
                if (in_array($managerId, $subtreeIds, true)) {
                    return $this->validationError(['manager_id' => ['Không thể chọn cấp dưới làm quản lý (sẽ tạo vòng lặp trong sơ đồ tổ chức)']]);
                }
                $request->merge(['manager_id' => $managerId]);
            }
        }

        // Only update columns that exist on the table. BLOCK security/identity
        // columns from mass-assignment: is_super_admin (platform cross-tenant
        // operator), tenant_id/legal_entity_id (moving a row between companies) —
        // otherwise any HR-module user could grant super-admin or leak across
        // tenants. Those are never set through a normal employee edit.
        $columns = Schema::getColumnListing('employees');
        $data = collect($request->except([
            'id', 'created_at', 'updated_at', 'password_hash',
            'is_super_admin', 'tenant_id', 'legal_entity_id',
        ]))
            ->only($columns)
            ->toArray();

        if (isset($data['profile']) && is_array($data['profile'])) {
            $data['profile'] = array_replace($employee->profile ?? [], $data['profile']);
        }

        $oldDept = $employee->department_id;
        $oldPos = $employee->position_id;

        $employee->update($data);

        // Auto-record a job-history entry when the department or position changed
        // (đúng nghiệp vụ: lịch sử công tác tự ghi nhận khi điều chuyển).
        $deptChanged = array_key_exists('department_id', $data) && (int) $data['department_id'] !== (int) $oldDept;
        $posChanged = array_key_exists('position_id', $data) && (int) $data['position_id'] !== (int) $oldPos;
        if ($deptChanged || $posChanged) {
            $this->recordEmploymentChange($employee->fresh());
        }

        $employee->load(['department:id,department_name', 'position:id,position_name']);

        return $this->ok($employee, 'Nhân viên đã được cập nhật');
    }

    /**
     * Snapshot the employee's current department/position into employment_histories:
     * close any open ("is_current") rows, then open a new current one. Safe to call
     * from create (initial assignment) or update (transfer/promotion).
     */
    private function recordEmploymentChange(Employee $employee): void
    {
        if (! Schema::hasTable('employment_histories')) {
            return;
        }

        if (empty($employee->department_id) && empty($employee->position_id)) {
            return;
        }

        $today = now()->toDateString();

        DB::table('employment_histories')
            ->where('employee_id', $employee->id)
            ->whereRaw('is_current = true')
            ->update(['is_current' => DB::raw('false'), 'end_date' => $today, 'updated_at' => now()]);

        DB::table('employment_histories')->insert(TenantContext::stamp([
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'start_date' => $today,
            'is_current' => DB::raw('true'),
            'notes' => 'Tự ghi nhận khi cập nhật phòng ban / chức danh',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * DELETE /employees/{id} — Xóa nhân viên (có kiểm tra ràng buộc).
     */
    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return $this->notFound();
        }

        $violations = $employee->deletionViolations();
        $violations[] = 'Hồ sơ nhân viên là dữ liệu lịch sử; hãy chuyển trạng thái hoặc dùng Gỡ khỏi sơ đồ thay vì xóa';

        return $this->conflict($violations, 'Nhân viên');
    }

    /**
     * POST /employees/{id}/detach-from-org — Gỡ nhân viên khỏi sơ đồ tổ chức.
     *
     * Nghiệp vụ khi một người nghỉ/được gỡ: cấp dưới trực tiếp của họ được
     * chuyển lên báo cáo cho quản lý của chính họ (không để mồ côi nhánh), và
     * nhân viên được đánh dấu TERMINATED nên biến mất khỏi sơ đồ. Toàn bộ trong
     * một transaction. (Không hard-delete để tránh vỡ ràng buộc chấm công/HĐ.)
     */
    public function detachFromOrg(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return $this->notFound();
        }

        $newManagerId = $employee->manager_id; // có thể null → cấp dưới lên gốc

        DB::transaction(function () use ($employee, $newManagerId) {
            // Reparent cấp dưới trực tiếp (chỉ trong cùng tenant nhờ global scope).
            Employee::where('manager_id', $employee->id)
                ->update(['manager_id' => $newManagerId]);

            // Đóng lịch sử công tác hiện tại + đánh dấu nghỉ việc.
            DB::table('employment_histories')
                ->where('employee_id', $employee->id)
                ->whereRaw('is_current = true')
                ->update(['is_current' => DB::raw('false'), 'end_date' => now()->toDateString(), 'updated_at' => now()]);

            $employee->update(['status' => 'TERMINATED']);
        });

        return $this->ok([
            'id' => $id,
            'reparented_to' => $newManagerId,
        ], 'Đã gỡ nhân viên khỏi sơ đồ; cấp dưới đã chuyển lên quản lý cấp trên');
    }

    /**
     * GET /employees/{id}/departments — Phòng ban của nhân viên: phòng CHÍNH
     * (employees.department_id) + các phòng KIÊM NHIỆM (pivot employee_departments).
     * Dùng để trang chi tiết hiển thị NV đang thuộc nhiều phòng ban (ma trận).
     */
    public function departments(int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return $this->notFound();
        }

        $primary = $employee->department_id
            ? DB::table('departments')->where('id', $employee->department_id)
                ->first(['id', 'department_name', 'department_code'])
            : null;

        $secondary = [];
        if (Schema::hasTable('employee_departments')) {
            $query = DB::table('employee_departments as ed')
                ->join('departments as d', 'd.id', '=', 'ed.department_id')
                ->where('ed.employee_id', $id)
                ->select('d.id', 'd.department_name', 'd.department_code', 'ed.role_in_dept');
            if (TenantContext::hasTenant()) {
                $query->where('ed.tenant_id', TenantContext::id());
            }
            $secondary = $query->get();
        }

        return $this->ok([
            'primary' => $primary,
            'secondary' => $secondary,
        ], 'Employee departments');
    }

    // ═══════════════════════════════════════════════════════
    // SUB-RESOURCES
    // ═══════════════════════════════════════════════════════

    /**
     * PATCH /employees/{id}/profile — Cập nhật profile JSONB.
     */
    public function updateProfile(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return $this->notFound();
        }

        $currentProfile = $employee->profile ?? [];
        $employee->update([
            'profile' => array_merge($currentProfile, $request->all()),
        ]);

        return $this->ok($employee->fresh(), 'Profile đã được cập nhật');
    }

    /**
     * GET /employees/{id}/profile — Xem profile.
     */
    public function showProfile(Request $request, int $id): JsonResponse
    {
        $employee = Employee::with([
            'department:id,department_name',
            'position:id,position_name',
        ])->find($id);

        if (! $employee) {
            return $this->notFound();
        }

        if (! $this->canAccessPrivateEmployeeData($request, $id)) {
            return response()->json([
                'status' => 403,
                'message' => 'Bạn không có quyền xem hồ sơ riêng tư của nhân viên này',
                'data' => null,
            ], 403);
        }

        // Gather extended profile data
        $data = [
            'employee' => $employee,
            'qualifications' => DB::table('qualifications')->where('employee_id', $id)->get(),
            'certificates' => DB::table('certificates')->where('employee_id', $id)->get(),
            'identity_documents' => DB::table('identity_documents')->where('employee_id', $id)->get(),
            'social_insurance' => DB::table('social_insurance_info')->where('employee_id', $id)->first(),
            'dependents' => DB::table('dependents')->where('employee_id', $id)->get(),
        ];

        return $this->ok($data, 'Employee profile');
    }

    private function canAccessPrivateEmployeeData(Request $request, int $employeeId): bool
    {
        if ((int) $request->attributes->get('auth_employee_id') === $employeeId) {
            return true;
        }

        $access = $request->attributes->get('access');

        return is_array($access) && (! empty($access['full'])
            || (bool) array_intersect(['hr', 'payroll'], $access['modules'] ?? []));
    }

    /**
     * GET /employees/{id}/employment-histories
     */
    public function employmentHistories(int $id): JsonResponse
    {
        if (! Employee::where('id', $id)->exists()) {
            return $this->notFound();
        }

        // FE đọc job_title/department (tên) + employment_status — join sẵn ở đây,
        // trả id trần thì tab Lịch sử công tác hiện "Chưa có chức danh" với mọi NV.
        $histories = DB::table('employment_histories as h')
            ->leftJoin('positions as p', 'p.id', '=', 'h.position_id')
            ->leftJoin('departments as d', 'd.id', '=', 'h.department_id')
            ->where('h.employee_id', $id)
            ->orderByDesc('h.id')
            ->get(['h.*', 'p.position_name as job_title', 'd.department_name as department']);

        foreach ($histories as $h) {
            $h->employment_status = in_array($h->is_current, [true, 'true', 1, '1'], true) ? 'active' : 'transferred';
        }

        return $this->ok($histories, 'Employment histories');
    }

    /**
     * GET /employees/{id}/certificates
     */
    public function certificates(int $id): JsonResponse
    {
        if (! Employee::where('id', $id)->exists()) {
            return $this->notFound();
        }

        $certs = DB::table('certificates')
            ->where('employee_id', $id)
            ->orderByDesc('id')
            ->get();

        return $this->ok($certs, 'Employee certificates');
    }

    /**
     * POST /employees/{id}/certificates — Thêm chứng chỉ.
     */
    public function storeCertificate(Request $request, int $id): JsonResponse
    {
        if (! Employee::where('id', $id)->exists()) {
            return $this->notFound();
        }

        $data = $request->all();
        $data['employee_id'] = $id;
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $columns = Schema::getColumnListing('certificates');
        $data = collect($data)->only($columns)->toArray();

        $newId = DB::table('certificates')->insertGetId($data);

        return response()->json([
            'status' => 201,
            'message' => 'Chứng chỉ đã được thêm',
            'data' => DB::table('certificates')->where('id', $newId)->first(),
        ], 201);
    }

    /**
     * DELETE /employees/{id}/certificates/{certId} — Xóa chứng chỉ.
     */
    public function destroyCertificate(int $id, int $certId): JsonResponse
    {
        $cert = DB::table('certificates')
            ->where('id', $certId)
            ->where('employee_id', $id)
            ->first();

        if (! $cert) {
            return $this->notFound('Chứng chỉ không tồn tại');
        }

        DB::table('certificates')->where('id', $certId)->delete();

        return $this->ok(['id' => $certId], 'Chứng chỉ đã được xóa');
    }

    /**
     * POST /employees/import-probation — Import NV thử việc hàng loạt.
     */
    public function importProbation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employees' => 'required|array|min:1',
            'employees.*.full_name' => 'required|string',
            'employees.*.company_email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $imported = 0;
        $errors = [];

        foreach ($request->input('employees', []) as $i => $empData) {
            if (Employee::where('company_email', $empData['company_email'])->exists()) {
                $errors[] = "Dòng {$i}: Email {$empData['company_email']} đã tồn tại";

                continue;
            }

            Employee::create([
                'full_name' => $empData['full_name'],
                'company_email' => $empData['company_email'],
                'employee_code' => $empData['employee_code'] ?? null,
                'status' => 'PROBATION',
                'password_hash' => Hash::make($empData['employee_code'] ?? 'password'),
            ]);
            $imported++;
        }

        return $this->ok([
            'imported' => $imported,
            'errors' => $errors,
        ], "Đã import {$imported} nhân viên thử việc");
    }

    // ═══════════════════════════════════════════════════════
    // RESPONSE HELPERS
    // ═══════════════════════════════════════════════════════

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
    }

    private function conflict(array $violations, string $resourceName): JsonResponse
    {
        return response()->json([
            'status' => 409,
            'message' => "Không thể xóa {$resourceName} do vi phạm ràng buộc nghiệp vụ",
            'data' => ['violations' => $violations],
        ], 409);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dữ liệu không hợp lệ',
            'data' => ['errors' => $errors],
        ], 422);
    }
}

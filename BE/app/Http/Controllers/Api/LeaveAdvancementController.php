<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAccess;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveAdvancementController extends Controller
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $query = DB::table('leave_advancement_requests as a')
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 'a.employee_id')->on('e.tenant_id', '=', 'a.tenant_id');
            })
            ->leftJoin('leave_types as l', function ($join): void {
                $join->on('l.id', '=', 'a.leave_type_id')->on('l.tenant_id', '=', 'a.tenant_id');
            })
            ->where('a.tenant_id', TenantContext::id());
        $this->scope($query, $request, 'a');
        foreach (['status', 'employee_id'] as $field) {
            if ($request->filled($field)) $query->where('a.'.$field, $request->query($field));
        }
        $page = $query->orderByDesc('a.id')->select(['a.*', 'e.employee_code', 'e.full_name as employee_name', 'l.leave_type_code', 'l.leave_type_name'])->paginate($perPage);

        return $this->ok(['items' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]], 'Danh sách tạm ứng phép');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['leave_type_id' => ['required', 'integer'], 'advance_days' => ['required', 'numeric', 'min:0.5', 'max:365'], 'reason' => ['required', 'string', 'max:5000']]);
        $employeeId = $this->access->actorId($request);
        $employee = DB::table('employees')->where('tenant_id', TenantContext::id())->where('id', $employeeId)->first(['department_id', 'position_id']);
        abort_unless(DB::table('leave_types')->where('tenant_id', TenantContext::id())->where('id', $data['leave_type_id'])->exists(), 422, 'Loại phép không hợp lệ');
        $max = $this->maximumDays((int) ($employee->department_id ?? 0), (int) ($employee->position_id ?? 0));
        if ($max === null) {
            throw ValidationException::withMessages([
                'advance_days' => ['Chưa có cấu hình tạm ứng phép phù hợp cho phòng ban/chức danh này'],
            ]);
        }
        if ((float) $data['advance_days'] > $max) {
            throw ValidationException::withMessages(['advance_days' => ["Số ngày tạm ứng tối đa là {$max}"]]);
        }
        $id = DB::table('leave_advancement_requests')->insertGetId(TenantContext::stamp([
            'employee_id' => $employeeId, 'leave_type_id' => $data['leave_type_id'], 'advance_days' => $data['advance_days'],
            'reason' => trim($data['reason']), 'status' => 'PENDING_MANAGER', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return response()->json(['status' => 201, 'message' => 'Đã gửi yêu cầu tạm ứng phép', 'data' => ['id' => $id]], 201);
    }

    public function managerDecision(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'leave.advance.manager');
        $row = $this->find($request, $id, true);
        if (! $row) return $this->notFound();
        abort_unless($this->access->canAccessEmployee($request, (int) $row->employee_id, true), 403);
        abort_if((int) $row->employee_id === $this->access->actorId($request), 422, 'Không thể tự duyệt yêu cầu của mình');
        if ($row->status !== 'PENDING_MANAGER') return $this->conflict('Yêu cầu không chờ Trưởng phòng duyệt');
        $data = $request->validate(['decision' => ['required', 'in:APPROVE,REJECT'], 'note' => ['nullable', 'string', 'max:5000']]);
        if ($data['decision'] === 'REJECT' && trim((string) ($data['note'] ?? '')) === '') throw ValidationException::withMessages(['note' => ['Bắt buộc ghi lý do từ chối']]);
        $meta = $this->meta($row->meta); $meta['manager_note'] = $data['note'] ?? null; $meta['manager_decided_at'] = now()->toIso8601String();
        DB::table('leave_advancement_requests')->where('tenant_id', TenantContext::id())->where('id', $id)->update([
            'approved_by_manager' => $this->access->actorId($request), 'status' => $data['decision'] === 'APPROVE' ? 'PENDING_HR' : 'REJECTED_MANAGER',
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);

        return $this->show($request, $id);
    }

    public function hrDecision(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'leave.advance.hr');
        $row = $this->find($request, $id);
        if (! $row) return $this->notFound();
        if ($row->status !== 'PENDING_HR') return $this->conflict('Yêu cầu không chờ HR duyệt');
        abort_if((int) $row->employee_id === $this->access->actorId($request), 422, 'Không thể tự duyệt yêu cầu của mình');
        $data = $request->validate(['decision' => ['required', 'in:APPROVE,REJECT'], 'note' => ['nullable', 'string', 'max:5000']]);
        if ($data['decision'] === 'REJECT' && trim((string) ($data['note'] ?? '')) === '') throw ValidationException::withMessages(['note' => ['Bắt buộc ghi lý do từ chối']]);

        DB::transaction(function () use ($row, $id, $data, $request): void {
            $locked = DB::table('leave_advancement_requests')->where('tenant_id', TenantContext::id())->where('id', $id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'PENDING_HR') throw ValidationException::withMessages(['status' => ['Yêu cầu đã được xử lý']]);
            $meta = $this->meta($locked->meta); $meta['hr_note'] = $data['note'] ?? null; $meta['hr_decided_at'] = now()->toIso8601String();
            if ($data['decision'] === 'REJECT') {
                DB::table('leave_advancement_requests')->where('id', $id)->update(['approved_by_hr' => $this->access->actorId($request), 'status' => 'REJECTED_HR', 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                return;
            }
            $year = now()->format('Y');
            $balance = DB::table('leave_balances')->where('tenant_id', TenantContext::id())->where('employee_id', $locked->employee_id)->where('leave_type_id', $locked->leave_type_id)->where('year', $year)->lockForUpdate()->first();
            $before = (float) ($balance->remaining_days ?? 0); $after = $before + (float) $locked->advance_days;
            if ($balance) {
                DB::table('leave_balances')->where('id', $balance->id)->update(['total_days' => (float) $balance->total_days + (float) $locked->advance_days, 'remaining_days' => $after, 'updated_at' => now()]);
            } else {
                DB::table('leave_balances')->insert(TenantContext::stamp(['employee_id' => $locked->employee_id, 'leave_type_id' => $locked->leave_type_id, 'year' => $year, 'total_days' => $locked->advance_days, 'used_days' => 0, 'remaining_days' => $after, 'created_at' => now(), 'updated_at' => now()]));
            }
            DB::table('leave_transactions')->insert(TenantContext::stamp(['employee_id' => $locked->employee_id, 'leave_type_id' => $locked->leave_type_id, 'transaction_date' => now()->toDateString(), 'transaction_type' => 'ADVANCEMENT', 'quantity' => $locked->advance_days, 'before_balance' => $before, 'after_balance' => $after, 'reference_id' => $id, 'reference_type' => 'leave_advancement_request', 'reason' => $locked->reason, 'created_at' => now(), 'updated_at' => now()]));
            DB::table('leave_advancement_requests')->where('id', $id)->update(['approved_by_hr' => $this->access->actorId($request), 'status' => 'APPROVED', 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        });

        return $this->show($request, $id);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = $this->find($request, $id);
        if (! $row) return $this->notFound();
        abort_unless((int) $row->employee_id === $this->access->actorId($request), 403);
        if (! in_array($row->status, ['PENDING_MANAGER', 'PENDING_HR'], true)) return $this->conflict('Không thể hủy yêu cầu đã duyệt cuối');
        DB::table('leave_advancement_requests')->where('tenant_id', TenantContext::id())->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        return $this->ok(['id' => $id, 'status' => 'CANCELLED'], 'Đã hủy yêu cầu');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->find($request, $id);
        return $row ? $this->ok($row, 'Chi tiết tạm ứng phép') : $this->notFound();
    }

    private function find(Request $request, int $id, bool $managerAction = false): ?object
    {
        $query = DB::table('leave_advancement_requests as a')->where('a.id', $id)->where('a.tenant_id', TenantContext::id());
        $this->scope($query, $request, 'a');
        return $query->first(['a.*']);
    }

    private function scope($query, Request $request, string $alias): void
    {
        if ($this->access->isAdmin($request)) return;
        if ($this->access->isHr($request)) { $query->whereIn("{$alias}.employee_id", DB::table('employees')->select('id')->where('tenant_id', TenantContext::id())->where('legal_entity_id', TenantContext::legalEntityId())); return; }
        if ($this->access->isDepartmentManager($request)) { $ids = $this->access->scopeEmployeeQuery(DB::table('employees'), $request)->pluck('employees.id'); $query->whereIn("{$alias}.employee_id", $ids); return; }
        $query->where("{$alias}.employee_id", $this->access->actorId($request));
    }

    private function maximumDays(int $departmentId, int $positionId): ?float
    {
        $rows = DB::table('leave_advancement_config')->where('tenant_id', TenantContext::id())->whereRaw("upper(coalesce(status, 'ACTIVE')) = 'ACTIVE'")
            ->where(fn ($query) => $query->whereNull('department_id')->orWhere('department_id', $departmentId))
            ->where(fn ($query) => $query->whereNull('position_id')->orWhere('position_id', $positionId))->get();
        $maximum = $rows->max('max_advance_days');

        return $maximum === null ? null : (float) $maximum;
    }

    private function require(Request $request, string $capability): void { abort_unless(AccessControl::accessHasCapability((array) $request->attributes->get('access', []), $capability), 403); }
    private function meta(mixed $value): array { $meta = is_string($value) ? json_decode($value, true) : (array) ($value ?? []); return is_array($meta) ? $meta : []; }
    private function ok(mixed $data, string $message): JsonResponse { return response()->json(['status' => 200, 'message' => $message, 'data' => $data]); }
    private function notFound(): JsonResponse { return response()->json(['status' => 404, 'message' => 'Không tìm thấy yêu cầu tạm ứng phép', 'data' => null], 404); }
    private function conflict(string $message): JsonResponse { return response()->json(['status' => 409, 'message' => $message, 'data' => null], 409); }
}

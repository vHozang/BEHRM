<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAccess;
use App\Services\PayrollRunService;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollAdjustmentController extends Controller
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireAny($request, ['payroll.adjustments.create', 'payroll.adjustments.approve', 'payroll.amounts.view']);
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $query = DB::table('payroll_adjustments as a')
            ->join('employees as e', function ($join): void {
                $join->on('e.id', '=', 'a.employee_id')->on('e.tenant_id', '=', 'a.tenant_id');
            })
            ->join('salary_periods as p', function ($join): void {
                $join->on('p.id', '=', 'a.paid_period_id')->on('p.tenant_id', '=', 'a.tenant_id');
            })
            ->where('a.tenant_id', TenantContext::id())
            ->orderByDesc('a.id');
        if (! $this->access->isAdmin($request)) {
            $query->where('a.legal_entity_id', TenantContext::legalEntityId());
        } elseif ($request->filled('legal_entity_id')) {
            $query->where('a.legal_entity_id', (int) $request->query('legal_entity_id'));
        }
        foreach (['employee_id', 'paid_period_id', 'status', 'adjustment_type'] as $field) {
            if ($request->filled($field)) {
                $query->where('a.'.$field, $request->query($field));
            }
        }
        $page = $query->paginate($perPage, [
            'a.*', 'e.employee_code', 'e.full_name', 'e.department_id',
            'p.period_code', 'p.period_name', 'p.status as period_status',
        ]);

        return $this->ok(['items' => $page->items(), 'pagination' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]], 'Payroll adjustments list');
    }

    public function store(Request $request): JsonResponse
    {
        $this->require($request, 'payroll.adjustments.create');
        $data = $this->validated($request);
        [$employee, $period] = $this->references($request, $data);
        $id = DB::table('payroll_adjustments')->insertGetId([
            ...$data,
            'status' => 'DRAFT',
            'created_by' => $this->access->actorId($request),
            'tenant_id' => TenantContext::id(),
            'legal_entity_id' => $employee->legal_entity_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 201, 'message' => 'Đã tạo điều chỉnh lương', 'data' => DB::table('payroll_adjustments')->find($id)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'payroll.adjustments.create');
        $row = $this->find($request, $id);
        if (! $row) {
            return $this->notFound();
        }
        if ($row->status !== 'DRAFT') {
            return $this->conflict('Chỉ điều chỉnh ở trạng thái DRAFT mới được sửa');
        }
        if ((int) $row->created_by !== $this->access->actorId($request) && ! $this->access->isAdmin($request)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không phải người tạo điều chỉnh', 'data' => null], 403);
        }
        $data = $this->validated($request, true);
        $merged = array_merge((array) $row, $data);
        $this->references($request, $merged);
        $this->adjustmentQuery($request)->where('id', $id)
            ->update([...$data, 'updated_at' => now()]);

        return $this->ok($this->find($request, $id), 'Đã cập nhật điều chỉnh lương');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'payroll.adjustments.create');
        $row = $this->find($request, $id);
        if (! $row) {
            return $this->notFound();
        }
        if ($row->status !== 'DRAFT') {
            return $this->conflict('Chỉ điều chỉnh DRAFT mới được xóa');
        }
        if ((int) $row->created_by !== $this->access->actorId($request) && ! $this->access->isAdmin($request)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không phải người tạo điều chỉnh', 'data' => null], 403);
        }
        $this->adjustmentQuery($request)->where('id', $id)->delete();

        return $this->ok(['id' => $id], 'Đã xóa điều chỉnh lương');
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'payroll.adjustments.create');
        $row = $this->find($request, $id);
        if (! $row) {
            return $this->notFound();
        }
        if ($row->status !== 'DRAFT') {
            return $this->conflict('Điều chỉnh không ở trạng thái DRAFT');
        }
        if ((int) $row->created_by !== $this->access->actorId($request)) {
            return response()->json(['status' => 403, 'message' => 'Chỉ người tạo được trình duyệt', 'data' => null], 403);
        }
        $this->assertPeriodOpen((int) $row->paid_period_id);
        $this->adjustmentQuery($request)->where('id', $id)->update([
            'status' => 'SUBMITTED', 'submitted_by' => $this->access->actorId($request),
            'submitted_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ok($this->find($request, $id), 'Đã trình duyệt điều chỉnh lương');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'payroll.adjustments.approve');
        $row = $this->find($request, $id);
        if (! $row) {
            return $this->notFound();
        }
        if ($row->status !== 'SUBMITTED') {
            return $this->conflict('Điều chỉnh không ở trạng thái SUBMITTED');
        }
        if ((int) $row->created_by === $this->access->actorId($request)) {
            return response()->json(['status' => 422, 'message' => 'Người tạo không thể tự duyệt điều chỉnh', 'data' => null], 422);
        }
        $this->assertPeriodOpen((int) $row->paid_period_id);
        $this->adjustmentQuery($request)->where('id', $id)->update([
            'status' => 'APPROVED', 'approved_by' => $this->access->actorId($request),
            'approved_at' => now(), 'rejection_reason' => null, 'updated_at' => now(),
        ]);

        return $this->ok($this->find($request, $id), 'Đã duyệt điều chỉnh lương');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->require($request, 'payroll.adjustments.approve');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $row = $this->find($request, $id);
        if (! $row) {
            return $this->notFound();
        }
        if ($row->status !== 'SUBMITTED') {
            return $this->conflict('Điều chỉnh không ở trạng thái SUBMITTED');
        }
        if ((int) $row->created_by === $this->access->actorId($request)) {
            return response()->json(['status' => 422, 'message' => 'Người tạo không thể tự từ chối điều chỉnh', 'data' => null], 422);
        }
        $this->assertPeriodOpen((int) $row->paid_period_id);
        $this->adjustmentQuery($request)->where('id', $id)->update([
            'status' => 'REJECTED', 'approved_by' => $this->access->actorId($request),
            'rejected_at' => now(), 'rejection_reason' => $data['reason'], 'updated_at' => now(),
        ]);

        return $this->ok($this->find($request, $id), 'Đã từ chối điều chỉnh lương');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'employee_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'paid_period_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'adjustment_type' => [$partial ? 'sometimes' : 'required', 'string', 'in:BONUS,THANG_13,THUONG,THUONG_TET,EARNING,OTHER_EARNING,DEDUCTION,ADVANCE,OTHER_DEDUCTION'],
            'amount' => [$partial ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function references(Request $request, array $data): array
    {
        $employee = DB::table('employees')->where('tenant_id', TenantContext::id())
            ->when(! $this->access->isAdmin($request), fn ($query) => $query->where('legal_entity_id', TenantContext::legalEntityId()))
            ->where('id', $data['employee_id'])->first(['id', 'legal_entity_id']);
        $period = DB::table('salary_periods')->where('tenant_id', TenantContext::id())
            ->when(! $this->access->isAdmin($request), fn ($query) => $query->where('legal_entity_id', TenantContext::legalEntityId()))
            ->where('id', $data['paid_period_id'])->first(['id', 'legal_entity_id', 'status']);
        if (! $employee || ! $period || (int) $employee->legal_entity_id !== (int) $period->legal_entity_id) {
            abort(422, 'Nhân viên và kỳ lương phải thuộc cùng pháp nhân hiện tại');
        }
        $this->assertPeriodOpen((int) $period->id, (string) $period->status);

        return [$employee, $period];
    }

    private function assertPeriodOpen(int $periodId, ?string $knownStatus = null): void
    {
        $status = $knownStatus ?? DB::table('salary_periods')->where('tenant_id', TenantContext::id())
            ->where('id', $periodId)->value('status');
        if ($status === null || in_array((string) $status, PayrollRunService::LOCKED_PERIOD_STATUSES, true)) {
            abort(409, 'Kỳ lương đã trình hoặc khóa');
        }
    }

    private function find(Request $request, int $id): ?object
    {
        return $this->adjustmentQuery($request)->where('id', $id)->first();
    }

    private function adjustmentQuery(Request $request)
    {
        return DB::table('payroll_adjustments')
            ->where('tenant_id', TenantContext::id())
            ->when(
                ! $this->access->isAdmin($request),
                fn ($query) => $query->where('legal_entity_id', TenantContext::legalEntityId()),
            );
    }

    private function require(Request $request, string $capability): void
    {
        abort_unless(AccessControl::accessHasCapability((array) $request->attributes->get('access', []), $capability), 403);
    }

    private function requireAny(Request $request, array $capabilities): void
    {
        $access = (array) $request->attributes->get('access', []);
        abort_unless(collect($capabilities)->contains(fn ($capability) => AccessControl::accessHasCapability($access, $capability)), 403);
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => 'Record not found', 'data' => null], 404);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['status' => 409, 'message' => $message, 'data' => null], 409);
    }
}

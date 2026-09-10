<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAccess;
use App\Support\AccessControl;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InsuranceClaimController extends Controller
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $query = DB::table('insurance_claims as c')
            ->leftJoin('employees as e', function ($join): void {
                $join->on('e.id', '=', 'c.employee_id')->on('e.tenant_id', '=', 'c.tenant_id');
            })
            ->leftJoin('insurance_types as t', function ($join): void {
                $join->on('t.id', '=', 'c.insurance_type_id')->on('t.tenant_id', '=', 'c.tenant_id');
            })
            ->leftJoin('banks as b', function ($join): void {
                $join->on('b.id', '=', 'c.bank_id')->on('b.tenant_id', '=', 'c.tenant_id');
            });
        $this->scope($query, $request, 'c');
        foreach (['employee_id', 'insurance_type_id', 'payment_status'] as $field) {
            if ($request->filled($field)) {
                $query->where('c.'.$field, $request->query($field));
            }
        }
        $page = $query->orderByDesc('c.id')->select([
            'c.*', 'e.employee_code', 'e.full_name as employee_name',
            't.insurance_type_code', 't.insurance_type_name', 'b.bank_code', 'b.bank_name',
        ])->paginate($perPage);

        return $this->ok(['items' => $page->items(), 'pagination' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]], 'Danh sách hồ sơ bảo hiểm');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $employeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : $this->access->actorId($request);
        $canCreateForOthers = AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'insurance.review');
        if ($employeeId !== $this->access->actorId($request) && ! $canCreateForOthers) {
            abort(403);
        }
        abort_unless($this->access->canAccessEmployee($request, $employeeId, $employeeId !== $this->access->actorId($request)), 403);
        $employee = DB::table('employees')->where('tenant_id', TenantContext::id())->where('id', $employeeId)->first(['legal_entity_id']);
        $this->assertReferences($data);
        $id = DB::table('insurance_claims')->insertGetId([
            ...collect($data)->except(['employee_id'])->toArray(),
            'tenant_id' => TenantContext::id(), 'legal_entity_id' => $employee->legal_entity_id,
            'employee_id' => $employeeId, 'claim_code' => $data['claim_code'] ?? 'BH-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'payment_status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ]);
        AuditLogger::log('create', 'insurance_claims', $id, null, (array) DB::table('insurance_claims')->find($id));

        return response()->json(['status' => 201, 'message' => 'Đã tạo hồ sơ bảo hiểm', 'data' => ['id' => $id]], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $claim = $this->find($request, $id);

        return $claim ? $this->ok($claim, 'Chi tiết hồ sơ bảo hiểm') : $this->notFound();
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $claim = $this->find($request, $id);
        if (! $claim) {
            return $this->notFound();
        }
        $isOwner = (int) $claim->employee_id === $this->access->actorId($request);
        $isHr = AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'insurance.review');
        abort_unless($isOwner || $isHr, 403);
        if (strtoupper((string) $claim->payment_status) !== 'DRAFT') {
            return $this->conflict('Chỉ hồ sơ nháp mới được sửa');
        }
        $data = $this->validated($request, true);
        unset($data['employee_id'], $data['claim_code']);
        $this->assertReferences($data);
        DB::table('insurance_claims')->where('tenant_id', TenantContext::id())->where('id', $id)->update([...$data, 'updated_at' => now()]);

        return $this->show($request, $id);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $claim = $this->find($request, $id);
        if (! $claim) {
            return $this->notFound();
        }
        abort_unless((int) $claim->employee_id === $this->access->actorId($request), 403);
        if (strtoupper((string) $claim->payment_status) !== 'DRAFT') {
            return $this->conflict('Hồ sơ không ở trạng thái nháp');
        }
        DB::table('insurance_claims')->where('tenant_id', TenantContext::id())->where('id', $id)
            ->update(['payment_status' => 'SUBMITTED', 'updated_at' => now()]);

        return $this->ok(['id' => $id, 'payment_status' => 'SUBMITTED'], 'Đã gửi hồ sơ cho HR');
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'insurance.review');
        $claim = $this->find($request, $id);
        if (! $claim) {
            return $this->notFound();
        }
        if (! in_array(strtoupper((string) $claim->payment_status), ['SUBMITTED', 'HR_REJECTED'], true)) {
            return $this->conflict('Hồ sơ chưa sẵn sàng để HR thẩm định');
        }
        $data = $request->validate(['decision' => ['required', 'in:APPROVE,REJECT'], 'note' => ['nullable', 'string', 'max:5000']]);
        if ($data['decision'] === 'REJECT' && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => ['Bắt buộc ghi lý do từ chối']]);
        }
        $meta = $this->meta($claim->meta ?? null);
        $meta['reviewed_by'] = $this->access->actorId($request);
        $meta['reviewed_at'] = now()->toIso8601String();
        $meta['review_note'] = $data['note'] ?? null;
        DB::table('insurance_claims')->where('tenant_id', TenantContext::id())->where('id', $id)->update([
            'payment_status' => $data['decision'] === 'APPROVE' ? 'HR_APPROVED' : 'HR_REJECTED',
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);

        return $this->show($request, $id);
    }

    public function payment(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'insurance.pay');
        $claim = $this->find($request, $id);
        if (! $claim) {
            return $this->notFound();
        }
        if (! in_array(strtoupper((string) $claim->payment_status), ['HR_APPROVED', 'PAYMENT_FAILED'], true)) {
            return $this->conflict('Hồ sơ chưa được HR duyệt hoặc đã thanh toán');
        }
        $data = $request->validate(['status' => ['required', 'in:PAID,PAYMENT_FAILED'], 'reference' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:5000']]);
        $meta = $this->meta($claim->meta ?? null);
        $meta['payment_by'] = $this->access->actorId($request);
        $meta['payment_at'] = now()->toIso8601String();
        $meta['payment_reference'] = $data['reference'] ?? null;
        $meta['payment_note'] = $data['note'] ?? null;
        DB::table('insurance_claims')->where('tenant_id', TenantContext::id())->where('id', $id)
            ->update(['payment_status' => $data['status'], 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $this->show($request, $id);
    }

    public function uploadCertificate(Request $request, int $id): JsonResponse
    {
        $claim = $this->find($request, $id);
        if (! $claim) {
            return $this->notFound();
        }
        abort_unless((int) $claim->employee_id === $this->access->actorId($request)
            || AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'insurance.review'), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);
        if ($claim->certificate_file_url) {
            Storage::disk('local')->delete($claim->certificate_file_url);
        }
        $path = $request->file('file')->store("insurance-claims/".TenantContext::id()."/{$id}", 'local');
        DB::table('insurance_claims')->where('tenant_id', TenantContext::id())->where('id', $id)->update([
            'certificate_file_url' => $path, 'certificate_uploaded_date' => now()->toDateString(), 'updated_at' => now(),
        ]);

        return $this->ok(['id' => $id, 'has_certificate' => true], 'Đã tải chứng từ');
    }

    public function downloadCertificate(Request $request, int $id)
    {
        $claim = $this->find($request, $id);
        if (! $claim || ! $claim->certificate_file_url || ! Storage::disk('local')->exists($claim->certificate_file_url)) {
            return $this->notFound();
        }

        return Storage::disk('local')->download($claim->certificate_file_url, "ChungTu-{$claim->claim_code}.".pathinfo($claim->certificate_file_url, PATHINFO_EXTENSION));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $claim = $this->find($request, $id);
        if (! $claim) {
            return $this->notFound();
        }
        abort_unless((int) $claim->employee_id === $this->access->actorId($request), 403);
        if (strtoupper((string) $claim->payment_status) !== 'DRAFT') {
            return $this->conflict('Chỉ hồ sơ nháp mới được xóa');
        }
        if ($claim->certificate_file_url) {
            Storage::disk('local')->delete($claim->certificate_file_url);
        }
        DB::table('insurance_claims')->where('tenant_id', TenantContext::id())->where('id', $id)->delete();

        return $this->ok(['id' => $id], 'Đã xóa hồ sơ nháp');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'employee_id' => [$partial ? 'sometimes' : 'nullable', 'integer'],
            'insurance_type_id' => [$partial ? 'sometimes' : 'required', 'integer'],
            'claim_code' => ['nullable', 'string', 'max:100'], 'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'total_days' => ['nullable', 'numeric', 'min:0'], 'daily_rate' => ['nullable', 'numeric', 'min:0'], 'total_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_source' => ['nullable', 'string', 'max:100'], 'certificate_number' => ['nullable', 'string', 'max:255'],
            'bank_account' => ['nullable', 'string', 'max:100'], 'bank_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function assertReferences(array $data): void
    {
        if (isset($data['insurance_type_id']) && ! DB::table('insurance_types')->where('tenant_id', TenantContext::id())->where('id', $data['insurance_type_id'])->exists()) {
            throw ValidationException::withMessages(['insurance_type_id' => ['Loại bảo hiểm không thuộc công ty hiện tại']]);
        }
        if (! empty($data['bank_id']) && ! DB::table('banks')->where('tenant_id', TenantContext::id())->where('id', $data['bank_id'])->exists()) {
            throw ValidationException::withMessages(['bank_id' => ['Ngân hàng không thuộc công ty hiện tại']]);
        }
    }

    private function find(Request $request, int $id): ?object
    {
        $query = DB::table('insurance_claims as c')->where('c.id', $id);
        $this->scope($query, $request, 'c');

        return $query->first(['c.*']);
    }

    private function scope($query, Request $request, string $alias): void
    {
        $query->where("{$alias}.tenant_id", TenantContext::id());
        if ($this->access->isAdmin($request)) {
            return;
        }
        if ($this->access->isHr($request) || $this->access->isAccountant($request)) {
            $query->where("{$alias}.legal_entity_id", TenantContext::legalEntityId());
            return;
        }
        if ($this->access->isDepartmentManager($request)) {
            $employeeIds = $this->access->scopeEmployeeQuery(DB::table('employees'), $request)->pluck('employees.id');
            $query->whereIn("{$alias}.employee_id", $employeeIds);
            return;
        }
        $query->where("{$alias}.employee_id", $this->access->actorId($request));
    }

    private function requireCapability(Request $request, string $capability): void
    {
        abort_unless(AccessControl::accessHasCapability((array) $request->attributes->get('access', []), $capability), 403);
    }

    private function meta(mixed $value): array
    {
        $meta = is_string($value) ? json_decode($value, true) : (array) ($value ?? []);
        return is_array($meta) ? $meta : [];
    }

    private function ok(mixed $data, string $message): JsonResponse { return response()->json(['status' => 200, 'message' => $message, 'data' => $data]); }
    private function notFound(): JsonResponse { return response()->json(['status' => 404, 'message' => 'Không tìm thấy hồ sơ bảo hiểm', 'data' => null], 404); }
    private function conflict(string $message): JsonResponse { return response()->json(['status' => 409, 'message' => $message, 'data' => null], 409); }
}

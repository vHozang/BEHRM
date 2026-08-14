<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunPayrollJob;
use App\Models\LegalEntity;
use App\Models\SalaryDetail;
use App\Models\SalaryPeriod;
use App\Services\PayrollRunService;
use App\Services\PayslipIssueService;
use App\Services\PayslipReadinessService;
use App\Support\AccessControl;
use App\Support\HrmConfig;
use App\Support\Notifier;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;

class PayrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->requireAnyCapability($request, [
            'payroll.periods.manage',
            'payroll.run',
            'payroll.amounts.view',
        ]);
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = $this->periodQuery($request)->orderByDesc('id');

        foreach (['status', 'period_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        // Đa pháp nhân: cùng period_code lặp lại theo từng pháp nhân → đính tên để
        // dropdown FE phân biệt (P-2026-07 · Chi nhánh Hà Nội). Bulk pluck, không cần relation.
        $items = $page->items();
        $entityNames = DB::table('legal_entities')
            ->where('tenant_id', TenantContext::id())
            ->pluck('name', 'id');
        foreach ($items as $item) {
            $item->legal_entity_name = $entityNames[$item->legal_entity_id] ?? null;
        }

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Salary periods list');
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'payroll.periods.manage');
        $validator = Validator::make($request->all(), [
            'period_code' => 'required|string|max:80',
            'period_name' => 'nullable|string|max:255',
            'period_type' => 'nullable|string|max:30',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'legal_entity_id' => 'nullable|integer',
        ], [
            'period_code.required' => 'Mã kỳ lương là bắt buộc',
            'start_date.required' => 'Ngày bắt đầu là bắt buộc',
            'end_date.required' => 'Ngày kết thúc là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $entityId = (int) ($request->input('legal_entity_id') ?: TenantContext::legalEntityId());
        if (! $this->canUseLegalEntity($request, $entityId)) {
            return $this->validationError(['legal_entity_id' => ['Pháp nhân không thuộc công ty hiện tại']]);
        }

        $data = $validator->validated();
        $data['period_code'] = strtoupper(trim((string) $data['period_code']));
        $data['period_type'] = strtoupper((string) ($data['period_type'] ?? 'MONTHLY'));
        $data['period_name'] = $data['period_name'] ?? $data['period_code'];
        $data['status'] = 'OPEN';
        $data['tenant_id'] = TenantContext::id();
        $data['legal_entity_id'] = $entityId;
        if ($this->periodConflict($data)) {
            return $this->validationError(['period_code' => ['Kỳ lương đã tồn tại trong pháp nhân này']]);
        }

        $period = SalaryPeriod::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Kỳ lương đã được tạo',
            'data' => $period,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->requireAnyCapability($request, [
            'payroll.periods.manage',
            'payroll.run',
            'payroll.amounts.view',
        ]);
        $period = $this->periodQuery($request)->withCount('salaryDetails')->find($id);

        if (! $period) {
            return $this->notFound();
        }

        return $this->ok($period, 'Salary period detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'payroll.periods.manage');
        $period = $this->periodQuery($request)->find($id);

        if (! $period) {
            return $this->notFound();
        }

        if (strtoupper((string) $period->status) !== 'OPEN') {
            return $this->validationError([
                'status' => ['Chỉ kỳ lương OPEN mới được sửa'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'period_code' => 'sometimes|string|max:80',
            'period_name' => 'sometimes|nullable|string|max:255',
            'period_type' => 'sometimes|string|max:30',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'legal_entity_id' => 'sometimes|integer',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        if ($request->has('status')) {
            return $this->validationError(['status' => ['Dùng thao tác trình/chốt/mở lại thay vì sửa trực tiếp trạng thái']]);
        }

        $data = $validator->validated();
        if (isset($data['period_code'])) {
            $data['period_code'] = strtoupper(trim((string) $data['period_code']));
        }
        if (isset($data['period_type'])) {
            $data['period_type'] = strtoupper((string) $data['period_type']);
        }
        $candidate = array_merge($period->only(['period_code', 'period_type', 'start_date', 'end_date', 'legal_entity_id']), $data, [
            'tenant_id' => $period->tenant_id,
        ]);
        if ((string) $candidate['end_date'] < (string) $candidate['start_date']) {
            return $this->validationError(['end_date' => ['Ngày kết thúc không được trước ngày bắt đầu']]);
        }
        if (! $this->canUseLegalEntity($request, (int) $candidate['legal_entity_id'])) {
            return $this->validationError(['legal_entity_id' => ['Pháp nhân không thuộc công ty hiện tại']]);
        }
        if ($this->periodConflict($candidate, $id)) {
            return $this->validationError(['period_code' => ['Kỳ lương bị trùng trong pháp nhân này']]);
        }

        $period->update($data);

        return $this->ok($period->fresh(), 'Kỳ lương đã được cập nhật');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'payroll.periods.manage');
        $period = $this->periodQuery($request)->find($id);

        if (! $period) {
            return $this->notFound();
        }

        if (strtoupper((string) $period->status) !== 'OPEN') {
            return $this->conflict(['Chỉ kỳ OPEN mới được xóa'], 'Kỳ lương');
        }

        if ($period->salaryDetails()->exists()) {
            return $this->conflict(['Không thể xóa kỳ lương đã có dữ liệu chi tiết'], 'Kỳ lương');
        }

        $period->delete();

        return $this->ok(['id' => $id], 'Kỳ lương đã được xóa');
    }

    public function suggestion(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'payroll.periods.manage');
        $payload = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'legal_entity_id' => ['nullable', 'integer'],
        ]);
        $entityId = (int) ($payload['legal_entity_id'] ?? TenantContext::legalEntityId());
        if (! $this->canUseLegalEntity($request, $entityId)) {
            return $this->validationError(['legal_entity_id' => ['Pháp nhân không thuộc công ty hiện tại']]);
        }
        $entity = LegalEntity::find($entityId);
        $start = Carbon::createFromFormat('Y-m-d', $payload['month'].'-01')->startOfMonth();
        $code = 'P-'.$start->format('Y-m');
        $candidate = [
            'period_code' => $code,
            'period_name' => 'Lương tháng '.$start->format('m/Y'),
            'period_type' => 'MONTHLY',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->endOfMonth()->toDateString(),
            'legal_entity_id' => $entityId,
            'legal_entity_name' => $entity->name,
        ];
        $existing = DB::table('salary_periods')
            ->where('tenant_id', TenantContext::id())
            ->where('legal_entity_id', $entityId)
            ->where('period_type', 'MONTHLY')
            ->where('start_date', $candidate['start_date'])
            ->where('end_date', $candidate['end_date'])
            ->first();
        $candidate['existing_period_id'] = $existing?->id;

        return $this->ok($candidate, 'Gợi ý kỳ lương');
    }

    /**
     * POST /salary-periods/{id}/close â€” ÄÃ³ng/chá»‘t ká»³ lÆ°Æ¡ng.
     */
    /**
     * POST /payroll/bonus-run — Sinh thưởng đợt theo công thức nhà máy ADMS/Aureole:
     *   thưởng = rate% × (LCB + phụ cấp trách nhiệm/chức vụ) × (tháng làm thực tế / số tháng cửa sổ)
     * Ghi vào payroll_adjustments (type BONUS) của kỳ chỉ định — engine lương tự cộng
     * vào gross + thu nhập chịu thuế, KHÔNG vào nền BHXH (đúng phiếu thật + Đ.89).
     * Idempotent theo meta.batch: chạy lại cùng cửa sổ sẽ thay thế batch cũ.
     */
    public function bonusRun(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'payroll.run');
        if (! AccessControl::hasAnyRole(
            (int) $request->attributes->get('auth_employee_id'),
            ['ADMIN', 'TENANT_ADMIN', 'ACCOUNTANT']
        )) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ Kế toán hoặc Admin được chạy thưởng đợt',
                'data' => null,
            ], 403);
        }

        $v = Validator::make($request->all(), [
            'salary_period_id' => 'required|integer|exists:salary_periods,id',
            'window_start' => 'required|date',
            'window_end' => 'required|date|after:window_start',
            'rate_percent' => 'nullable|numeric|min:1|max:300',
        ]);
        if ($v->fails()) {
            return $this->validationError($v->errors()->toArray());
        }

        $periodId = (int) $request->input('salary_period_id');
        $period = SalaryPeriod::find($periodId);
        if (! $period || in_array((string) $period->status, PayrollRunService::LOCKED_PERIOD_STATUSES, true)) {
            return $this->validationError(['salary_period_id' => ['Kỳ lương không tồn tại hoặc đã khóa']]);
        }

        $tenantId = (int) $period->tenant_id;
        $rate = (float) ($request->input('rate_percent') ?? 50) / 100;
        $winStart = new \DateTimeImmutable($request->input('window_start'));
        $winEnd = new \DateTimeImmutable($request->input('window_end'));
        $windowMonths = max(1, round(($winStart->diff($winEnd)->days + 1) / 30.4));
        $batch = 'BONUS-'.$winStart->format('Ymd').'-'.$winEnd->format('Ymd');

        // Chạy lại → thay batch cũ (không nhân đôi thưởng).
        DB::table('payroll_adjustments')->where('tenant_id', $tenantId)
            ->where('paid_period_id', $periodId)->whereRaw("meta->>'batch' = ?", [$batch])->delete();

        $employees = DB::table('employees')->where('tenant_id', $tenantId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])->whereNotNull('base_salary')
            ->get(['id', 'base_salary', 'hire_date', 'legal_entity_id']);

        // Phụ cấp trách nhiệm/chức vụ (PC-CV) — thành phần thứ 2 của công thức ADMS.
        $respAllow = DB::table('employee_allowances as ea')
            ->join('allowances as a', 'a.id', '=', 'ea.allowance_id')
            ->where('ea.tenant_id', $tenantId)->where('ea.is_active', DB::raw('true'))
            ->where('a.allowance_code', 'PC-CV')
            ->pluck('ea.amount', 'ea.employee_id');

        $rows = [];
        $total = 0.0;
        foreach ($employees as $e) {
            // Tháng làm thực tế trong cửa sổ (theo hire_date; ponytail: chưa trừ
            // tháng nghỉ không lương/thai sản — bổ sung khi HR cần chính xác từng người).
            $from = max($winStart, new \DateTimeImmutable((string) ($e->hire_date ?: '1900-01-01')));
            if ($from > $winEnd) {
                continue;
            }
            $months = min($windowMonths, max(0, round(($from->diff($winEnd)->days + 1) / 30.4)));
            if ($months <= 0) {
                continue;
            }
            $amount = round(((float) $e->base_salary + (float) ($respAllow[$e->id] ?? 0)) * $rate * $months / $windowMonths, 0);
            if ($amount <= 0) {
                continue;
            }
            $rows[] = [
                'employee_id' => $e->id, 'paid_period_id' => $periodId,
                'adjustment_type' => 'BONUS', 'amount' => $amount, 'status' => 'APPLIED',
                'meta' => json_encode(['batch' => $batch, 'rate' => $rate, 'months' => $months,
                    'window_months' => $windowMonths, 'formula' => 'ADMS: rate×(LCB+PC-CV)×months/window'], JSON_UNESCAPED_UNICODE),
                'tenant_id' => $tenantId, 'legal_entity_id' => $e->legal_entity_id,
                'created_at' => now(), 'updated_at' => now(),
            ];
            $total += $amount;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('payroll_adjustments')->insert($chunk);
        }

        return $this->ok([
            'batch' => $batch, 'employees' => count($rows), 'total' => $total,
            'note' => 'Chạy lại Tính lương kỳ này để thưởng vào bảng lương',
        ], 'Đã sinh thưởng đợt cho '.count($rows).' nhân viên');
    }

    public function submitPeriod(
        Request $request,
        int $id,
        PayslipReadinessService $readinessService,
        PayslipIssueService $issueService
    ): JsonResponse {
        $this->requireCapability($request, 'payroll.periods.manage');
        // Kế toán TRÌNH chốt kỳ (maker của maker–checker): OPEN → CHỜ_DUYỆT,
        // ghi người trình vào meta, báo ADMIN vào duyệt.
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
        }
        if ($period->isClosed() || (string) $period->status === 'CHỜ_DUYỆT') {
            return $this->validationError(['status' => ['Kỳ lương đã chốt hoặc đang chờ duyệt']]);
        }
        if (! $period->salaryDetails()->exists()) {
            return $this->validationError(['status' => ['Kỳ lương chưa có dữ liệu — hãy tính lương trước khi trình chốt']]);
        }

        $submitterId = (int) $request->attributes->get('auth_employee_id');
        if (! AccessControl::hasAnyRole($submitterId, ['ADMIN', 'TENANT_ADMIN', 'ACCOUNTANT'])) {
            return response()->json([
                'status' => 403,
                'message' => 'Chỉ Kế toán hoặc Admin được trình chốt kỳ lương',
                'data' => null,
            ], 403);
        }
        $readiness = $readinessService->analyze($period);
        $allowPartial = $request->boolean('allow_partial');
        $issueService->syncPayrollIssues($period, $readiness, $submitterId, $allowPartial);
        if (! empty($readiness['has_non_bypassable_issues'])) {
            return $this->mandatoryReadinessRequired($readiness);
        }
        if ($readiness['fail_count'] > 0 && ! $allowPartial) {
            return $this->partialConfirmationRequired($readiness);
        }

        $meta = is_string($period->meta) ? (json_decode($period->meta, true) ?: []) : (array) ($period->meta ?? []);
        $meta['submitted_by'] = $submitterId;
        $meta['submitted_at'] = now()->toIso8601String();
        $snapshot = $this->readinessSnapshot($readiness, $submitterId, $allowPartial, 'SUBMIT');
        $meta['payslip_readiness'] = $snapshot;
        if (! isset($meta['payslip_readiness_audit']) || ! is_array($meta['payslip_readiness_audit'])) {
            $meta['payslip_readiness_audit'] = [];
        }
        $meta['payslip_readiness_audit'][] = $snapshot;
        $period->update(['status' => 'CHỜ_DUYỆT', 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);

        Notifier::notifyMany(
            $this->adminIds((int) $period->tenant_id),
            'Chờ duyệt chốt kỳ lương',
            "Kỳ {$period->period_code} đã được trình chốt. Vào trang Lương để duyệt.",
            'salary_period', $period->id, ['priority' => 'high'], $submitterId
        );

        $fresh = $period->fresh();
        $fresh->setAttribute('payslip_readiness', $readiness);

        return $this->ok($fresh, 'Đã trình chốt kỳ — chờ duyệt');
    }

    public function reopenPeriod(Request $request, int $id): JsonResponse
    {
        $this->requireCapability($request, 'payroll.periods.manage');
        // Trả kỳ về Đang mở: người trình thu hồi, hoặc admin trả về để tính lại.
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
        }
        if ((string) $period->status !== 'CHỜ_DUYỆT') {
            return $this->validationError(['status' => ['Chỉ kỳ đang chờ duyệt mới trả về được']]);
        }

        $callerId = (int) $request->attributes->get('auth_employee_id');
        $meta = is_string($period->meta) ? (json_decode($period->meta, true) ?: []) : (array) ($period->meta ?? []);
        $submitterId = (int) ($meta['submitted_by'] ?? 0);
        if ($callerId !== $submitterId && ! $this->isAdminEmployee($callerId)) {
            return response()->json(['status' => 403, 'message' => 'Chỉ người trình hoặc admin mới trả kỳ về', 'data' => null], 403);
        }

        unset($meta['submitted_by'], $meta['submitted_at']);
        $period->update(['status' => 'OPEN', 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        if ($submitterId && $callerId !== $submitterId) {
            Notifier::notify($submitterId, 'Kỳ lương bị trả về',
                "Kỳ {$period->period_code} được trả về Đang mở".($request->input('comment') ? ': '.$request->input('comment') : '.'),
                'salary_period', $period->id, ['priority' => 'high'], $callerId);
        }

        return $this->ok($period->fresh(), 'Kỳ lương đã trả về Đang mở');
    }

    public function closePeriod(
        Request $request,
        int $id,
        PayslipReadinessService $readinessService,
        PayslipIssueService $issueService
    ): JsonResponse {
        // DUYỆT & chốt (checker): chỉ từ CHỜ_DUYỆT, người duyệt là ADMIN và KHÁC người trình.
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
        }

        if ($period->isClosed()) {
            return $this->validationError(['status' => ['Kỳ lương đã được chốt']]);
        }

        if ((string) $period->status !== 'CHỜ_DUYỆT') {
            return $this->validationError(['status' => ['Kỳ lương chưa được trình duyệt — kế toán cần Trình chốt kỳ trước']]);
        }

        $approverId = (int) $request->attributes->get('auth_employee_id');
        $pmeta = is_string($period->meta) ? (json_decode($period->meta, true) ?: []) : (array) ($period->meta ?? []);
        $submitterId = (int) ($pmeta['submitted_by'] ?? 0);
        if ($submitterId && $approverId === $submitterId) {
            return $this->validationError(['approver_id' => ['Người trình không thể tự duyệt chốt kỳ của mình']]);
        }
        if (! $this->isAdminEmployee($approverId)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền duyệt chốt kỳ lương', 'data' => null], 403);
        }
        $readiness = $readinessService->analyze($period);
        $allowPartial = $request->boolean('allow_partial');
        $issueService->syncPayrollIssues($period, $readiness, $approverId, $allowPartial);
        if (! empty($readiness['has_non_bypassable_issues'])) {
            return $this->mandatoryReadinessRequired($readiness);
        }
        if ($readiness['fail_count'] > 0 && ! $allowPartial) {
            return $this->partialConfirmationRequired($readiness);
        }

        $snapshot = $this->readinessSnapshot($readiness, $approverId, $allowPartial, 'CLOSE');
        $pmeta['payslip_readiness'] = $snapshot;
        if (! isset($pmeta['payslip_readiness_audit']) || ! is_array($pmeta['payslip_readiness_audit'])) {
            $pmeta['payslip_readiness_audit'] = [];
        }
        $pmeta['payslip_readiness_audit'][] = $snapshot;

        DB::transaction(function () use ($period, $pmeta): void {
            $period->update([
                'status' => 'CLOSED',
                'meta' => json_encode($pmeta, JSON_UNESCAPED_UNICODE),
            ]);

            foreach (DB::table('salary_details')->where('period_id', $period->id)->get(['id', 'meta']) as $detail) {
                $meta = is_string($detail->meta) ? (json_decode($detail->meta, true) ?: []) : (array) ($detail->meta ?? []);
                $meta['locked'] = true;
                DB::table('salary_details')->where('id', $detail->id)->update([
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        });

        if ($submitterId) {
            Notifier::notify(
                $submitterId,
                'Kỳ lương đã được chốt',
                "Kỳ {$period->period_code} đã được duyệt và chốt.",
                'salary_period',
                $period->id,
                ['priority' => 'normal'],
                $approverId
            );
        }
        if ($readiness['fail_count'] > 0) {
            Notifier::notifyMany(
                $this->hrIds((int) $period->tenant_id),
                'Có phiếu lương chưa đủ điều kiện phát hành',
                "Kỳ {$period->period_code} có {$readiness['fail_count']} nhân viên cần HR/Kế toán xử lý.",
                'payslip_issue',
                $period->id,
                ['priority' => 'high'],
                $approverId
            );
        }

        $fresh = $period->fresh();
        $fresh->setAttribute('payslip_readiness', $readiness);

        return $this->ok($fresh, 'Kỳ lương đã được chốt');
    }

    /**
     * GET /salary-details â€” Danh sÃ¡ch chi tiáº¿t lÆ°Æ¡ng.
     */
    public function salaryDetails(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = SalaryDetail::with([
            // profile: FE cần bank_name/bank_account để xuất file chuyển khoản ngân hàng.
            'employee:id,full_name,employee_code,department_id,profile',
            'employee.department:id,department_name',
            'period:id,period_code,status,end_date',
            'payslipDocument:id,salary_detail_id,generation_status,email_status,published_at,sent_at,last_error',
        ])->orderByDesc('id');

        $access = $request->attributes->get('access') ?: [];
        $canManagePayroll = ! empty($access['full']) || in_array('payroll', $access['modules'] ?? [], true);
        if (! $canManagePayroll) {
            $query->whereHas('period', fn ($period) => $period->whereIn('status', ['CLOSED', 'ĐÃ_ĐÓNG', 'DA_DONG']))
                ->whereHas('payslipDocument', fn ($document) => $document
                    ->where('generation_status', 'READY')
                    ->whereNotNull('published_at'));
        }

        foreach (['employee_id', 'period_id', 'transfer_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Salary details list');
    }

    /**
     * GET /salary-details/{id}/payslip â€” Báº£ng lÆ°Æ¡ng chi tiáº¿t kÃ¨m breakdowns.
     */
    public function payslip(int $id): JsonResponse
    {
        $detail = SalaryDetail::with([
            'employee:id,full_name,employee_code,company_email,department_id,position_id,legal_entity_id,profile',
            'employee.department:id,department_code,department_name',
            'employee.position:id,position_code,position_name',
            'contract:id,contract_number,contract_type_id,start_date,end_date,status,meta',
            'period:id,period_code,period_name,period_type,start_date,end_date,status,legal_entity_id',
        ])->find($id);

        if (! $detail) {
            return $this->notFound();
        }

        $authEmployeeId = (int) request()->attributes->get('auth_employee_id');
        $access = request()->attributes->get('access') ?: [];
        $canManagePayroll = ! empty($access['full']) || in_array('payroll', $access['modules'] ?? [], true);
        $document = $detail->payslipDocument;
        if ($authEmployeeId && ! $canManagePayroll
            && ((int) $detail->employee_id !== $authEmployeeId
                || ! $detail->period?->isClosed()
                || ! $document
                || $document->generation_status !== 'READY'
                || ! $document->published_at)) {
            return response()->json([
                'status' => 403,
                'message' => 'Phiếu lương chưa được phát hành hoặc không thuộc tài khoản của bạn.',
                'data' => null,
            ], 403);
        }

        $data = [
            'salary_detail' => $detail,
            'legal_entity' => LegalEntity::query()
                ->select(['id', 'name', 'code', 'tax_code', 'address', 'status', 'meta'])
                ->find($detail->legal_entity_id),
            'breakdowns' => DB::table('salary_breakdowns')
                ->where('salary_detail_id', $id)
                ->orderBy('item_type')
                ->get(),
            // Summary công tháng khớp theo (nhân viên, kỳ) — bảng không có cột
            // salary_detail_id (query cũ theo cột đó luôn lỗi SQL).
            'attendance_summary' => DB::table('salary_attendance_summary')
                ->where('employee_id', $detail->employee_id)
                ->where('period_id', $detail->period_id)
                ->first(),
            // Phiếu lương custom theo công ty — chỉnh trong Settings (payslip.*).
            'config' => [
                'title' => (string) HrmConfig::get('payslip.title', 'PHIẾU LƯƠNG THÁNG'),
                'footer' => (string) HrmConfig::get('payslip.footer', 'Công ty xin chân thành cảm ơn toàn thể nhân viên! Đừng quên đối chiếu số tiền trong tài khoản của bạn.'),
                'show_allowance_detail' => (bool) HrmConfig::get('payslip.show_allowance_detail', true),
                'show_ot_detail' => (bool) HrmConfig::get('payslip.show_ot_detail', true),
                'show_insurance_base' => (bool) HrmConfig::get('payslip.show_insurance_base', true),
                'show_relief' => (bool) HrmConfig::get('payslip.show_relief', true),
                'show_work_days' => (bool) HrmConfig::get('payslip.show_work_days', true),
                'show_employer_cost' => (bool) HrmConfig::get('payslip.show_employer_cost', false),
            ],
            'document' => $document ? [
                'id' => $document->id,
                'generation_status' => $document->generation_status,
                'email_status' => $document->email_status,
                'filename' => $document->filename,
                'published_at' => $document->published_at,
                'sent_at' => $document->sent_at,
                'last_error' => $document->last_error,
            ] : null,
        ];

        return $this->ok($data, 'Payslip detail');
    }

    /**
     * POST /salary-details
     */
    public function storeSalaryDetail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period_id' => 'required|exists:salary_periods,id',
            'employee_id' => 'required|exists:employees,id',
            'contract_id' => 'nullable|exists:contracts,id',
            'gross_salary' => 'required|numeric|min:0',
            'net_salary' => 'required|numeric|min:0',
            'transfer_status' => 'sometimes|string',
        ], [
            'period_id.required' => 'MÃ£ ká»³ lÆ°Æ¡ng lÃ  báº¯t buá»™c',
            'employee_id.required' => 'MÃ£ nhÃ¢n viÃªn lÃ  báº¯t buá»™c',
            'gross_salary.required' => 'LÆ°Æ¡ng gá»™p lÃ  báº¯t buá»™c',
            'net_salary.required' => 'LÆ°Æ¡ng thá»±c nháº­n lÃ  báº¯t buá»™c',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('salary_details');
        $payload = [];
        $meta = [];

        foreach ($request->except(['id', 'created_at', 'updated_at']) as $key => $value) {
            if (in_array($key, $columns, true)) {
                $payload[$key] = $value;
            } else {
                $meta[$key] = $value;
            }
        }

        if (! empty($meta)) {
            $payload['meta'] = json_encode($meta);
        }

        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $detail = SalaryDetail::create($payload);

        return response()->json([
            'status' => 201,
            'message' => 'Chi tiáº¿t lÆ°Æ¡ng Ä‘Ã£ Ä‘Æ°á»£c táº¡o',
            'data' => $detail->fresh()->load(['employee:id,full_name,employee_code', 'period:id,period_code,status']),
        ], 201);
    }

    /**
     * PUT /salary-details/{id}
     */
    public function updateSalaryDetail(Request $request, int $id): JsonResponse
    {
        $detail = SalaryDetail::find($id);

        if (! $detail) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'period_id' => 'sometimes|exists:salary_periods,id',
            'employee_id' => 'sometimes|exists:employees,id',
            'contract_id' => 'nullable|exists:contracts,id',
            'gross_salary' => 'sometimes|numeric|min:0',
            'net_salary' => 'sometimes|numeric|min:0',
            'transfer_status' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('salary_details');
        $payload = [];
        $meta = [];

        foreach ($request->except(['id', 'created_at', 'updated_at']) as $key => $value) {
            if (in_array($key, $columns, true)) {
                $payload[$key] = $value;
            } else {
                $meta[$key] = $value;
            }
        }

        if (! empty($meta)) {
            $existingMeta = [];
            if ($detail->meta) {
                $existingMeta = is_string($detail->meta) ? json_decode($detail->meta, true) : (array) $detail->meta;
                if (! is_array($existingMeta)) {
                    $existingMeta = [];
                }
            }
            $mergedMeta = array_merge($existingMeta, $meta);
            $payload['meta'] = json_encode($mergedMeta);
        }

        $payload['updated_at'] = now();

        $detail->update($payload);

        return $this->ok($detail->fresh()->load(['employee:id,full_name,employee_code', 'period:id,period_code,status']), 'Cáº­p nháº­t thÃ nh cÃ´ng');
    }

    // â”€â”€ Response Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * POST /payroll/run — Tính lương cho một kỳ (VN payroll engine).
     *
     * Refuses (409) when the period is closed/locked. Idempotent for open periods.
     */
    public function run(Request $request, PayrollRunService $service): JsonResponse
    {
        $this->requireCapability($request, 'payroll.run');
        $validator = Validator::make($request->all(), [
            'salary_period_id' => 'required|integer|exists:salary_periods,id',
        ], [
            'salary_period_id.required' => 'Mã kỳ lương là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $periodId = (int) $request->input('salary_period_id');
        $period = $this->periodQuery($request)->where('id', $periodId)->first();
        if (! $period) {
            return $this->notFound('Không tìm thấy kỳ lương');
        }

        // Pre-check kỳ khóa → 409 NGAY (khỏi đưa vào hàng đợi). Dùng chung hằng với
        // PayrollRunService — trước đây chép tay nên thiếu CHỜ_DUYỆT (maker–checker).
        if (in_array((string) $period->status, PayrollRunService::LOCKED_PERIOD_STATUSES, true)) {
            return response()->json([
                'status' => 409,
                'message' => 'Kỳ lương đã khóa ('.$period->status.') — không thể tính lại',
                'data' => null,
            ], 409);
        }

        // Đang chạy dở → không dispatch chồng.
        $existing = Cache::get(RunPayrollJob::statusKey($periodId));
        if (($existing['status'] ?? null) === 'PROCESSING') {
            return response()->json([
                'status' => 202,
                'message' => 'Kỳ này đang được tính, vui lòng chờ',
                'data' => array_merge(['period_id' => $periodId, 'run_status' => 'PROCESSING'], $existing),
            ], 202);
        }

        // Số NV để hiển thị tiến độ.
        $total = (int) DB::table('employees')
            ->where('tenant_id', $period->tenant_id)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->count();

        Cache::put(RunPayrollJob::statusKey($periodId), [
            'status' => 'PROCESSING',
            'total' => $total,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        // Chạy NỀN: trả về ngay, worker xử lý tính lương phía sau (không timeout).
        RunPayrollJob::dispatch(
            $periodId,
            (int) $period->tenant_id,
            $period->legal_entity_id !== null ? (int) $period->legal_entity_id : null,
        );

        return response()->json([
            'status' => 202,
            'message' => 'Đã đưa vào hàng đợi tính lương — đang xử lý nền',
            'data' => ['period_id' => $periodId, 'total' => $total, 'run_status' => 'PROCESSING'],
        ], 202);
    }

    /**
     * GET /payroll/run-status?salary_period_id= — FE poll tiến độ tính lương nền.
     */
    public function runStatus(Request $request): JsonResponse
    {
        $this->requireCapability($request, 'payroll.run');
        $periodId = (int) ($request->query('salary_period_id') ?: $request->query('period_id'));
        if (! $periodId) {
            return $this->validationError(['salary_period_id' => ['Thiếu mã kỳ lương']]);
        }
        if (! $this->periodQuery($request)->where('id', $periodId)->exists()) {
            return $this->notFound('Không tìm thấy kỳ lương');
        }

        $status = Cache::get(RunPayrollJob::statusKey($periodId));
        if (! $status) {
            return $this->ok(['period_id' => $periodId, 'run_status' => 'IDLE'], 'Chưa có lần chạy nào');
        }

        return $this->ok(array_merge(['period_id' => $periodId, 'run_status' => $status['status']], $status), 'Trạng thái tính lương');
    }

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
            'message' => "KhÃ´ng thá»ƒ xÃ³a {$resourceName} do vi pháº¡m rÃ ng buá»™c nghiá»‡p vá»¥",
            'data' => ['violations' => $violations],
        ], 409);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Dá»¯ liá»‡u khÃ´ng há»£p lá»‡',
            'data' => ['errors' => $errors],
        ], 422);
    }

    private function partialConfirmationRequired(array $readiness): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Có nhân viên chưa đủ điều kiện phát hành phiếu lương.',
            'data' => [
                'errors' => [
                    'allow_partial' => ['Kiểm tra danh sách lỗi và xác nhận phát hành một phần.'],
                ],
                'readiness' => $readiness,
            ],
        ], 422);
    }

    private function mandatoryReadinessRequired(array $readiness): JsonResponse
    {
        return response()->json([
            'status' => 422,
            'message' => 'Kỳ lương còn review chấm công hoặc lỗi OT bắt buộc xử lý.',
            'data' => [
                'errors' => [
                    'readiness' => ['Không thể bỏ qua các lỗi này bằng allow_partial.'],
                ],
                'readiness' => $readiness,
            ],
        ], 422);
    }

    private function readinessSnapshot(
        array $readiness,
        int $actorId,
        bool $allowPartial,
        string $phase
    ): array {
        return [
            'phase' => $phase,
            'checked_at' => now()->toIso8601String(),
            'checked_by' => $actorId,
            'allow_partial' => $allowPartial,
            'total_employees' => $readiness['total_employees'],
            'pass_count' => $readiness['pass_count'],
            'fail_count' => $readiness['fail_count'],
            'excluded_employee_ids' => collect($readiness['issues'])->pluck('employee_id')->unique()->values()->all(),
            'exclusions' => collect($readiness['issues'])->map(fn (array $issue) => [
                'employee_id' => $issue['employee_id'],
                'salary_detail_id' => $issue['salary_detail_id'],
                'employee_code' => $issue['employee_code'],
                'full_name' => $issue['full_name'],
                'department_name' => $issue['department_name'],
                'issue_code' => $issue['issue_code'],
                'message' => $issue['message'],
                'resolution_hint' => $issue['resolution_hint'],
            ])->values()->all(),
        ];
    }

    /** Nhân viên giữ role ADMIN (hoặc super-admin) — người được duyệt chốt kỳ. */
    private function isAdminEmployee(?int $employeeId): bool
    {
        if (! $employeeId) {
            return false;
        }
        if (DB::table('employees')->where('id', $employeeId)->value('is_super_admin')) {
            return true;
        }

        return DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.employee_id', $employeeId)
            ->whereRaw('er.is_active = true')
            ->where(fn ($q) => $q->where('r.role_code', 'ADMIN')->orWhereRaw("r.meta->>'is_admin' = 'true'"))
            ->exists();
    }

    /** id các nhân viên giữ role ADMIN của tenant — người nhận thông báo trình duyệt. */
    private function adminIds(int $tenantId): array
    {
        return DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.tenant_id', $tenantId)
            ->whereRaw('er.is_active = true')
            ->where('r.role_code', 'ADMIN')
            ->pluck('er.employee_id')->unique()->values()->all();
    }

    private function hrIds(int $tenantId): array
    {
        return DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.tenant_id', $tenantId)
            ->whereRaw('er.is_active = true')
            ->whereIn('r.role_code', ['HR', 'ADMIN'])
            ->pluck('er.employee_id')->unique()->values()->all();
    }

    private function requireCapability(Request $request, string $capability): void
    {
        abort_unless(
            AccessControl::accessHasCapability((array) $request->attributes->get('access', []), $capability),
            403,
        );
    }

    /** @param array<int, string> $capabilities */
    private function requireAnyCapability(Request $request, array $capabilities): void
    {
        $access = (array) $request->attributes->get('access', []);
        abort_unless(
            collect($capabilities)->contains(
                fn (string $capability): bool => AccessControl::accessHasCapability($access, $capability),
            ),
            403,
        );
    }

    private function periodQuery(Request $request)
    {
        return SalaryPeriod::query()->when(
            ! $this->isAdminRequest($request),
            fn ($query) => $query->where('legal_entity_id', TenantContext::legalEntityId()),
        );
    }

    private function canUseLegalEntity(Request $request, int $legalEntityId): bool
    {
        if (! LegalEntity::find($legalEntityId)) {
            return false;
        }

        return $this->isAdminRequest($request)
            || $legalEntityId === (int) TenantContext::legalEntityId();
    }

    private function isAdminRequest(Request $request): bool
    {
        $access = (array) $request->attributes->get('access', []);
        if (! empty($access['full'])) {
            return true;
        }

        return collect($access['roles'] ?? [])->contains(
            fn ($role): bool => is_array($role)
                && in_array(strtoupper((string) ($role['role_code'] ?? '')), ['ADMIN', 'TENANT_ADMIN'], true),
        );
    }

    /** @param array<string, mixed> $data */
    private function periodConflict(array $data, ?int $ignoreId = null): bool
    {
        $tenantId = (int) ($data['tenant_id'] ?? TenantContext::id());
        $entityId = (int) ($data['legal_entity_id'] ?? TenantContext::legalEntityId());
        $code = strtoupper(trim((string) ($data['period_code'] ?? '')));
        $type = strtoupper(trim((string) ($data['period_type'] ?? 'MONTHLY')));

        $base = DB::table('salary_periods')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $entityId)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '<>', $ignoreId));

        return (clone $base)->where('period_code', $code)->exists()
            || (clone $base)
                ->where('period_type', $type)
                ->whereDate('start_date', (string) $data['start_date'])
                ->whereDate('end_date', (string) $data['end_date'])
                ->exists();
    }
}

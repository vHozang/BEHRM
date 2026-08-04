<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Models\SalaryDetail;
use App\Models\SalaryPeriod;
use App\Services\PayrollRunService;
use App\Support\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class PayrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = SalaryPeriod::orderByDesc('id');

        foreach (['status', 'period_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        $page = $query->paginate($perPage);

        // Đa pháp nhân: cùng period_code lặp lại theo từng pháp nhân → đính tên để
        // dropdown FE phân biệt (P-2026-07 · Chi nhánh Hà Nội). Bulk pluck, không cần relation.
        $items = $page->items();
        $entityNames = \Illuminate\Support\Facades\DB::table('legal_entities')->pluck('name', 'id');
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
        $validator = Validator::make($request->all(), [
            'period_code' => 'required|string|unique:salary_periods,period_code',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ], [
            'period_code.required' => 'MÃ£ ká»³ lÆ°Æ¡ng lÃ  báº¯t buá»™c',
            'period_code.unique' => 'MÃ£ ká»³ lÆ°Æ¡ng Ä‘Ã£ tá»“n táº¡i',
            'start_date.required' => 'NgÃ y báº¯t Ä‘áº§u lÃ  báº¯t buá»™c',
            'end_date.required' => 'NgÃ y káº¿t thÃºc lÃ  báº¯t buá»™c',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Entity targeting: a supplied legal_entity_id must belong to the
        // current tenant. If absent, the BelongsToTenant trait defaults it.
        if ($request->filled('legal_entity_id')) {
            $entityId = (int) $request->input('legal_entity_id');
            if (! LegalEntity::find($entityId)) {
                return $this->validationError([
                    'legal_entity_id' => ['legal_entity_id không thuộc công ty hiện tại'],
                ]);
            }
        }

        $columns = Schema::getColumnListing('salary_periods');
        $data = collect($request->all())->only($columns)->toArray();
        $data['status'] = $data['status'] ?? 'OPEN';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $period = SalaryPeriod::create($data);

        return response()->json([
            'status' => 201,
            'message' => 'Ká»³ lÆ°Æ¡ng Ä‘Ã£ Ä‘Æ°á»£c táº¡o',
            'data' => $period,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $period = SalaryPeriod::withCount('salaryDetails')->find($id);

        if (! $period) {
            return $this->notFound();
        }

        return $this->ok($period, 'Salary period detail');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
        }

        if ($period->isClosed()) {
            return $this->validationError([
                'status' => ['KhÃ´ng thá»ƒ sá»­a ká»³ lÆ°Æ¡ng Ä‘Ã£ chá»‘t'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'period_code' => "nullable|string|unique:salary_periods,period_code,{$id}",
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('salary_periods');
        $data = collect($request->except(['id', 'created_at', 'updated_at']))->only($columns)->toArray();

        $period->update($data);

        // Dong bo: ky chuyen sang DA TRA (PAID) nghia la tien da chuyen cho toan
        // bo nhan vien -> moi phieu luong con PENDING phai thanh PAID (tranh lech
        // kieu 19 phieu PAID + 1 phieu "cho chuyen" mo coi trong ky da tra).
        $newStatus = strtoupper((string) ($data['status'] ?? ''));
        if (in_array($newStatus, ['PAID', 'DA_TRA'], true) || $newStatus === 'ĐÃ_TRẢ') {
            DB::table('salary_details')
                ->where('period_id', $period->id)
                ->where('transfer_status', 'PENDING')
                ->update(['transfer_status' => 'PAID', 'updated_at' => now()]);
        }

        return $this->ok($period->fresh(), 'Ká»³ lÆ°Æ¡ng Ä‘Ã£ Ä‘Æ°á»£c cáº­p nháº­t');
    }

    public function destroy(int $id): JsonResponse
    {
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
        }

        if ($period->isClosed()) {
            return $this->conflict(['KhÃ´ng thá»ƒ xÃ³a ká»³ lÆ°Æ¡ng Ä‘Ã£ chá»‘t'], 'Ká»³ lÆ°Æ¡ng');
        }

        if ($period->salaryDetails()->exists()) {
            return $this->conflict(['KhÃ´ng thá»ƒ xÃ³a ká»³ lÆ°Æ¡ng Ä‘Ã£ cÃ³ dá»¯ liá»‡u chi tiáº¿t lÆ°Æ¡ng'], 'Ká»³ lÆ°Æ¡ng');
        }

        $period->delete();

        return $this->ok(['id' => $id], 'Ká»³ lÆ°Æ¡ng Ä‘Ã£ Ä‘Æ°á»£c xÃ³a');
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

    public function submitPeriod(Request $request, int $id): JsonResponse
    {
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

        $submitterId = $request->attributes->get('auth_employee_id');
        $meta = is_string($period->meta) ? (json_decode($period->meta, true) ?: []) : (array) ($period->meta ?? []);
        $meta['submitted_by'] = $submitterId;
        $meta['submitted_at'] = now()->toIso8601String();
        $period->update(['status' => 'CHỜ_DUYỆT', 'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);

        \App\Support\Notifier::notifyMany(
            $this->adminIds((int) $period->tenant_id),
            'Chờ duyệt chốt kỳ lương',
            "Kỳ {$period->period_code} đã được trình chốt. Vào trang Lương để duyệt.",
            'salary_period', $period->id, ['priority' => 'high'], $submitterId
        );

        return $this->ok($period->fresh(), 'Đã trình chốt kỳ — chờ duyệt');
    }

    public function reopenPeriod(Request $request, int $id): JsonResponse
    {
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
            \App\Support\Notifier::notify($submitterId, 'Kỳ lương bị trả về',
                "Kỳ {$period->period_code} được trả về Đang mở" . ($request->input('comment') ? ': '.$request->input('comment') : '.'),
                'salary_period', $period->id, ['priority' => 'high'], $callerId);
        }

        return $this->ok($period->fresh(), 'Kỳ lương đã trả về Đang mở');
    }

    public function closePeriod(Request $request, int $id): JsonResponse
    {
        // DUYỆT & chốt (checker): chỉ từ CHỜ_DUYỆT, người duyệt là ADMIN và KHÁC người trình.
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
        }

        if ((string) $period->status !== 'CHỜ_DUYỆT' && ! $period->isClosed()) {
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
        if ($submitterId) {
            \App\Support\Notifier::notify($submitterId, 'Kỳ lương đã được chốt',
                "Kỳ {$period->period_code} đã được duyệt và chốt.", 'salary_period', $period->id, ['priority' => 'normal'], $approverId);
        }

        if ($period->isClosed()) {
            return $this->validationError(['status' => ['Ká»³ lÆ°Æ¡ng Ä‘Ã£ Ä‘Æ°á»£c chá»‘t']]);
        }

        DB::transaction(function () use ($period) {
            $period->update(['status' => 'CLOSED']);

            // Lock all salary details. There is no is_locked column; the lock
            // lives in meta->locked (jsonb) and the period's CLOSED status is the
            // authoritative gate that PayrollRunService checks before recomputing.
            DB::statement(
                "UPDATE salary_details
                    SET meta = jsonb_set(COALESCE(meta, '{}'::jsonb), '{locked}', 'true'::jsonb, true),
                        updated_at = now()
                  WHERE period_id = ?",
                [$period->id]
            );
        });

        return $this->ok($period->fresh(), 'Ká»³ lÆ°Æ¡ng Ä‘Ã£ Ä‘Æ°á»£c chá»‘t');
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
        ])->orderByDesc('id');

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
                'title' => (string) \App\Support\HrmConfig::get('payslip.title', 'PHIẾU LƯƠNG THÁNG'),
                'footer' => (string) \App\Support\HrmConfig::get('payslip.footer', 'Công ty xin chân thành cảm ơn toàn thể nhân viên! Đừng quên đối chiếu số tiền trong tài khoản của bạn.'),
                'show_allowance_detail' => (bool) \App\Support\HrmConfig::get('payslip.show_allowance_detail', true),
                'show_ot_detail' => (bool) \App\Support\HrmConfig::get('payslip.show_ot_detail', true),
                'show_insurance_base' => (bool) \App\Support\HrmConfig::get('payslip.show_insurance_base', true),
                'show_relief' => (bool) \App\Support\HrmConfig::get('payslip.show_relief', true),
                'show_work_days' => (bool) \App\Support\HrmConfig::get('payslip.show_work_days', true),
                'show_employer_cost' => (bool) \App\Support\HrmConfig::get('payslip.show_employer_cost', false),
            ],
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
        $validator = Validator::make($request->all(), [
            'salary_period_id' => 'required|integer|exists:salary_periods,id',
        ], [
            'salary_period_id.required' => 'Mã kỳ lương là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $periodId = (int) $request->input('salary_period_id');
        $period = DB::table('salary_periods')->where('id', $periodId)->first();
        if (! $period) {
            return $this->notFound('Không tìm thấy kỳ lương');
        }

        // Pre-check kỳ khóa → 409 NGAY (khỏi đưa vào hàng đợi). Dùng chung hằng với
        // PayrollRunService — trước đây chép tay nên thiếu CHỜ_DUYỆT (maker–checker).
        if (in_array((string) $period->status, PayrollRunService::LOCKED_PERIOD_STATUSES, true)) {
            return response()->json([
                'status' => 409,
                'message' => 'Kỳ lương đã khóa (' . $period->status . ') — không thể tính lại',
                'data' => null,
            ], 409);
        }

        // Đang chạy dở → không dispatch chồng.
        $existing = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunPayrollJob::statusKey($periodId));
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

        \Illuminate\Support\Facades\Cache::put(\App\Jobs\RunPayrollJob::statusKey($periodId), [
            'status' => 'PROCESSING',
            'total' => $total,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        // Chạy NỀN: trả về ngay, worker xử lý tính lương phía sau (không timeout).
        \App\Jobs\RunPayrollJob::dispatch(
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
        $periodId = (int) ($request->query('salary_period_id') ?: $request->query('period_id'));
        if (! $periodId) {
            return $this->validationError(['salary_period_id' => ['Thiếu mã kỳ lương']]);
        }

        $status = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunPayrollJob::statusKey($periodId));
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
}

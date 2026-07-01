<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Models\SalaryDetail;
use App\Models\SalaryPeriod;
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

        return $this->ok([
            'items' => $page->items(),
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
    public function closePeriod(int $id): JsonResponse
    {
        $period = SalaryPeriod::find($id);

        if (! $period) {
            return $this->notFound();
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
            'employee:id,full_name,employee_code',
            'period:id,period_code,status',
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
            'employee:id,full_name,employee_code',
            'period:id,period_code',
        ])->find($id);

        if (! $detail) {
            return $this->notFound();
        }

        $data = [
            'salary_detail' => $detail,
            'breakdowns' => DB::table('salary_breakdowns')
                ->where('salary_detail_id', $id)
                ->orderBy('item_type')
                ->get(),
            'attendance_summary' => DB::table('salary_attendance_summary')
                ->where('salary_detail_id', $id)
                ->first(),
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
    public function run(Request $request, \App\Services\PayrollRunService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'salary_period_id' => 'required|integer|exists:salary_periods,id',
        ], [
            'salary_period_id.required' => 'Mã kỳ lương là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $result = $service->run((int) $request->input('salary_period_id'));
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                return $this->notFound('Không tìm thấy kỳ lương');
            }
            if ($e->getCode() === 409) {
                return response()->json([
                    'status' => 409,
                    'message' => $e->getMessage(),
                    'data' => null,
                ], 409);
            }

            throw $e;
        }

        return $this->ok($result, 'Đã tính lương cho kỳ');
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
}


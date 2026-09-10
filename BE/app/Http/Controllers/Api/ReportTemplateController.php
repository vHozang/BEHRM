<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccessControl;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportTemplateController extends Controller
{
    public const DEFINITIONS = [
        'headcount' => ['label' => 'Thống kê nhân sự theo phòng ban', 'columns' => ['department_id', 'department_name', 'headcount'], 'filters' => []],
        'workforce-structure' => ['label' => 'Cơ cấu lao động', 'columns' => ['tieu_chi', 'phan_loai', 'so_nguoi', 'ty_le_phan_tram'], 'filters' => []],
        'leave-summary' => ['label' => 'Tổng hợp nghỉ phép', 'columns' => ['status', 'leave_type_id', 'leave_type_name', 'count', 'total_days'], 'filters' => []],
        'leave-liability' => ['label' => 'Quỹ phép phải trả', 'columns' => ['employee_id', 'employee_code', 'full_name', 'remaining_days', 'daily_rate', 'liability_amount'], 'filters' => []],
        'attendance-summary' => ['label' => 'Tổng hợp chấm công', 'columns' => ['status', 'count'], 'filters' => []],
        'payroll-summary' => ['label' => 'Tổng hợp bảng lương', 'columns' => ['period_id', 'period_code', 'period_name', 'employees', 'total_gross', 'total_net'], 'filters' => []],
        'labor-cost' => ['label' => 'Chi phí lao động theo phòng ban', 'columns' => ['department_id', 'department_name', 'employees', 'gross_salary', 'employer_insurance', 'total_labor_cost'], 'filters' => ['period_id']],
        'bhxh-declaration' => ['label' => 'Tờ khai BHXH theo kỳ', 'columns' => ['employee_code', 'full_name', 'social_insurance_number', 'insurance_salary', 'employee_contribution', 'employer_contribution'], 'filters' => ['period_id']],
        'pit-finalization' => ['label' => 'Quyết toán thuế TNCN', 'columns' => ['employee_code', 'full_name', 'tax_code', 'taxable_income', 'personal_deduction', 'dependent_deduction', 'pit_amount'], 'filters' => ['period_id']],
        'hr-metrics' => ['label' => 'Bảng chỉ số nhân sự', 'columns' => ['nhom', 'chi_so', 'gia_tri', 'don_vi', 'dien_giai'], 'filters' => ['from', 'to']],
    ];

    public function catalog(): JsonResponse
    {
        $items = [];
        foreach (self::DEFINITIONS as $type => $definition) {
            $items[] = ['type' => $type, ...$definition];
        }

        return $this->ok([
            'reports' => $items,
            'charts' => ['NONE', 'TABLE', 'BAR', 'LINE', 'PIE'],
        ], 'Danh mục trình dựng báo cáo');
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $page = DB::table('report_templates')
            ->where('tenant_id', TenantContext::id())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderByDesc('id')->paginate($perPage);

        return $this->ok([
            'items' => collect($page->items())->map(fn ($row) => $this->normalizeTemplate($row))->all(),
            'pagination' => $this->pagination($page),
        ], 'Danh sách mẫu báo cáo');
    }

    public function show(int $id): JsonResponse
    {
        $row = DB::table('report_templates')->where('tenant_id', TenantContext::id())->where('id', $id)->first();

        return $row ? $this->ok($this->normalizeTemplate($row), 'Chi tiết mẫu báo cáo') : $this->notFound();
    }

    public function store(Request $request): JsonResponse
    {
        $this->manage($request);
        $data = $this->validated($request);
        $now = now();
        $id = DB::table('report_templates')->insertGetId(TenantContext::stamp([
            ...$this->payload($data),
            'created_by' => (int) $request->attributes->get('auth_employee_id'),
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return response()->json(['status' => 201, 'message' => 'Đã tạo mẫu báo cáo an toàn', 'data' => ['id' => $id]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->manage($request);
        $row = DB::table('report_templates')->where('tenant_id', TenantContext::id())->where('id', $id)->first();
        if (! $row) return $this->notFound();
        $data = $this->validated($request, $id);
        DB::table('report_templates')->where('tenant_id', TenantContext::id())->where('id', $id)
            ->update([...$this->payload($data), 'updated_at' => now()]);

        return $this->show($id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->manage($request);
        $row = DB::table('report_templates')->where('tenant_id', TenantContext::id())->where('id', $id)->first();
        if (! $row) return $this->notFound();
        $used = DB::table('report_histories')->where('tenant_id', TenantContext::id())->where('template_id', $id)->exists();
        if ($used) {
            DB::table('report_templates')->where('id', $id)->update(['status' => 'ARCHIVED', 'updated_at' => now()]);
            return $this->ok(['id' => $id, 'status' => 'ARCHIVED'], 'Mẫu đã có lịch sử chạy nên được lưu trữ thay vì xóa');
        }
        DB::table('report_templates')->where('tenant_id', TenantContext::id())->where('id', $id)->delete();

        return $this->ok(['id' => $id], 'Đã xóa mẫu báo cáo');
    }

    public function histories(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
        $canManage = AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'reports.templates.manage');
        $page = DB::table('report_histories as h')
            ->leftJoin('report_templates as t', function ($join): void {
                $join->on('t.id', '=', 'h.template_id')->on('t.tenant_id', '=', 'h.tenant_id');
            })
            ->where('h.tenant_id', TenantContext::id())
            ->when(! $canManage, fn ($query) => $query->where('h.executed_by', $request->attributes->get('auth_employee_id')))
            ->when($request->filled('template_id'), fn ($query) => $query->where('h.template_id', $request->query('template_id')))
            ->orderByDesc('h.id')
            ->select(['h.*', 't.template_code', 't.template_name'])
            ->paginate($perPage);

        return $this->ok(['items' => $page->items(), 'pagination' => $this->pagination($page)], 'Lịch sử chạy báo cáo');
    }

    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $canManage = AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'reports.templates.manage');
        $row = DB::table('report_histories')->where('tenant_id', TenantContext::id())->where('id', $id)
            ->when(! $canManage, fn ($query) => $query->where('executed_by', $request->attributes->get('auth_employee_id')))
            ->first();
        if (! $row || ! $row->file_url || ! Storage::disk('local')->exists($row->file_url)) {
            return response()->json(['status' => 404, 'message' => 'File báo cáo không tồn tại hoặc đã hết hạn', 'data' => null], 404);
        }

        return Storage::disk('local')->download($row->file_url, basename($row->file_url), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'template_code' => ['required', 'string', 'max:100'],
            'template_name' => ['required', 'string', 'max:255'],
            'report_type' => ['required', Rule::in(array_keys(self::DEFINITIONS))],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
            'chart' => ['nullable', 'array'],
            'chart.type' => ['nullable', Rule::in(['NONE', 'TABLE', 'BAR', 'LINE', 'PIE'])],
            'chart.x' => ['nullable', 'string'],
            'chart.y' => ['nullable', 'string'],
            'is_public' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ARCHIVED'])],
        ]);
        $definition = self::DEFINITIONS[$data['report_type']];
        $invalidColumns = array_values(array_diff($data['columns'], $definition['columns']));
        if ($invalidColumns !== []) {
            throw ValidationException::withMessages(['columns' => ['Cột không được phép: '.implode(', ', $invalidColumns)]]);
        }
        $filterKeys = array_keys($data['filters'] ?? []);
        $invalidFilters = array_values(array_diff($filterKeys, $definition['filters']));
        if ($invalidFilters !== []) {
            throw ValidationException::withMessages(['filters' => ['Bộ lọc không được phép: '.implode(', ', $invalidFilters)]]);
        }
        $chart = $data['chart'] ?? [];
        foreach (['x', 'y'] as $axis) {
            if (! empty($chart[$axis]) && ! in_array($chart[$axis], $data['columns'], true)) {
                throw ValidationException::withMessages(["chart.{$axis}" => ['Trục biểu đồ phải là một cột đã chọn']]);
            }
        }
        $duplicate = DB::table('report_templates')->where('tenant_id', TenantContext::id())
            ->whereRaw('lower(trim(template_code)) = ?', [mb_strtolower(trim($data['template_code']))])
            ->when($id !== null, fn ($query) => $query->where('id', '!=', $id))->exists();
        if ($duplicate) throw ValidationException::withMessages(['template_code' => ['Mã mẫu báo cáo đã tồn tại']]);

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function payload(array $data): array
    {
        return [
            'template_code' => strtoupper(trim($data['template_code'])),
            'template_name' => trim($data['template_name']),
            'report_type' => $data['report_type'],
            'sql_query' => null,
            'columns_config' => json_encode(array_values(array_unique($data['columns'])), JSON_UNESCAPED_UNICODE),
            'filters_config' => json_encode($data['filters'] ?? [], JSON_UNESCAPED_UNICODE),
            'chart_config' => json_encode($data['chart'] ?? ['type' => 'TABLE'], JSON_UNESCAPED_UNICODE),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'status' => $data['status'] ?? 'ACTIVE',
        ];
    }

    /** @return array<string, mixed> */
    private function normalizeTemplate(object $row): array
    {
        return [
            ...(array) $row,
            'columns' => $this->json($row->columns_config ?? null, []),
            'filters' => $this->json($row->filters_config ?? null, []),
            'chart' => $this->json($row->chart_config ?? null, ['type' => 'TABLE']),
            'legacy_disabled' => strtoupper((string) ($row->status ?? '')) === 'LEGACY_DISABLED',
        ];
    }

    private function manage(Request $request): void
    {
        abort_unless(AccessControl::accessHasCapability((array) $request->attributes->get('access', []), 'reports.templates.manage'), 403);
    }

    private function json(mixed $value, array $fallback): array
    {
        if (is_array($value)) return $value;
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : $fallback;
    }

    private function pagination($page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => 'Không tìm thấy mẫu báo cáo', 'data' => null], 404);
    }
}

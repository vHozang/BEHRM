<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RefreshTokenService;
use App\Support\HrmConfig;
use App\Support\Notifier;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * QUYẾT ĐỊNH NHÂN SỰ — tăng lương, điều chuyển, bổ nhiệm, thôi việc.
 *
 * Vì sao cần: trước đây sửa lương/chức danh là UPDATE thẳng vào bảng employees,
 * không để lại vết và không ai duyệt. Hai hệ quả:
 *   - Pháp lý: Đ.33 BLLĐ — thay đổi mức lương/công việc phải có phụ lục HĐ.
 *   - Kiểm soát nội bộ: sửa lương không vết là rủi ro gian lận lớn nhất của HRM.
 *
 * Luồng: HR đề xuất (CHỜ_DUYỆT) → admin duyệt (người duyệt phải KHÁC người đề
 * xuất) → hệ thống mới ÁP DỤNG thay đổi + ghi phụ lục vào contract_change_logs.
 * Bản ghi contract_change_logs chính là dấu vết pháp lý của phụ lục HĐ.
 *
 * Thôi việc còn: chốt ngày chấm dứt + lý do (mở khoá chỉ số turnover) và tự tính
 * tiền phép chưa nghỉ phải trả (Đ.113.3) đưa vào kỳ lương đang mở.
 */
class PersonnelDecisionController extends Controller
{
    private const TYPES = ['SALARY', 'POSITION', 'DEPARTMENT', 'TERMINATION'];

    private const TYPE_LABEL = [
        'SALARY' => 'Điều chỉnh lương',
        'POSITION' => 'Bổ nhiệm / thay đổi chức danh',
        'DEPARTMENT' => 'Điều chuyển bộ phận',
        'TERMINATION' => 'Chấm dứt hợp đồng lao động',
    ];

    /** GET /personnel-decisions */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = DB::table('contract_change_logs AS l')
            ->leftJoin('employees AS e', 'e.id', '=', 'l.employee_id')
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('l.tenant_id', TenantContext::id()))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('l.employee_id', $request->query('employee_id')))
            ->when($request->filled('change_type'), fn ($q) => $q->where('l.change_type', $request->query('change_type')))
            ->orderByDesc('l.id')
            ->select('l.*', 'e.full_name', 'e.employee_code');

        $page = $query->paginate($perPage);
        $items = $page->items();

        $callerId = (int) $request->attributes->get('auth_employee_id');
        foreach ($items as $it) {
            $meta = json_decode($it->meta ?: '{}', true) ?: [];
            $it->status = $meta['status'] ?? 'CHỜ_DUYỆT';
            $it->effective_date = $meta['effective_date'] ?? null;
            $it->proposed_by = $meta['proposed_by'] ?? null;
            $it->type_label = self::TYPE_LABEL[$it->change_type] ?? $it->change_type;
            // Chỉ admin KHÁC người đề xuất mới thấy nút duyệt (backend vẫn chặn lại).
            $it->can_approve = ($it->status === 'CHỜ_DUYỆT')
                && $callerId !== (int) ($meta['proposed_by'] ?? 0)
                && $this->isAdmin($callerId);
        }

        if ($request->filled('status')) {
            $items = array_values(array_filter($items, fn ($i) => $i->status === $request->query('status')));
        }

        return $this->ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ],
        ], 'Danh sách quyết định nhân sự');
    }

    /** POST /personnel-decisions — HR đề xuất (chưa áp dụng). */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'employee_id' => 'required|integer|exists:employees,id',
            'change_type' => 'required|string|in:'.implode(',', self::TYPES),
            'effective_date' => 'required|date',
            'reason' => 'required|string|min:3|max:500',
            'new_value' => 'nullable',
        ], [
            'reason.required' => 'Phải ghi lý do — đây là căn cứ của quyết định nhân sự',
            'effective_date.required' => 'Phải có ngày hiệu lực',
        ]);
        if ($v->fails()) {
            return $this->validationError($v->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');
        $type = $request->input('change_type');
        $emp = DB::table('employees')->where('id', $employeeId)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->first();
        if (! $emp) {
            return $this->notFound('Không tìm thấy nhân viên');
        }

        [$old, $new, $err] = $this->resolveValues($type, $emp, $request->input('new_value'));
        if ($err !== null) {
            return $this->validationError(['new_value' => [$err]]);
        }

        // Đang có quyết định cùng loại chờ duyệt → không cho đề xuất chồng.
        $pending = DB::table('contract_change_logs')
            ->where('employee_id', $employeeId)->where('change_type', $type)
            ->whereRaw("COALESCE(meta->>'status','CHỜ_DUYỆT') = 'CHỜ_DUYỆT'")
            ->exists();
        if ($pending) {
            return $this->validationError(['change_type' => ['Đã có quyết định cùng loại đang chờ duyệt cho nhân viên này']]);
        }

        $proposerId = $request->attributes->get('auth_employee_id');
        $contractId = DB::table('contracts')->where('employee_id', $employeeId)
            ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])->value('id');

        $id = DB::table('contract_change_logs')->insertGetId(TenantContext::stamp([
            'employee_id' => $employeeId,
            'contract_id' => $contractId,
            'change_type' => $type,
            'old_value' => (string) $old,
            'new_value' => (string) $new,
            'reason' => $request->input('reason'),
            'meta' => json_encode([
                'status' => 'CHỜ_DUYỆT',
                'proposed_by' => $proposerId,
                'proposed_at' => now()->toIso8601String(),
                'effective_date' => $request->date('effective_date')?->toDateString(),
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        Notifier::notifyMany($this->adminIds(), 'Chờ duyệt quyết định nhân sự',
            self::TYPE_LABEL[$type]." cho {$emp->full_name} ({$emp->employee_code}) đang chờ duyệt.",
            'personnel_decision', $id, ['priority' => 'high'], $proposerId);

        return $this->ok(DB::table('contract_change_logs')->find($id), 'Đã tạo đề xuất — chờ duyệt');
    }

    /** POST /personnel-decisions/{id}/approve — duyệt VÀ áp dụng thay đổi. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $row = DB::table('contract_change_logs')->where('id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->first();
        if (! $row) {
            return $this->notFound();
        }

        $meta = json_decode($row->meta ?: '{}', true) ?: [];
        if (($meta['status'] ?? 'CHỜ_DUYỆT') !== 'CHỜ_DUYỆT') {
            return $this->validationError(['status' => ['Quyết định này đã được xử lý']]);
        }

        $approverId = (int) $request->attributes->get('auth_employee_id');
        if ($approverId === (int) ($meta['proposed_by'] ?? 0)) {
            return $this->validationError(['approver_id' => ['Người đề xuất không thể tự duyệt quyết định của mình']]);
        }
        if (! $this->isAdmin($approverId)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền duyệt quyết định nhân sự', 'data' => null], 403);
        }

        $applied = DB::transaction(fn () => $this->applyDecision($row, $meta, $approverId));

        $meta = array_merge($meta, [
            'status' => 'ĐÃ_DUYỆT',
            'approved_by' => $approverId,
            'approved_at' => now()->toIso8601String(),
        ] + $applied);
        DB::table('contract_change_logs')->where('id', $id)
            ->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        Notifier::notify((int) $row->employee_id, 'Quyết định nhân sự có hiệu lực',
            (self::TYPE_LABEL[$row->change_type] ?? $row->change_type).' — hiệu lực từ '
                .($meta['effective_date'] ?? now()->toDateString()).'.',
            'personnel_decision', $id, ['priority' => 'high'], $approverId);
        if (! empty($meta['proposed_by'])) {
            Notifier::notify((int) $meta['proposed_by'], 'Quyết định nhân sự đã được duyệt',
                (self::TYPE_LABEL[$row->change_type] ?? '').' đã được duyệt và áp dụng.',
                'personnel_decision', $id, ['priority' => 'normal'], $approverId);
        }

        return $this->ok(DB::table('contract_change_logs')->find($id), 'Đã duyệt và áp dụng quyết định');
    }

    /** POST /personnel-decisions/{id}/reject */
    public function reject(Request $request, int $id): JsonResponse
    {
        $row = DB::table('contract_change_logs')->where('id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('tenant_id', TenantContext::id()))
            ->first();
        if (! $row) {
            return $this->notFound();
        }
        $meta = json_decode($row->meta ?: '{}', true) ?: [];
        if (($meta['status'] ?? 'CHỜ_DUYỆT') !== 'CHỜ_DUYỆT') {
            return $this->validationError(['status' => ['Quyết định này đã được xử lý']]);
        }
        $approverId = (int) $request->attributes->get('auth_employee_id');
        if ($approverId === (int) ($meta['proposed_by'] ?? 0)) {
            return $this->validationError(['approver_id' => ['Người đề xuất không thể tự từ chối quyết định của mình']]);
        }
        if (! $this->isAdmin($approverId)) {
            return response()->json(['status' => 403, 'message' => 'Bạn không có quyền từ chối quyết định nhân sự', 'data' => null], 403);
        }

        $meta = array_merge($meta, ['status' => 'TỪ_CHỐI', 'rejected_by' => $approverId,
            'rejected_at' => now()->toIso8601String(), 'reject_comment' => $request->input('comment')]);
        DB::table('contract_change_logs')->where('id', $id)
            ->update(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        if (! empty($meta['proposed_by'])) {
            Notifier::notify((int) $meta['proposed_by'], 'Quyết định nhân sự bị từ chối',
                ($request->input('comment') ?: 'Không nêu lý do'), 'personnel_decision', $id, ['priority' => 'high'], $approverId);
        }

        return $this->ok(DB::table('contract_change_logs')->find($id), 'Đã từ chối quyết định');
    }

    // ── Nội bộ ───────────────────────────────────────────────

    /** Giá trị cũ/mới theo loại quyết định + kiểm tra hợp lệ. */
    private function resolveValues(string $type, object $emp, $newRaw): array
    {
        switch ($type) {
            case 'SALARY':
                $new = (float) preg_replace('/[^0-9.]/', '', (string) $newRaw);
                if ($new <= 0) {
                    return [null, null, 'Mức lương mới phải lớn hơn 0'];
                }
                if ((float) $emp->base_salary === $new) {
                    return [null, null, 'Mức lương mới trùng mức hiện tại'];
                }

                return [(float) $emp->base_salary, $new, null];

            case 'POSITION':
                $new = (int) $newRaw;
                if (! DB::table('positions')->where('id', $new)->exists()) {
                    return [null, null, 'Chức danh không tồn tại'];
                }

                return [(int) $emp->position_id, $new, null];

            case 'DEPARTMENT':
                $new = (int) $newRaw;
                if (! DB::table('departments')->where('id', $new)->exists()) {
                    return [null, null, 'Phòng ban không tồn tại'];
                }

                return [(int) $emp->department_id, $new, null];

            case 'TERMINATION':
                return [(string) $emp->status, 'TERMINATED', null];
        }

        return [null, null, 'Loại quyết định không hợp lệ'];
    }

    /** Áp dụng thay đổi thật vào dữ liệu nhân sự. Trả về thông tin bổ sung cho meta. */
    private function applyDecision(object $row, array $meta, int $approverId): array
    {
        $employeeId = (int) $row->employee_id;
        $effective = $meta['effective_date'] ?? now()->toDateString();
        $extra = [];

        switch ($row->change_type) {
            case 'SALARY':
                DB::table('employees')->where('id', $employeeId)
                    ->update(['base_salary' => (float) $row->new_value, 'updated_at' => now()]);
                // Đồng bộ mức lương trong hợp đồng đang hiệu lực (phụ lục HĐ).
                if ($row->contract_id) {
                    $c = DB::table('contracts')->find($row->contract_id);
                    $cm = json_decode($c->meta ?: '{}', true) ?: [];
                    $cm['basic_salary'] = (float) $row->new_value;
                    $cm['annex_history'][] = ['date' => $effective, 'from' => (float) $row->old_value,
                        'to' => (float) $row->new_value, 'decision_id' => (int) $row->id];
                    DB::table('contracts')->where('id', $row->contract_id)
                        ->update(['meta' => json_encode($cm, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                }
                break;

            case 'POSITION':
            case 'DEPARTMENT':
                $col = $row->change_type === 'POSITION' ? 'position_id' : 'department_id';
                DB::table('employees')->where('id', $employeeId)
                    ->update([$col => (int) $row->new_value, 'updated_at' => now()]);
                // Khép lịch sử công tác cũ, mở dòng mới kể từ ngày hiệu lực.
                $previousEndDate = \Carbon\Carbon::parse($effective)->subDay()->toDateString();
                DB::table('employment_histories')->where('employee_id', $employeeId)
                    ->whereRaw('is_current = true')
                    ->update(['is_current' => DB::raw('false'), 'end_date' => $previousEndDate, 'updated_at' => now()]);
                $emp = DB::table('employees')->find($employeeId);
                DB::table('employment_histories')->insert(TenantContext::stamp([
                    'employee_id' => $employeeId,
                    'department_id' => $emp->department_id,
                    'position_id' => $emp->position_id,
                    'start_date' => $effective,
                    'is_current' => DB::raw('true'),
                    'notes' => 'Theo quyết định nhân sự #'.$row->id.': '.$row->reason,
                    'meta' => json_encode(['decision_id' => (int) $row->id], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(), 'updated_at' => now(),
                ]));
                break;

            case 'TERMINATION':
                $emp = DB::table('employees')->find($employeeId);
                $profile = json_decode($emp->profile ?: '{}', true) ?: [];
                // ponytail: lưu ngày/lý do nghỉ trong profile (jsonb) theo đúng lối các
                // trường mở rộng khác của bảng này — thêm cột riêng khi cần index/thống kê nặng.
                $profile['termination_date'] = $effective;
                $profile['termination_reason'] = $row->reason;
                $profile['termination_decision_id'] = (int) $row->id;
                DB::table('employees')->where('id', $employeeId)->update([
                    'status' => 'TERMINATED',
                    'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
                DB::table('contracts')->where('employee_id', $employeeId)
                    ->whereIn('status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
                    ->update(['status' => 'HẾT_HIỆU_LỰC', 'end_date' => $effective, 'updated_at' => now()]);
                DB::table('employment_histories')->where('employee_id', $employeeId)
                    ->whereRaw('is_current = true')
                    ->update(['is_current' => DB::raw('false'), 'end_date' => $effective, 'updated_at' => now()]);

                if (Schema::hasTable('api_refresh_tokens')) {
                    app(RefreshTokenService::class)->revokeEmployee($employeeId);
                } elseif (Schema::hasTable('api_tokens')) {
                    DB::table('api_tokens')->where('employee_id', $employeeId)->delete();
                }

                $extra = $this->payoutUnusedLeave($emp, $effective, $row);
                break;
        }

        return $extra;
    }

    /**
     * Đ.113.3 BLLĐ: thôi việc mà chưa nghỉ hết phép năm thì phải TRẢ TIỀN những ngày
     * chưa nghỉ. Tạo khoản chi vào kỳ lương đang mở của pháp nhân — engine lương tự
     * cộng vào thu nhập chịu thuế và KHÔNG tính vào nền BHXH (đúng bản chất khoản này).
     */
    private function payoutUnusedLeave(object $emp, string $effective, object $row): array
    {
        $year = (int) substr($effective, 0, 4);
        $remaining = (float) (DB::table('leave_balances')
            ->where('employee_id', $emp->id)->where('year', (string) $year)
            ->sum('remaining_days') ?? 0);
        if ($remaining <= 0 || ! $emp->base_salary) {
            return ['leave_payout_days' => 0, 'leave_payout_amount' => 0];
        }

        $stdDays = max(1.0, (float) HrmConfig::get('payroll.standard_days', 26));
        $amount = round($remaining * (float) $emp->base_salary / $stdDays);

        $periodId = DB::table('salary_periods')
            ->where('tenant_id', $emp->tenant_id)
            ->where('legal_entity_id', $emp->legal_entity_id)
            ->where('status', 'OPEN')->orderByDesc('id')->value('id');

        if ($periodId) {
            DB::table('payroll_adjustments')->insert([
                'employee_id' => $emp->id, 'paid_period_id' => $periodId,
                'adjustment_type' => 'BONUS', 'amount' => $amount, 'status' => 'APPLIED',
                'meta' => json_encode([
                    'kind' => 'LEAVE_PAYOUT', 'days' => $remaining, 'decision_id' => (int) $row->id,
                    'note' => "Thanh toán {$remaining} ngày phép chưa nghỉ khi chấm dứt HĐ (Đ.113.3 BLLĐ)",
                ], JSON_UNESCAPED_UNICODE),
                'tenant_id' => $emp->tenant_id, 'legal_entity_id' => $emp->legal_entity_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [
            'leave_payout_days' => $remaining,
            'leave_payout_amount' => $amount,
            'leave_payout_period_id' => $periodId,
            'leave_payout_note' => $periodId ? null : 'Chưa có kỳ lương đang mở — kế toán cần chi thủ công',
        ];
    }

    private function isAdmin(?int $employeeId): bool
    {
        if (! $employeeId) {
            return false;
        }
        if (DB::table('employees')->where('id', $employeeId)->value('is_super_admin')) {
            return true;
        }

        return DB::table('employee_roles AS er')->join('roles AS r', 'r.id', '=', 'er.role_id')
            ->where('er.employee_id', $employeeId)->whereRaw('er.is_active = true')
            ->where(fn ($q) => $q->where('r.role_code', 'ADMIN')->orWhereRaw("r.meta->>'is_admin' = 'true'"))
            ->exists();
    }

    private function adminIds(): array
    {
        return DB::table('employee_roles AS er')->join('roles AS r', 'r.id', '=', 'er.role_id')
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('er.tenant_id', TenantContext::id()))
            ->whereRaw('er.is_active = true')->where('r.role_code', 'ADMIN')
            ->pluck('er.employee_id')->unique()->values()->all();
    }

    private function ok($data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $msg = 'Không tìm thấy'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $msg, 'data' => null], 404);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }
}

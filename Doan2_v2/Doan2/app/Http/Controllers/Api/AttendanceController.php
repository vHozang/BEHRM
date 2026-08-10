<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\OvertimeRequest;
use App\Services\AttendanceSummaryService;
use App\Services\TimesheetService;
use App\Services\ShiftResolver;
use App\Support\AttendanceVerification;
use App\Support\HrmConfig;
use App\Support\TenantContext;
use App\Support\TimePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AttendanceController extends Controller
{
    public function __construct(private readonly ShiftResolver $shiftResolver) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = Attendance::with(['employee:id,full_name,employee_code'])
            ->orderByDesc('id');

        foreach (['employee_id', 'work_date', 'status', 'shift_type_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('work_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('work_date', '<=', $request->query('to'));
        }

        // Lọc theo THÁNG (month + year): giới hạn work_date trong tháng đó. FE
        // gửi month/year để chỉ lấy 1 tháng — trước đây backend bỏ qua nên FE phải
        // tải TOÀN BỘ lịch sử (nhiều trang → chậm, tưởng lỗi khi công nhân nhiều
        // bản ghi). year không kèm month → lọc cả năm.
        $year = (int) $request->query('year', 0);
        $month = (int) $request->query('month', 0);
        if ($year >= 2000 && $year <= 2100) {
            if ($month >= 1 && $month <= 12) {
                $start = sprintf('%04d-%02d-01', $year, $month);
                $end = date('Y-m-t', strtotime($start));
                $query->whereBetween('work_date', [$start, $end]);
            } else {
                $query->whereBetween('work_date', ["{$year}-01-01", "{$year}-12-31"]);
            }
        }

        // Lọc các lượt cần xem xét (xác minh chống gian lận).
        if ($request->query('review') === 'needs_review') {
            $query->whereRaw("meta->>'review_status' = 'needs_review'");
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
        ], 'Attendances list');
    }

    public function show(int $id): JsonResponse
    {
        $attendance = Attendance::with(['employee:id,full_name'])->find($id);

        if (! $attendance) {
            return $this->notFound();
        }

        return $this->ok($attendance, 'Attendance detail');
    }

    /**
     * POST /attendances/check-in
     */
    public function checkIn(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'employee_id.exists' => 'Nhân viên không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = $request->input('employee_id');

        // Reject employees belonging to another tenant (bare `exists` is unscoped).
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        // Tài khoản hệ thống (vd System Administrator) không phải nhân sự thật → không chấm công.
        if ($this->isSystemAccount($employeeId)) {
            return $this->validationError(['employee_id' => ['Tài khoản hệ thống không thể chấm công. Vui lòng dùng tài khoản nhân viên.']]);
        }

        $today = now()->toDateString();

        // Check if already checked in today
        $existing = Attendance::where('employee_id', $employeeId)
            ->where('work_date', $today)
            ->first();

        if ($existing && $existing->check_in_time) {
            // Second check-in (support 2 sessions/day)
            if ($existing->check_in_time_2) {
                return $this->validationError([
                    'employee_id' => ['Nhân viên đã check-in đủ 2 lần hôm nay'],
                ]);
            }
            $existing->update(['check_in_time_2' => now()->toTimeString()]);

            return $this->ok($existing->fresh(), 'Check-in buổi 2 thành công');
        }

        // Determine current shift and calculate late minutes
        $shiftAssignment = $this->shiftResolver->resolve((int) $employeeId, $today, TenantContext::id());

        $shiftTypeId = $shiftAssignment->shift_type_id ?? null;
        $shift = null;
        if ($shiftTypeId) {
            $shift = DB::table('shift_types')->where('id', $shiftTypeId)
                ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_types.tenant_id', TenantContext::id()))
                ->first();
        }

        // Phân loại theo ca + dung sai đi trễ (attendance.late_grace_minutes).
        // Khi check-in chưa có giờ ra → chỉ ra ON_TIME / LATE.
        $cls = TimePolicy::classifyAttendance($shift, now()->toTimeString(), null);

        // Xác minh chống gian lận (IP / vị trí / thiết bị / WFH).
        $verify = AttendanceVerification::evaluate(
            $request->ip(),
            $request->input('latitude'),
            $request->input('longitude'),
            $shift,
            $request->userAgent(),
            $request->input('source'),
            $request->input('work_mode') // office | wfh | on_site
        );
        $verify['accuracy'] = is_numeric($request->input('accuracy')) ? (float) $request->input('accuracy') : null;
        $verify['at'] = now()->toIso8601String();
        if ($request->filled('site_name')) {
            $verify['site_name'] = (string) $request->input('site_name'); // tên công trình (on-site)
        }

        // Chế độ 'block': từ chối nếu ngoài phạm vi cho phép.
        if ($verify['mode'] === 'block' && ! $verify['within_policy']) {
            return $this->validationError([
                'location' => ['Chấm công ngoài phạm vi cho phép (IP/vị trí không hợp lệ). Liên hệ quản trị nếu bạn làm từ xa.'],
            ]);
        }

        $reviewStatus = $verify['within_policy'] ? 'ok' : 'needs_review';

        // `attendances` has no late_minutes column — persist it in meta, which is
        // where the timesheet engine / payroll summary read it from.
        $attendance = Attendance::create([
            'employee_id' => $employeeId,
            'work_date' => $today,
            'check_in_time' => now()->toTimeString(),
            'shift_type_id' => $shiftTypeId,
            'status' => $cls['status'],
            'meta' => json_encode([
                'late_minutes' => $cls['late_minutes'],
                'verification' => $verify,
                'review_status' => $reviewStatus,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return response()->json([
            'status' => 201,
            'message' => $reviewStatus === 'needs_review'
                ? 'Đã ghi nhận chấm công — cần quản trị xem xét (ngoài phạm vi).'
                : 'Check-in thành công',
            'data' => $attendance,
        ], 201);
    }

    /**
     * POST /attendances/check-out
     */
    public function checkOut(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = $request->input('employee_id');

        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $today = now()->toDateString();

        $attendance = Attendance::where('employee_id', $employeeId)
            ->where('work_date', $today)
            ->first();

        if (! $attendance) {
            return $this->validationError([
                'employee_id' => ['Nhân viên chưa check-in hôm nay'],
            ]);
        }

        $shift = null;
        if ($attendance->shift_type_id) {
            $shift = DB::table('shift_types')->where('id', $attendance->shift_type_id)
                ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_types.tenant_id', TenantContext::id()))
                ->first();
        }

        // Phân loại lại theo cả giờ vào/ra (về sớm vượt dung sai → EARLY_LEAVE,
        // nhưng đi trễ vẫn ưu tiên LATE).
        $checkOutTime = now()->toTimeString();
        $cls = TimePolicy::classifyAttendance($shift, (string) $attendance->check_in_time, $checkOutTime);

        // Merge các chỉ số công vào meta (không có cột vật lý trên attendances).
        $existingMeta = [];
        if ($attendance->meta) {
            $existingMeta = is_string($attendance->meta) ? json_decode($attendance->meta, true) : (array) $attendance->meta;
            if (! is_array($existingMeta)) {
                $existingMeta = [];
            }
        }
        $existingMeta['late_minutes'] = $cls['late_minutes'];
        $existingMeta['early_leave_minutes'] = $cls['early_leave_minutes'];
        $existingMeta['worked_hours'] = $cls['worked_hours'];

        // Ghi nhận xác minh lượt check-out (IP/vị trí/thiết bị) để đối chiếu.
        if ($request->filled('latitude') || $request->filled('longitude') || $request->ip()) {
            $vOut = AttendanceVerification::evaluate(
                $request->ip(),
                $request->input('latitude'),
                $request->input('longitude'),
                $shift,
                $request->userAgent(),
                $request->input('source')
            );
            $vOut['at'] = now()->toIso8601String();
            $existingMeta['verification_out'] = $vOut;
        }
        $metaJson = json_encode($existingMeta, JSON_UNESCAPED_UNICODE);

        // Update correct check-out field
        if ($attendance->check_in_time_2 && ! $attendance->check_out_time_2) {
            $attendance->update([
                'check_out_time_2' => now()->toTimeString(),
                'status' => $cls['status'],
                'meta' => $metaJson,
            ]);
        } else {
            $attendance->update([
                'check_out_time' => $checkOutTime,
                'status' => $cls['status'],
                'meta' => $metaJson,
            ]);
        }

        // Đi làm ngày nghỉ/lễ → tự đề xuất đơn tăng ca chờ duyệt.
        $otSuggested = \App\Support\OvertimeSuggester::suggest([
            'employee_id' => (int) $employeeId,
            'tenant_id' => TenantContext::id(),
            'work_date' => $today,
            'check_in' => (string) $attendance->check_in_time,
            'check_out' => $checkOutTime,
            'shift' => $shift,
        ]);

        return $this->ok($attendance->fresh(), $otSuggested
            ? 'Check-out thành công. Đã tạo đơn tăng ca (làm ngày nghỉ/lễ) chờ duyệt.'
            : 'Check-out thành công');
    }

    /**
     * PATCH /attendances/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $attendance = Attendance::find($id);

        if (! $attendance) {
            return $this->notFound();
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => 'sometimes|exists:employees,id',
            'work_date' => 'sometimes|date',
            'check_in_time' => 'nullable|string',
            'check_out_time' => 'nullable|string',
            'check_in_time_2' => 'nullable|string',
            'check_out_time_2' => 'nullable|string',
            'status' => 'sometimes|string',
            'shift_type_id' => 'nullable|exists:shift_types,id',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $columns = Schema::getColumnListing('attendances');
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
            if ($attendance->meta) {
                $existingMeta = is_string($attendance->meta) ? json_decode($attendance->meta, true) : (array) $attendance->meta;
                if (! is_array($existingMeta)) {
                    $existingMeta = [];
                }
            }
            $mergedMeta = array_merge($existingMeta, $meta);
            $payload['meta'] = json_encode($mergedMeta);
        }

        $attendance->update($payload);

        return $this->ok($attendance->fresh()->load('employee:id,full_name,employee_code'), 'Cập nhật thành công');
    }


    // ═══════════════════════════════════════════════════════
    // OVERTIME REQUESTS
    // ═══════════════════════════════════════════════════════

    public function overtimeIndex(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = OvertimeRequest::with(['employee:id,full_name'])
            ->orderByDesc('id');

        foreach (['employee_id', 'status', 'work_date'] as $field) {
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
        ], 'Overtime requests list');
    }

    public function storeOvertime(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date',
            'total_hours' => 'nullable|numeric|min:0.5',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'reason' => 'nullable|string',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'work_date.required' => 'Ngày tăng ca là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $start = $request->input('start_time');
        $end = $request->input('end_time');
        // Phân loại theo luật (loại ngày, giờ đêm, hệ số) + tính tổng giờ.
        $cls = TimePolicy::classifyOvertime($request->input('work_date'), $start, $end, $request->input('total_hours'));
        if ($cls['total_hours'] < 0.5) {
            return $this->validationError(['total_hours' => ['Tổng giờ tăng ca phải ≥ 0.5h (hoặc nhập giờ bắt đầu/kết thúc)']]);
        }

        // Chặn vượt giới hạn OT theo luật (ngày/tháng/năm).
        $caps = TimePolicy::overtimeCaps($employeeId, $request->input('work_date'), $cls['total_hours']);
        if (! empty($caps['violations'])) {
            return $this->validationError(['total_hours' => $caps['violations']]);
        }

        $ot = OvertimeRequest::create([
            'employee_id' => $employeeId,
            'work_date' => $request->input('work_date'),
            'start_time' => $start,
            'end_time' => $end,
            'total_hours' => $cls['total_hours'],
            'status' => 'PENDING',
            'meta' => [
                'day_type' => $cls['day_type'],
                'multiplier' => $cls['multiplier'],
                'night_hours' => $cls['night_hours'],
                'pay_factor' => $cls['pay_factor'],
                'label' => $cls['label'],
                'reason' => $request->input('reason'),
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đơn tăng ca đã được tạo (' . $cls['label'] . ')',
            'data' => $ot->fresh()->load('employee:id,full_name'),
        ], 201);
    }

    public function approveOvertime(Request $request, int $id): JsonResponse
    {
        $ot = OvertimeRequest::find($id);

        if (! $ot) {
            return $this->notFound();
        }

        if (! in_array($ot->status, ['PENDING', 'CHỜ_DUYỆT'])) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');
        if ($approverId !== null && (int) $ot->employee_id === (int) $approverId) {
            return $this->validationError(['approver_id' => ['Người tạo đơn không thể tự duyệt']]);
        }

        // Khi duyệt: bảo đảm tổng OT đã DUYỆT không vượt giới hạn luật.
        $caps = TimePolicy::overtimeCaps((int) $ot->employee_id, $ot->work_date, (float) $ot->total_hours, $ot->id, true);
        if (! empty($caps['violations'])) {
            return $this->validationError(['status' => array_map(fn ($v) => 'Không thể duyệt: ' . $v, $caps['violations'])]);
        }

        // Duyệt nhiều cấp (approval.overtime_chain). Chỉ chốt khi qua hết các cấp.
        $meta = is_string($ot->meta) ? (json_decode($ot->meta, true) ?: []) : (array) ($ot->meta ?? []);
        $meta = \App\Support\ApprovalFlow::ensure($meta, 'overtime');
        if ($err = \App\Support\ApprovalFlow::cannotApprove($meta, $approverId)) {
            return $this->validationError(['approver_id' => [$err]]);
        }
        [$meta, $final] = \App\Support\ApprovalFlow::approveStep($meta, $approverId, $request->input('comment'));
        $progress = \App\Support\ApprovalFlow::progress($meta);

        if (! $final) {
            $ot->update(['meta' => $meta]);
            $next = \App\Support\ApprovalFlow::currentRole($meta);

            return $this->ok($ot->fresh(), "Đã duyệt cấp {$progress['done']}/{$progress['total']}. Chờ cấp tiếp theo" . ($next ? " ({$next})" : '') . '.');
        }

        // Tuỳ chọn: quy đổi OT này thành NGHỈ BÙ thay vì trả tiền (comp-off).
        $compOff = filter_var($request->input('comp_off'), FILTER_VALIDATE_BOOLEAN);
        $message = 'Đơn tăng ca đã được duyệt';

        DB::transaction(function () use ($ot, $compOff, $meta, &$message) {
            // Chống double-credit: khoá hàng + xác nhận lại PENDING trong transaction.
            // 2 duyệt song song với comp_off=true nếu không khoá sẽ cộng nghỉ bù 2 lần.
            $locked = DB::table('overtime_requests')->where('id', $ot->id)->lockForUpdate()->first();
            if (! $locked || ! in_array((string) $locked->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
                return;
            }
            if ($compOff && (bool) HrmConfig::get('overtime.comp_off_enabled', true)) {
                $ot->update(['status' => 'APPROVED', 'meta' => $meta]);
                $days = $this->creditCompOff($ot);
                $meta['converted_to_comp_off'] = true;
                $meta['comp_off_days'] = $days;
                $ot->update(['meta' => $meta]);
                $message = "Đã duyệt & quy đổi {$ot->total_hours}h tăng ca thành {$days} ngày nghỉ bù";
            } else {
                $ot->update(['status' => 'APPROVED', 'meta' => $meta]);
            }
        });

        \App\Support\Notifier::notify(
            (int) $ot->employee_id,
            'Tăng ca được duyệt',
            'Đơn tăng ca ngày ' . \Carbon\Carbon::parse($ot->work_date)->format('d/m/Y')
                . ' (' . (float) $ot->total_hours . 'h) đã được duyệt.',
            'overtime_request', $ot->id, ['priority' => 'normal'], $approverId
        );

        return $this->ok($ot->fresh(), $message);
    }

    /**
     * Cộng số dư NGHỈ BÙ (COMP_OFF) cho nhân viên từ một đơn OT đã duyệt.
     * Số ngày bù = giờ OT × comp_off_rate / giờ chuẩn/ngày. Ghi ledger GRANT.
     */
    private function creditCompOff(OvertimeRequest $ot): float
    {
        $rate = (float) HrmConfig::get('overtime.comp_off_rate', 1.0);
        $std = (float) HrmConfig::get('attendance.standard_hours_per_day', 8);
        $days = $std > 0 ? round((float) $ot->total_hours * $rate / $std, 2) : 0.0;
        if ($days <= 0) {
            return 0.0;
        }

        $typeId = DB::table('leave_types')
            ->where('tenant_id', TenantContext::id())
            ->where('leave_type_code', 'COMP_OFF')
            ->value('id');
        if (! $typeId) {
            return 0.0;
        }

        $year = (string) \Carbon\Carbon::parse($ot->work_date)->year;
        $bal = DB::table('leave_balances')
            ->where('tenant_id', TenantContext::id())
            ->where('employee_id', $ot->employee_id)
            ->where('leave_type_id', $typeId)
            ->where('year', $year)
            ->first();

        $now = now();
        $before = $bal ? (float) $bal->remaining_days : 0.0;

        if ($bal) {
            DB::table('leave_balances')->where('id', $bal->id)->update([
                'total_days' => (float) $bal->total_days + $days,
                'remaining_days' => (float) $bal->remaining_days + $days,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('leave_balances')->insert(TenantContext::stamp([
                'employee_id' => $ot->employee_id,
                'leave_type_id' => $typeId,
                'year' => $year,
                'total_days' => $days,
                'used_days' => 0,
                'remaining_days' => $days,
                'meta' => json_encode(['source' => 'comp_off'], JSON_UNESCAPED_UNICODE),
                'tenant_id' => TenantContext::id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        DB::table('leave_transactions')->insert(TenantContext::stamp([
            'employee_id' => $ot->employee_id,
            'leave_type_id' => $typeId,
            'transaction_date' => $now->toDateString(),
            'transaction_type' => 'GRANT',
            'quantity' => $days,
            'before_balance' => $before,
            'after_balance' => $before + $days,
            'reference_id' => $ot->id,
            'reference_type' => 'OVERTIME',
            'reason' => "Nghỉ bù từ tăng ca ngày {$ot->work_date} ({$ot->total_hours}h)",
            'tenant_id' => TenantContext::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return $days;
    }

    /**
     * GET /overtime-requests/usage?employee_id=&date= — mức dùng OT ngày/tháng/năm
     * + các giới hạn theo luật (cho FE hiển thị thanh tiến độ & cảnh báo).
     */
    public function overtimeUsage(Request $request): JsonResponse
    {
        $employeeId = (int) $request->query('employee_id');
        if (! $employeeId) {
            return $this->validationError(['employee_id' => ['Thiếu employee_id']]);
        }
        $date = $request->query('date') ?: now()->toDateString();

        return $this->ok(TimePolicy::overtimeCaps($employeeId, $date, 0), 'Overtime usage');
    }

    // ═══════════════════════════════════════════════════════
    // ATTENDANCE SUMMARY (E1.4 — payroll feed)
    // ═══════════════════════════════════════════════════════

    /**
     * POST /attendance/summary/run {salary_period_id}
     *
     * Build (upsert) one salary_attendance_summary row per ACTIVE employee for
     * the given salary period. Idempotent. Result is read back via the generic
     * GET /salary-attendance-summary endpoint.
     */
    public function summaryRun(Request $request, AttendanceSummaryService $service): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'salary_period_id' => 'required|integer|exists:salary_periods,id',
        ], [
            'salary_period_id.required' => 'Mã kỳ lương là bắt buộc',
            'salary_period_id.exists' => 'Kỳ lương không tồn tại',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        try {
            $summary = $service->build((int) $request->input('salary_period_id'));
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                return $this->notFound($e->getMessage());
            }

            throw $e;
        }

        return $this->ok($summary, 'Tổng hợp chấm công kỳ lương hoàn tất');
    }

    /**
     * GET /attendance/timesheet?month=YYYY-MM[&employee_id=]
     * Bảng công tháng (lưới nhân viên × ngày) + tổng hợp — feed payroll.
     */
    public function timesheet(Request $request, TimesheetService $service): JsonResponse
    {
        $month = (string) $request->query('month', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return $this->validationError(['month' => ['Định dạng tháng phải là YYYY-MM']]);
        }

        $tenantId = TenantContext::id();
        $legalEntityId = (int) ($request->query('legal_entity_id') ?: TenantContext::legalEntityId());

        $employeeIds = null;
        if ($request->filled('employee_id')) {
            $employeeIds = [(int) $request->query('employee_id')];
        }

        $grid = $service->monthlyGrid($tenantId, $legalEntityId, $month, $employeeIds);

        // Kỳ lương trùng tháng (để nút "Tổng hợp công → lương" biết period nào).
        $period = DB::table('salary_periods')
            ->where('tenant_id', $tenantId)
            ->where('start_date', '<=', $grid['end'])
            ->where('end_date', '>=', $grid['start'])
            ->orderBy('start_date')
            ->first();
        $grid['salary_period'] = $period
            ? ['id' => $period->id, 'period_code' => $period->period_code ?? null, 'status' => $period->status ?? null]
            : null;

        return $this->ok($grid, 'Bảng công tháng ' . $month);
    }

    /**
     * POST /attendance/recompute {month|start,end[,employee_id]}
     * Tái phân loại trạng thái chấm công theo ca + dung sai (engine). Idempotent.
     */
    public function recompute(Request $request, TimesheetService $service): JsonResponse
    {
        $start = $request->input('start');
        $end = $request->input('end');
        if ($request->filled('month')) {
            $month = (string) $request->input('month');
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                return $this->validationError(['month' => ['Định dạng tháng phải là YYYY-MM']]);
            }
            $start = \Carbon\Carbon::parse($month . '-01')->startOfMonth()->toDateString();
            $end = \Carbon\Carbon::parse($month . '-01')->endOfMonth()->toDateString();
        }

        if (! $start || ! $end) {
            return $this->validationError(['month' => ['Cần truyền month=YYYY-MM hoặc start+end']]);
        }

        $tenantId = TenantContext::id();
        $legalEntityId = (int) ($request->input('legal_entity_id') ?: TenantContext::legalEntityId());
        $employeeIds = $request->filled('employee_id') ? [(int) $request->input('employee_id')] : null;

        $result = $service->recompute($tenantId, $legalEntityId, $start, $end, $employeeIds);

        return $this->ok($result, 'Đã tái phân loại chấm công');
    }

    // ═══════════════════════════════════════════════════════
    // SHIFT SWAPS
    // ═══════════════════════════════════════════════════════

    public function requestShiftSwap(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'requester_id' => 'required|exists:employees,id',
            'target_employee_id' => 'required|exists:employees,id|different:requester_id',
            'swap_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        // Both employees must belong to the caller's tenant.
        foreach (['requester_id', 'target_employee_id'] as $f) {
            if (! TenantContext::ownsRow('employees', $request->input($f))) {
                return $this->validationError([$f => ['Nhân viên không thuộc công ty hiện tại']]);
            }
        }

        $columns = Schema::getColumnListing('shift_swaps');
        $data = collect($request->all())->only($columns)->toArray();
        // shift_swaps uses `approval_status`, not `status`.
        $data['approval_status'] = 'PENDING';
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $swapId = DB::table('shift_swaps')->insertGetId(TenantContext::stamp($data));

        $reqName = DB::table('employees')->where('id', $request->input('requester_id'))->value('full_name') ?: 'Đồng nghiệp';
        \App\Support\Notifier::notify(
            (int) $request->input('target_employee_id'),
            'Yêu cầu đổi ca',
            $reqName . ' muốn đổi ca ngày ' . \Carbon\Carbon::parse($request->input('swap_date'))->format('d/m/Y') . ' với bạn.',
            'shift_swap', $swapId, ['priority' => 'normal'], (int) $request->input('requester_id')
        );

        return response()->json([
            'status' => 201,
            'message' => 'Yêu cầu đổi ca đã được tạo',
            'data' => DB::table('shift_swaps')->where('id', $swapId)->first(),
        ], 201);
    }

    public function approveShiftSwap(int $id): JsonResponse
    {
        $swap = DB::table('shift_swaps')->where('id', $id)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_swaps.tenant_id', TenantContext::id()))
            ->first();

        if (! $swap) {
            return $this->notFound();
        }

        if (! in_array((string) ($swap->approval_status ?? ''), ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError(['approval_status' => ['Yêu cầu đổi ca không ở trạng thái chờ duyệt']]);
        }

        DB::transaction(function () use ($swap, $id) {
            DB::table('shift_swaps')->where('id', $id)->update([
                'approval_status' => 'APPROVED',
                'approver_id' => request()->attributes->get('auth_employee_id'),
                'updated_at' => now(),
            ]);

            // Đổi ca theo ĐÚNG ngày swap_date (không đụng ca cố định): phân giải
            // ca mỗi NV trong ngày đó rồi ghi-đè 1 ngày chéo nhau.
            $swapDate = (string) $swap->swap_date;
            $rShift = $this->resolveShiftOnDate((int) $swap->requester_id, $swapDate);
            $tShift = $this->resolveShiftOnDate((int) $swap->target_employee_id, $swapDate);

            if ($rShift && $tShift && $rShift->shift_type_id != $tShift->shift_type_id) {
                $pairs = [
                    [(int) $swap->requester_id, $tShift->shift_type_id, $rShift->legal_entity_id ?? null],
                    [(int) $swap->target_employee_id, $rShift->shift_type_id, $tShift->legal_entity_id ?? null],
                ];
                foreach ($pairs as [$emp, $shiftType, $le]) {
                    $existing = DB::table('shift_assignments')
                        ->where('employee_id', $emp)
                        ->whereDate('effective_date', $swapDate)
                        ->whereDate('expiry_date', $swapDate)
                        ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_assignments.tenant_id', TenantContext::id()))
                        ->first();
                    if ($existing) {
                        DB::table('shift_assignments')->where('id', $existing->id)
                            ->update(['shift_type_id' => $shiftType, 'updated_at' => now()]);
                    } else {
                        DB::table('shift_assignments')->insert([
                            'employee_id' => $emp,
                            'shift_type_id' => $shiftType,
                            'effective_date' => $swapDate,
                            'expiry_date' => $swapDate,
                            'status' => 'ACTIVE',
                            'notes' => 'Đổi ca (swap #' . $id . ')',
                            'meta' => json_encode(['source' => 'shift-swap', 'swap_id' => $id], JSON_UNESCAPED_UNICODE),
                            'tenant_id' => TenantContext::id(),
                            'legal_entity_id' => $le ?? TenantContext::legalEntityId(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        \App\Support\Notifier::notifyMany(
            [(int) $swap->requester_id, (int) $swap->target_employee_id],
            'Đổi ca đã được duyệt',
            'Yêu cầu đổi ca ngày ' . \Carbon\Carbon::parse($swap->swap_date)->format('d/m/Y') . ' đã được duyệt.',
            'shift_swap', $id, ['priority' => 'normal']
        );

        return $this->ok(
            DB::table('shift_swaps')->where('id', $id)
                ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_swaps.tenant_id', TenantContext::id()))
                ->first(),
            'Yêu cầu đổi ca đã được duyệt'
        );
    }

    /**
     * POST /attendances/{id}/verify {decision: approve|reject, note?}
     * Admin xác minh một lượt chấm công bị đánh dấu "cần xem xét".
     */
    public function verifyAttendance(Request $request, int $id): JsonResponse
    {
        $attendance = Attendance::find($id);
        if (! $attendance) {
            return $this->notFound();
        }

        $decision = strtolower((string) $request->input('decision'));
        if (! in_array($decision, ['approve', 'reject'], true)) {
            return $this->validationError(['decision' => ['decision phải là approve hoặc reject']]);
        }

        $meta = is_string($attendance->meta) ? (json_decode($attendance->meta, true) ?: []) : (array) ($attendance->meta ?? []);
        $meta['review_status'] = $decision === 'approve' ? 'approved' : 'rejected';
        $meta['reviewed_by'] = $request->attributes->get('auth_employee_id');
        $meta['reviewed_at'] = now()->toIso8601String();
        if ($request->filled('note')) {
            $meta['review_note'] = $request->input('note');
        }

        $update = ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)];
        // Từ chối lượt chấm công gian lận → đánh dấu vắng.
        if ($decision === 'reject') {
            $update['status'] = 'ABSENT';
        }
        $attendance->update($update);

        return $this->ok($attendance->fresh(), $decision === 'approve' ? 'Đã xác nhận hợp lệ' : 'Đã từ chối lượt chấm công');
    }

    /**
     * Phân giải phân ca có hiệu lực cho 1 NV trong 1 ngày (range-resolve):
     * ưu tiên bản ghi-đè 1 ngày (có expiry_date) hơn ca cố định/standing; cùng
     * loại lấy effective_date mới nhất. Trả null nếu ngày đó không có ca.
     */
    private function resolveShiftOnDate(int $employeeId, string $date): ?object
    {
        return $this->shiftResolver->resolve($employeeId, $date, TenantContext::id());
    }

    /** Tài khoản kỹ thuật (profile.system_account) không phải nhân sự thật. */
    private function isSystemAccount($employeeId): bool
    {
        $profile = DB::table('employees')->where('id', $employeeId)->value('profile');
        $p = is_string($profile) ? json_decode($profile, true) : (array) $profile;

        return is_array($p) && ! empty($p['system_account']);
    }

    /**
     * POST /shift-roster/generate — sinh lịch CA XOAY theo tuần.
     * Body: { shift_type_ids:[], start_date, weeks, employee_ids?:[], rotate?:bool }
     * Mỗi tuần gán mỗi NV một ca; nếu rotate → ca dịch 1 bậc mỗi tuần (ca xoay).
     * Idempotent: xoá lịch sinh tự động cũ (meta.source='roster-gen') trong khoảng.
     */
    public function generateRoster(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'shift_type_ids' => 'required|array|min:1',
            'start_date' => 'required|date',
            'weeks' => 'required|integer|min:1|max:26',
        ], [
            'shift_type_ids.required' => 'Cần chọn ít nhất một ca để xoay',
            'start_date.required' => 'Ngày bắt đầu là bắt buộc',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $tenantId = TenantContext::id();
        $entityId = TenantContext::legalEntityId();
        $shiftIds = array_values($request->input('shift_type_ids'));
        $weeks = (int) $request->input('weeks');
        $rotate = $request->boolean('rotate', true);
        // Snap về thứ Hai của tuần để các tuần xoay khớp nhau kể cả khi caller
        // truyền ngày giữa tuần (không tin mỗi FE).
        $startMonday = \Carbon\Carbon::parse($request->input('start_date'))->startOfWeek(\Carbon\Carbon::MONDAY);

        // Nhân viên: theo danh sách hoặc tất cả NV đang làm.
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->whereRaw("COALESCE((profile->>'system_account')::boolean, false) = false")
            ->when($request->filled('employee_ids'), fn ($q) => $q->whereIn('id', $request->input('employee_ids')))
            ->orderBy('employee_code')
            ->get(['id', 'legal_entity_id']);
        if ($employees->isEmpty()) {
            return $this->validationError(['employee_ids' => ['Không có nhân viên để xếp ca']]);
        }

        $endDate = $startMonday->copy()->addDays($weeks * 7 - 1);
        $empIds = $employees->pluck('id')->all();

        // Xoá lịch sinh tự động cũ trong khoảng để tạo lại sạch.
        DB::table('shift_assignments')
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $empIds)
            ->whereRaw("meta->>'source' = 'roster-gen'")
            ->whereBetween('effective_date', [$startMonday->toDateString(), $endDate->toDateString()])
            ->delete();

        $now = now();
        $rows = [];
        $count = count($shiftIds);
        foreach ($employees->values() as $i => $emp) {
            for ($w = 0; $w < $weeks; $w++) {
                $weekStart = $startMonday->copy()->addDays($w * 7);
                $weekEnd = $weekStart->copy()->addDays(6);
                $idx = $rotate ? (($i + $w) % $count) : ($i % $count);
                $rows[] = [
                    'employee_id' => $emp->id,
                    'shift_type_id' => $shiftIds[$idx],
                    'effective_date' => $weekStart->toDateString(),
                    'expiry_date' => $weekEnd->toDateString(),
                    'status' => 'ACTIVE',
                    'notes' => 'Lịch ca xoay (tự sinh)',
                    'meta' => json_encode(['source' => 'roster-gen', 'rotate' => $rotate], JSON_UNESCAPED_UNICODE),
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $emp->legal_entity_id ?? $entityId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        // is_permanent để DB tự đặt default (false) — tránh lỗi bind boolean PG.
        DB::table('shift_assignments')->insert($rows);

        return $this->ok([
            'employees' => $employees->count(),
            'weeks' => $weeks,
            'assignments_created' => count($rows),
            'range' => [$startMonday->toDateString(), $endDate->toDateString()],
        ], "Đã tạo lịch ca xoay {$weeks} tuần cho {$employees->count()} nhân viên");
    }

    // ── Response Helpers ─────────────────────────────────

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(string $message = 'Record not found'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $message, 'data' => null], 404);
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

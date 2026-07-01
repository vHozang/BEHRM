<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceAdjustmentRequest;
use App\Support\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Attendance regularization (đơn điều chỉnh công).
 *
 * Employees file a request to correct a work-day's check-in/out times or
 * status. On approval the target `attendances` row is created/updated and
 * late/early minutes are recomputed against the employee's shift, reusing the
 * same calc AttendanceController::checkIn/checkOut perform.
 */
class AttendanceRegularizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = AttendanceAdjustmentRequest::with(['employee:id,full_name'])
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
        ], 'Attendance adjustment requests list');
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'work_date' => 'required|date',
            'reason' => 'nullable|string',
            'requested_check_in_time' => 'nullable|string',
            'requested_check_out_time' => 'nullable|string',
            'requested_status' => 'nullable|string',
        ], [
            'employee_id.required' => 'Mã nhân viên là bắt buộc',
            'employee_id.exists' => 'Nhân viên không tồn tại',
            'work_date.required' => 'Ngày công là bắt buộc',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $employeeId = (int) $request->input('employee_id');

        // Reject employees belonging to another tenant (bare `exists` is unscoped).
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        // Must request at least one concrete change.
        if (! $request->filled('requested_check_in_time') && ! $request->filled('requested_check_out_time')) {
            return $this->validationError([
                'requested_check_in_time' => ['Phải có ít nhất một trong giờ vào hoặc giờ ra cần điều chỉnh'],
            ]);
        }

        $workDate = Carbon::parse($request->input('work_date'))->toDateString();

        // Resolve target attendance row for (tenant, employee, work_date) if any.
        // Model global scope already filters by tenant.
        $attendanceId = Attendance::where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->value('id');

        // Bằng chứng (link ảnh/tài liệu hoặc ghi chú) → lưu trong meta.
        $meta = null;
        if ($request->filled('evidence') || $request->filled('evidence_url')) {
            $meta = json_encode([
                'evidence' => $request->input('evidence') ?? $request->input('evidence_url'),
            ], JSON_UNESCAPED_UNICODE);
        }

        $adjustment = AttendanceAdjustmentRequest::create([
            'employee_id' => $employeeId,
            'attendance_id' => $attendanceId,
            'work_date' => $workDate,
            'requested_check_in_time' => $request->input('requested_check_in_time'),
            'requested_check_out_time' => $request->input('requested_check_out_time'),
            'requested_status' => $request->input('requested_status'),
            'reason' => $request->input('reason'),
            'status' => 'PENDING',
            'meta' => $meta,
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đơn điều chỉnh công đã được tạo',
            'data' => $adjustment->fresh()->load('employee:id,full_name'),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $adjustment = AttendanceAdjustmentRequest::with(['employee:id,full_name'])->find($id);

        if (! $adjustment) {
            return $this->notFound();
        }

        return $this->ok($adjustment, 'Attendance adjustment request detail');
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $adjustment = AttendanceAdjustmentRequest::find($id);

        if (! $adjustment) {
            return $this->notFound();
        }

        if (! in_array($adjustment->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        // Self-approval guard — a requester cannot approve their own request.
        if ($approverId !== null && (int) $adjustment->employee_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo đơn không thể tự duyệt đơn của mình'],
            ]);
        }

        // Duyệt nhiều cấp (approval.adjustment_chain) — chỉ áp dụng khi qua hết cấp.
        $flowMeta = is_string($adjustment->meta) ? (json_decode($adjustment->meta, true) ?: []) : (array) ($adjustment->meta ?? []);
        $flowMeta = \App\Support\ApprovalFlow::ensure($flowMeta, 'adjustment');
        if ($err = \App\Support\ApprovalFlow::cannotApprove($flowMeta, $approverId)) {
            return $this->validationError(['approver_id' => [$err]]);
        }
        [$flowMeta, $flowFinal] = \App\Support\ApprovalFlow::approveStep($flowMeta, $approverId, $request->input('comment'));
        if (! $flowFinal) {
            $adjustment->update(['meta' => json_encode($flowMeta, JSON_UNESCAPED_UNICODE)]);
            $pr = \App\Support\ApprovalFlow::progress($flowMeta);
            $next = \App\Support\ApprovalFlow::currentRole($flowMeta);

            return $this->ok($adjustment->fresh()->load('employee:id,full_name'),
                "Đã duyệt cấp {$pr['done']}/{$pr['total']}. Chờ cấp tiếp theo" . ($next ? " ({$next})" : '') . '.');
        }

        $attendance = DB::transaction(function () use ($adjustment, $approverId, $request, $flowMeta) {
            // Find-or-create the target attendances row (tenant/entity stamped by trait).
            $attendance = Attendance::where('employee_id', $adjustment->employee_id)
                ->where('work_date', $adjustment->work_date)
                ->first();

            $isNew = $attendance === null;

            $before = $isNew ? null : $attendance->getOriginal();

            if ($isNew) {
                $attendance = new Attendance();
                $attendance->employee_id = $adjustment->employee_id;
                $attendance->work_date = $adjustment->work_date;
            }

            // Apply only the fields the request actually provided.
            if ($adjustment->requested_check_in_time !== null) {
                $attendance->check_in_time = $adjustment->requested_check_in_time;
            }
            if ($adjustment->requested_check_out_time !== null) {
                $attendance->check_out_time = $adjustment->requested_check_out_time;
            }

            // Ensure the row has a shift for late/early calc — an existing row may
            // lack one. Resolve from the employee's active shift assignment.
            if (! $attendance->shift_type_id) {
                $attendance->shift_type_id = $this->resolveShiftTypeId($adjustment->employee_id);
            }

            // Recompute late/early minutes against the employee's shift, reusing
            // the same shift_types start_time/end_time + diffInMinutes calc that
            // AttendanceController::checkIn/checkOut perform. When a time was
            // adjusted but no shift is available to compare against, clear any
            // stale minutes (do NOT keep the pre-adjustment lateness).
            $shift = $this->resolveShift($attendance->shift_type_id);
            $meta = $this->existingMeta($attendance->meta);

            if ($adjustment->requested_check_in_time !== null) {
                if ($shift && $shift->start_time) {
                    $shiftStart = now()->copy()->setTimeFromTimeString((string) $shift->start_time);
                    $checkIn = now()->copy()->setTimeFromTimeString((string) $attendance->check_in_time);
                    $meta['late_minutes'] = max(0, (int) round($shiftStart->diffInMinutes($checkIn, false)));
                } else {
                    $meta['late_minutes'] = 0;
                }
            }

            if ($adjustment->requested_check_out_time !== null) {
                if ($shift && $shift->end_time) {
                    $shiftEnd = now()->copy()->setTimeFromTimeString((string) $shift->end_time);
                    $checkOut = now()->copy()->setTimeFromTimeString((string) $attendance->check_out_time);
                    $meta['early_leave_minutes'] = max(0, (int) round($checkOut->diffInMinutes($shiftEnd, false)));
                } else {
                    $meta['early_leave_minutes'] = 0;
                }
            }

            // Status: explicit request wins, otherwise derive from late minutes.
            if ($adjustment->requested_status !== null) {
                $attendance->status = $adjustment->requested_status;
            } elseif (isset($meta['late_minutes'])) {
                $attendance->status = $meta['late_minutes'] > 0 ? 'LATE' : 'ON_TIME';
            }

            $attendance->meta = json_encode($meta);
            $attendance->save();

            AuditLogger::log(
                $isNew ? 'create' : 'update',
                'attendances',
                (int) $attendance->id,
                $before,
                $attendance->getAttributes()
            );

            $adjustment->update([
                'attendance_id' => $attendance->id,
                'status' => 'APPROVED',
                'approver_id' => $approverId,
                'decided_at' => now(),
                'decision_comment' => $request->input('decision_comment') ?? $request->input('comment'),
                'meta' => json_encode($flowMeta, JSON_UNESCAPED_UNICODE),
            ]);

            return $attendance;
        });

        \App\Support\Notifier::notify(
            (int) $adjustment->employee_id,
            'Điều chỉnh công được duyệt',
            'Đơn điều chỉnh công ngày ' . \Carbon\Carbon::parse($adjustment->work_date)->format('d/m/Y') . ' đã được duyệt.',
            'attendance_adjustment', $adjustment->id, ['priority' => 'normal'], $approverId
        );

        return $this->ok([
            'request' => $adjustment->fresh()->load('employee:id,full_name'),
            'attendance' => $attendance->fresh(),
        ], 'Đơn điều chỉnh công đã được duyệt');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $adjustment = AttendanceAdjustmentRequest::find($id);

        if (! $adjustment) {
            return $this->notFound();
        }

        if (! in_array($adjustment->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError(['status' => ['Đơn không ở trạng thái chờ duyệt']]);
        }

        $approverId = $request->attributes->get('auth_employee_id');

        if ($approverId !== null && (int) $adjustment->employee_id === (int) $approverId) {
            return $this->validationError([
                'approver_id' => ['Người tạo đơn không thể tự từ chối đơn của mình'],
            ]);
        }

        $adjustment->update([
            'status' => 'REJECTED',
            'approver_id' => $approverId,
            'decision_comment' => $request->input('decision_comment') ?? $request->input('comment'),
            'decided_at' => now(),
        ]);

        return $this->ok($adjustment->fresh(), 'Đơn điều chỉnh công đã bị từ chối');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $adjustment = AttendanceAdjustmentRequest::find($id);

        if (! $adjustment) {
            return $this->notFound();
        }

        $currentUserId = $request->attributes->get('auth_employee_id');

        if ($currentUserId !== null && (int) $adjustment->employee_id !== (int) $currentUserId) {
            return $this->validationError([
                'employee_id' => ['Chỉ người tạo đơn mới có thể hủy'],
            ]);
        }

        if (! in_array($adjustment->status, ['PENDING', 'CHỜ_DUYỆT'], true)) {
            return $this->validationError([
                'status' => ['Không thể hủy đơn đã được xử lý'],
            ]);
        }

        $adjustment->update(['status' => 'CANCELLED']);

        return $this->ok($adjustment->fresh(), 'Đơn điều chỉnh công đã được hủy');
    }

    // ── Shift Helpers (mirror AttendanceController) ──────────

    /**
     * Resolve the employee's active shift_type_id from shift_assignments
     * (tenant-scoped), mirroring AttendanceController::checkIn.
     */
    private function resolveShiftTypeId(int $employeeId): ?int
    {
        $assignment = DB::table('shift_assignments')
            ->where('employee_id', $employeeId)
            ->where('status', '!=', 'INACTIVE')
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_assignments.tenant_id', TenantContext::id()))
            ->first();

        return $assignment->shift_type_id ?? null;
    }

    /**
     * Resolve a shift_types row (tenant-scoped) for late/early calc.
     */
    private function resolveShift($shiftTypeId): ?object
    {
        if (! $shiftTypeId) {
            return null;
        }

        return DB::table('shift_types')->where('id', $shiftTypeId)
            ->when(TenantContext::hasTenant(), fn ($q) => $q->where('shift_types.tenant_id', TenantContext::id()))
            ->first();
    }

    /**
     * Decode the attendances.meta jsonb into an array (safe for null/string).
     */
    private function existingMeta($meta): array
    {
        if (! $meta) {
            return [];
        }

        $decoded = is_string($meta) ? json_decode($meta, true) : (array) $meta;

        return is_array($decoded) ? $decoded : [];
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

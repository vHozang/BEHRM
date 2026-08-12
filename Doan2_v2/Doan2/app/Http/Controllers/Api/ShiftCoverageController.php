<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
use App\Support\TenantContext;
use App\Support\TimePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * PHỦ CA KHI VẮNG ĐỘT XUẤT (coverage / điều người tăng ca + chuỗi giao ca).
 *
 * Luồng: QL tạo "ca cần phủ" cho 1 ngày (work_date + shift_type + ai vắng) →
 * QL MỜI từng người ở lại tăng ca phủ một khúc giờ (offer start→end) → NV
 * NHẬN/TỪ CHỐI ở cổng nhân viên → khi nhận, hệ thống tạo đơn tăng ca
 * (overtime_requests, phân loại + chặn cap theo luật VN) và cập nhật số giờ đã
 * phủ; phủ đủ giờ → trạng thái COVERED. Đây là "chuỗi giao ca": ví dụ NV Ca1 ở
 * lại phủ 14h–18h, NV Ca3 vào sớm phủ 18h–22h để bù đủ 8h của người vắng.
 */
class ShiftCoverageController extends Controller
{
    /** GET /shift-coverage-requests — danh sách ca cần phủ (QL). */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('shift_coverage_requests as coverage')
            ->leftJoin('employees as absent_employee', 'absent_employee.id', '=', 'coverage.absent_employee_id')
            ->where('coverage.tenant_id', TenantContext::id())
            ->where('coverage.legal_entity_id', TenantContext::legalEntityId())
            ->when($request->filled('status'), fn ($q) => $q->where('coverage.status', $request->query('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('coverage.work_date', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('coverage.work_date', '<=', $request->query('to')))
            ->orderByDesc('coverage.work_date')->orderByDesc('coverage.id')
            ->limit(300)
            ->get([
                'coverage.*',
                'absent_employee.employee_code as absent_employee_code',
                'absent_employee.full_name as absent_employee_name',
            ]);

        $offers = DB::table('shift_coverage_offers as offer')
            ->leftJoin('employees as covering_employee', 'covering_employee.id', '=', 'offer.employee_id')
            ->where('offer.tenant_id', TenantContext::id())
            ->where('offer.legal_entity_id', TenantContext::legalEntityId())
            ->whereIn('offer.coverage_request_id', $rows->pluck('id'))
            ->get([
                'offer.*',
                'covering_employee.employee_code',
                'covering_employee.full_name as employee_name',
            ])
            ->groupBy('coverage_request_id');

        $data = $rows->map(function ($r) use ($offers) {
            $r->offers = $offers->get($r->id, collect())->values();

            return $r;
        });

        return $this->ok($data, 'Danh sách ca cần phủ');
    }

    /** POST /shift-coverage-requests — QL tạo một ca cần phủ. */
    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'work_date' => 'required|date',
            'shift_type_id' => 'required|exists:shift_types,id',
            'absent_employee_id' => 'nullable|exists:employees,id',
            'reason' => 'nullable|string|max:50',
            'hours_needed' => 'nullable|numeric|min:0.5|max:24',
            'notes' => 'nullable|string',
        ], [
            'work_date.required' => 'Ngày cần phủ ca là bắt buộc',
            'shift_type_id.required' => 'Ca cần phủ là bắt buộc',
        ]);
        if ($v->fails()) {
            return $this->validationError($v->errors()->toArray());
        }

        $shift = DB::table('shift_types')->where('id', $request->input('shift_type_id'))
            ->where('tenant_id', TenantContext::id())->first();
        if (! $shift) {
            return $this->validationError(['shift_type_id' => ['Ca không thuộc công ty hiện tại']]);
        }
        if ($request->filled('absent_employee_id')
            && ! $this->employeeBelongsToCurrentEntity((int) $request->input('absent_employee_id'))) {
            return $this->validationError(['absent_employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }

        $hoursNeeded = $request->filled('hours_needed')
            ? (float) $request->input('hours_needed')
            : $this->shiftHours($shift);

        $now = now();
        $id = DB::table('shift_coverage_requests')->insertGetId([
            'tenant_id' => TenantContext::id(),
            'legal_entity_id' => TenantContext::legalEntityId(),
            'work_date' => $request->input('work_date'),
            'shift_type_id' => (int) $request->input('shift_type_id'),
            'absent_employee_id' => $request->input('absent_employee_id'),
            'reason' => $request->input('reason'),
            'hours_needed' => $hoursNeeded,
            'hours_covered' => 0,
            'status' => 'OPEN',
            'created_by' => $request->attributes->get('auth_employee_id'),
            'notes' => $request->input('notes'),
            'meta' => json_encode([
                'shift_code' => $shift->shift_code,
                'shift_start' => $shift->start_time,
                'shift_end' => $shift->end_time,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'status' => 201,
            'message' => 'Đã tạo yêu cầu phủ ca',
            'data' => DB::table('shift_coverage_requests')->find($id),
        ], 201);
    }

    /** POST /shift-coverage-requests/{id}/offer — QL mời 1 NV phủ một khúc giờ. */
    public function offer(Request $request, int $id): JsonResponse
    {
        $req = $this->findRequest($id);
        if (! $req) {
            return $this->notFound();
        }
        if (in_array($req->status, ['COVERED', 'CANCELLED'], true)) {
            return $this->validationError(['status' => ['Yêu cầu phủ ca đã đóng']]);
        }

        $v = Validator::make($request->all(), [
            'employee_id' => 'required|exists:employees,id',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'hours' => 'nullable|numeric|min:0.5|max:24',
        ], ['employee_id.required' => 'Cần chọn người phủ ca']);
        if ($v->fails()) {
            return $this->validationError($v->errors()->toArray());
        }
        $empId = (int) $request->input('employee_id');
        if (! $this->employeeBelongsToCurrentEntity($empId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại']]);
        }
        if ($req->absent_employee_id && (int) $req->absent_employee_id === $empId) {
            return $this->validationError(['employee_id' => ['Không thể mời chính người đang vắng']]);
        }

        $start = $request->input('start_time');
        $end = $request->input('end_time');
        $hours = $request->filled('hours')
            ? (float) $request->input('hours')
            : ($start && $end ? $this->hoursBetween($start, $end) : 0);

        $now = now();
        $offerId = DB::table('shift_coverage_offers')->insertGetId([
            'tenant_id' => TenantContext::id(),
            'legal_entity_id' => TenantContext::legalEntityId(),
            'coverage_request_id' => $req->id,
            'employee_id' => $empId,
            'start_time' => $start,
            'end_time' => $end,
            'hours' => $hours,
            'status' => 'OFFERED',
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        \App\Support\Notifier::notify(
            $empId,
            'Lời mời phủ ca',
            'Bạn được mời tăng ca phủ ca ngày ' . $this->dmy($req->work_date)
                . (($start && $end) ? ' (' . substr($start, 0, 5) . '–' . substr($end, 0, 5) . ')' : '')
                . '. Mở "Lịch ca của tôi" để Nhận/Từ chối.',
            'shift_coverage_offer',
            $offerId,
            ['priority' => 'high'],
            $req->created_by
        );

        return response()->json([
            'status' => 201,
            'message' => 'Đã mời nhân viên phủ ca',
            'data' => DB::table('shift_coverage_offers')->find($offerId),
        ], 201);
    }

    /** GET /shift-coverage-offers?employee_id=self — NV xem lời mời phủ ca của mình. */
    public function myOffers(Request $request): JsonResponse
    {
        $empId = (int) ($request->query('employee_id') ?? $request->input('employee_id'));
        $rows = DB::table('shift_coverage_offers as o')
            ->join('shift_coverage_requests as r', 'r.id', '=', 'o.coverage_request_id')
            ->leftJoin('employees as absent_employee', 'absent_employee.id', '=', 'r.absent_employee_id')
            ->where('o.tenant_id', TenantContext::id())
            ->where('o.legal_entity_id', TenantContext::legalEntityId())
            ->when($empId, fn ($q) => $q->where('o.employee_id', $empId))
            ->when($request->filled('status'), fn ($q) => $q->where('o.status', $request->query('status')))
            ->orderByDesc('r.work_date')->orderByDesc('o.id')
            ->limit(200)
            ->get([
                'o.*', 'r.work_date', 'r.shift_type_id', 'r.reason as coverage_reason',
                'r.absent_employee_id', 'r.meta as request_meta',
                'absent_employee.employee_code as absent_employee_code',
                'absent_employee.full_name as absent_employee_name',
            ]);

        return $this->ok($rows, 'Lời mời phủ ca của tôi');
    }

    /**
     * POST /shift-coverage-offers/{id}/respond {decision: accept|decline}
     * NV nhận/từ chối lời mời. Nhận → tạo đơn tăng ca (phân loại + chặn cap),
     * cập nhật giờ đã phủ của yêu cầu; phủ đủ → COVERED.
     */
    public function respond(Request $request, int $id): JsonResponse
    {
        $offer = DB::table('shift_coverage_offers')->where('id', $id)
            ->where('tenant_id', TenantContext::id())->first();
        if (! $offer) {
            return $this->notFound();
        }
        if ($offer->status !== 'OFFERED') {
            return $this->validationError(['status' => ['Lời mời đã được phản hồi']]);
        }

        $decision = strtolower((string) $request->input('decision'));
        if (! in_array($decision, ['accept', 'decline'], true)) {
            return $this->validationError(['decision' => ['decision phải là accept hoặc decline']]);
        }

        $req = $this->findRequest((int) $offer->coverage_request_id);
        if (! $req) {
            return $this->notFound();
        }

        $coverName = DB::table('employees')->where('id', $offer->employee_id)->value('full_name') ?: ('NV #' . $offer->employee_id);

        if ($decision === 'decline') {
            DB::table('shift_coverage_offers')->where('id', $id)
                ->update(['status' => 'DECLINED', 'updated_at' => now()]);

            \App\Support\Notifier::notify(
                $req->created_by,
                'Từ chối phủ ca',
                $coverName . ' đã từ chối phủ ca ngày ' . $this->dmy($req->work_date) . '.',
                'shift_coverage_request', $req->id, ['priority' => 'normal'], (int) $offer->employee_id
            );

            return $this->ok(DB::table('shift_coverage_offers')->find($id), 'Đã từ chối lời mời phủ ca');
        }

        // accept → tạo đơn tăng ca cho khúc giờ phủ.
        $start = $offer->start_time;
        $end = $offer->end_time;
        $cls = TimePolicy::classifyOvertime($req->work_date, $start, $end, $offer->hours > 0 ? (float) $offer->hours : null);
        if ($cls['total_hours'] < 0.5) {
            return $this->validationError(['hours' => ['Khúc giờ phủ ca không hợp lệ (cần ≥ 0.5h)']]);
        }

        $caps = TimePolicy::overtimeCaps((int) $offer->employee_id, $req->work_date, $cls['total_hours']);
        if (! empty($caps['violations'])) {
            return $this->validationError(['hours' => $caps['violations']]);
        }

        $otId = null;
        DB::transaction(function () use ($offer, $req, $cls, $start, $end, $id, &$otId) {
            $ot = OvertimeRequest::create([
                'employee_id' => (int) $offer->employee_id,
                'work_date' => $req->work_date,
                'start_time' => $start,
                'end_time' => $end,
                'total_hours' => $cls['total_hours'],
                'status' => 'APPROVED',
                'meta' => [
                    'day_type' => $cls['day_type'],
                    'multiplier' => $cls['multiplier'],
                    'night_hours' => $cls['night_hours'],
                    'pay_factor' => $cls['pay_factor'],
                    'label' => $cls['label'],
                    'reason' => 'Phủ ca thay người vắng (coverage #' . $req->id . ')',
                    'source' => 'shift-coverage',
                    'kind' => 'MANAGER_TICKET',
                    'accepted_by_employee' => true,
                    'accepted_at' => now()->toIso8601String(),
                    'coverage_request_id' => $req->id,
                ],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $otId = $ot->id;

            DB::table('shift_coverage_offers')->where('id', $id)->update([
                'status' => 'ACCEPTED',
                'hours' => $cls['total_hours'],
                'overtime_request_id' => $ot->id,
                'updated_at' => now(),
            ]);

            $this->recomputeCoverage($req->id);
        });

        \App\Support\Notifier::notify(
            $req->created_by,
            'Đã nhận phủ ca',
            $coverName . ' đã nhận phủ ca ngày ' . $this->dmy($req->work_date)
                . ' (' . $cls['total_hours'] . 'h) — ticket tăng ca đã được chấp nhận và có hiệu lực.',
            'shift_coverage_request', $req->id, ['priority' => 'normal'], (int) $offer->employee_id
        );

        return $this->ok([
            'offer' => DB::table('shift_coverage_offers')->find($id),
            'overtime_request_id' => $otId,
            'classification' => $cls['label'],
        ], 'Đã nhận phủ ca — ticket tăng ca (' . $cls['label'] . ') đã được duyệt tự động');
    }

    /** POST /shift-coverage-requests/{id}/cancel — QL huỷ yêu cầu phủ ca. */
    public function cancel(int $id): JsonResponse
    {
        $req = $this->findRequest($id);
        if (! $req) {
            return $this->notFound();
        }
        DB::table('shift_coverage_requests')->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        DB::table('shift_coverage_offers')->where('coverage_request_id', $id)
            ->where('status', 'OFFERED')->update(['status' => 'CANCELLED', 'updated_at' => now()]);

        return $this->ok(DB::table('shift_coverage_requests')->find($id), 'Đã huỷ yêu cầu phủ ca');
    }

    // ── Helpers ──────────────────────────────────────────

    private function recomputeCoverage(int $requestId): void
    {
        $covered = (float) DB::table('shift_coverage_offers')
            ->where('coverage_request_id', $requestId)
            ->where('status', 'ACCEPTED')
            ->sum('hours');

        $req = DB::table('shift_coverage_requests')->find($requestId);
        $status = $req->status;
        if ($status !== 'CANCELLED') {
            if ($covered <= 0) {
                $status = 'OPEN';
            } elseif ($covered + 0.001 >= (float) $req->hours_needed) {
                $status = 'COVERED';
            } else {
                $status = 'PARTIALLY_COVERED';
            }
        }

        DB::table('shift_coverage_requests')->where('id', $requestId)
            ->update(['hours_covered' => $covered, 'status' => $status, 'updated_at' => now()]);
    }

    private function findRequest(int $id): ?object
    {
        return DB::table('shift_coverage_requests')->where('id', $id)
            ->where('tenant_id', TenantContext::id())
            ->where('legal_entity_id', TenantContext::legalEntityId())
            ->first();
    }

    private function employeeBelongsToCurrentEntity(int $employeeId): bool
    {
        return DB::table('employees')
            ->where('id', $employeeId)
            ->where('tenant_id', TenantContext::id())
            ->where('legal_entity_id', TenantContext::legalEntityId())
            ->exists();
    }

    /** Giờ làm của 1 ca (từ meta.working_hours hoặc start→end trừ nghỉ). */
    private function shiftHours(object $shift): float
    {
        $meta = is_string($shift->meta ?? null) ? json_decode($shift->meta, true) : (array) ($shift->meta ?? []);
        if (! empty($meta['working_hours'])) {
            return (float) $meta['working_hours'];
        }
        $h = $this->hoursBetween($shift->start_time, $shift->end_time);
        $breakMin = (float) ($meta['break_minutes'] ?? 0);

        return max(0, $h - $breakMin / 60);
    }

    private function dmy(?string $date): string
    {
        if (! $date) {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    private function hoursBetween(?string $start, ?string $end): float
    {
        if (! $start || ! $end) {
            return 0;
        }
        [$sh, $sm] = array_pad(array_map('intval', explode(':', $start)), 2, 0);
        [$eh, $em] = array_pad(array_map('intval', explode(':', $end)), 2, 0);
        $startMin = $sh * 60 + $sm;
        $endMin = $eh * 60 + $em;
        if ($endMin <= $startMin) {
            $endMin += 24 * 60; // qua đêm
        }

        return round(($endMin - $startMin) / 60, 2);
    }

    private function ok($data, string $msg): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $msg, 'data' => $data]);
    }

    private function notFound(string $msg = 'Không tìm thấy'): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => $msg, 'data' => null], 404);
    }

    private function validationError(array $e): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $e]], 422);
    }
}

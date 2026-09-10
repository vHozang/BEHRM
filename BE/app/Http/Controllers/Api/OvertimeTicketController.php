<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileOvertimeDay;
use App\Models\OvertimeRequest;
use App\Services\AttendanceAccess;
use App\Support\AccessControl;
use App\Support\Notifier;
use App\Support\TenantContext;
use App\Support\TimePolicy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OvertimeTicketController extends Controller
{
    public function __construct(private readonly AttendanceAccess $attendanceAccess) {}

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'work_date' => ['required', 'date'],
            'start_time' => ['required', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/'],
            'end_time' => ['required', 'regex:/^([01]\\d|2[0-3]):[0-5]\\d(?::[0-5]\\d)?$/'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $actorId = (int) $request->attributes->get('auth_employee_id');
        $employeeId = (int) $request->input('employee_id');
        if (! TenantContext::ownsRow('employees', $employeeId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc công ty hiện tại.']]);
        }
        if (! $this->attendanceAccess->canAccessEmployee($request, $employeeId, true)) {
            return $this->forbidden('Trưởng phòng chỉ được giao ticket OT cho nhân viên thuộc phòng mình.');
        }

        $classification = TimePolicy::classifyOvertime(
            $request->input('work_date'),
            $request->input('start_time'),
            $request->input('end_time'),
        );
        if ($classification['total_hours'] * 60 < 15) {
            return $this->validationError(['start_time' => ['Khung tăng ca phải tối thiểu 15 phút.']]);
        }
        $caps = TimePolicy::overtimeCaps(
            $employeeId,
            $request->input('work_date'),
            $classification['total_hours'],
            null,
            false,
            (int) TenantContext::id(),
        );
        if ($caps['violations'] !== []) {
            return $this->validationError(['end_time' => $caps['violations']]);
        }

        $ticket = OvertimeRequest::create([
            'employee_id' => $employeeId,
            'work_date' => $request->input('work_date'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'total_hours' => $classification['total_hours'],
            'status' => 'OFFERED',
            'meta' => [
                'kind' => 'MANAGER_TICKET',
                'created_by' => $actorId,
                'reason' => $request->input('reason'),
                'day_type' => $classification['day_type'],
                'multiplier' => $classification['multiplier'],
                'night_hours' => $classification['night_hours'],
                'pay_factor' => $classification['pay_factor'],
                'label' => $classification['label'],
                'offered_at' => now()->toIso8601String(),
            ],
        ]);

        Notifier::notify(
            $employeeId,
            'Ticket tăng ca mới',
            'Bạn được giao tăng ca ngày '.Carbon::parse($ticket->work_date)->format('d/m/Y')
                .' từ '.substr((string) $ticket->start_time, 0, 5).' đến '.substr((string) $ticket->end_time, 0, 5).'.',
            'overtime_ticket',
            $ticket->id,
            ['priority' => 'high'],
            $actorId,
        );

        return response()->json([
            'status' => 201,
            'message' => 'Đã giao ticket tăng ca, chờ nhân viên phản hồi.',
            'data' => $ticket->fresh()->load('employee:id,employee_code,full_name,department_id'),
        ], 201);
    }

    public function respond(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => ['required', 'in:accept,decline'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $ticket = OvertimeRequest::find($id);
        if (! $ticket || ($ticket->meta['kind'] ?? null) !== 'MANAGER_TICKET') {
            return $this->notFound();
        }
        $actorId = (int) $request->attributes->get('auth_employee_id');
        if ((int) $ticket->employee_id !== $actorId) {
            return $this->forbidden('Nhân viên chỉ được phản hồi ticket của chính mình.');
        }
        if ($ticket->status !== 'OFFERED') {
            return $this->validationError(['status' => ['Ticket đã được phản hồi hoặc hủy.']]);
        }

        $decision = $request->input('decision');
        $meta = $ticket->meta ?? [];
        $meta['responded_by'] = $actorId;
        $meta['responded_at'] = now()->toIso8601String();
        $meta['response_note'] = $request->input('note');

        if ($decision === 'accept') {
            $caps = TimePolicy::overtimeCaps(
                (int) $ticket->employee_id,
                $ticket->work_date,
                (float) $ticket->total_hours,
                $ticket->id,
                true,
                (int) $ticket->tenant_id,
            );
            if ($caps['violations'] !== []) {
                return $this->validationError(['status' => $caps['violations']]);
            }
            $ticket->update(['status' => 'APPROVED', 'meta' => $meta]);
            ReconcileOvertimeDay::dispatch(
                (int) $ticket->tenant_id,
                (int) $ticket->employee_id,
                $ticket->work_date->toDateString(),
            )->afterCommit();
        } else {
            $ticket->update(['status' => 'DECLINED', 'meta' => $meta]);
        }

        $creatorId = (int) ($meta['created_by'] ?? 0);
        Notifier::notify(
            $creatorId,
            $decision === 'accept' ? 'Nhân viên đã nhận ticket OT' : 'Nhân viên từ chối ticket OT',
            'Ticket tăng ca ngày '.Carbon::parse($ticket->work_date)->format('d/m/Y').' đã được phản hồi.',
            'overtime_ticket',
            $ticket->id,
            ['priority' => 'normal'],
            $actorId,
        );

        return $this->ok($ticket->fresh()->load('employee:id,employee_code,full_name,department_id'),
            $decision === 'accept' ? 'Đã nhận ticket tăng ca.' : 'Đã từ chối ticket tăng ca.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $ticket = $this->attendanceAccess->scopeEmployeeResource(
            OvertimeRequest::query(),
            $request,
            'overtime_requests',
        )->find($id);
        if (! $ticket || ($ticket->meta['kind'] ?? null) !== 'MANAGER_TICKET') {
            return $this->notFound();
        }

        $actorId = (int) $request->attributes->get('auth_employee_id');
        $creatorId = (int) ($ticket->meta['created_by'] ?? 0);
        $global = AccessControl::hasAnyRole($actorId, ['ADMIN', 'TENANT_ADMIN', 'HR']);
        if (! $global && $creatorId !== $actorId) {
            return $this->forbidden('Chỉ người giao ticket, HR hoặc Admin được hủy.');
        }
        if (! in_array($ticket->status, ['OFFERED', 'APPROVED'], true)) {
            return $this->validationError(['status' => ['Ticket không còn ở trạng thái có thể hủy.']]);
        }

        $meta = $ticket->meta ?? [];
        $meta['cancelled_by'] = $actorId;
        $meta['cancelled_at'] = now()->toIso8601String();
        $meta['cancel_reason'] = $request->input('reason');
        $ticket->update(['status' => 'CANCELLED', 'meta' => $meta]);
        ReconcileOvertimeDay::dispatch(
            (int) $ticket->tenant_id,
            (int) $ticket->employee_id,
            $ticket->work_date->toDateString(),
        )->afterCommit();

        Notifier::notify(
            (int) $ticket->employee_id,
            'Ticket tăng ca đã hủy',
            'Ticket tăng ca ngày '.Carbon::parse($ticket->work_date)->format('d/m/Y').' đã được hủy.',
            'overtime_ticket',
            $ticket->id,
            ['priority' => 'normal'],
            $actorId,
        );

        return $this->ok($ticket->fresh(), 'Đã hủy ticket tăng ca.');
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['status' => 404, 'message' => 'Không tìm thấy ticket tăng ca.', 'data' => null], 404);
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 403, 'message' => $message, 'data' => null], 403);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }
}

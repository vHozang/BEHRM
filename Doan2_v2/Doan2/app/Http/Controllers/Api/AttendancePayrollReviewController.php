<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendancePayrollReview;
use App\Services\AttendanceAccess;
use App\Services\AttendanceChangePublisher;
use App\Services\AttendancePayrollReviewService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AttendancePayrollReviewController extends Controller
{
    public function __construct(private readonly AttendanceAccess $attendanceAccess) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->canReview($request)) {
            return $this->forbidden();
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $query = AttendancePayrollReview::query()
            ->with([
                'employee:id,employee_code,full_name,department_id',
                'employee.department:id,department_code,department_name',
                'attendance:id,employee_id,shift_type_id,work_date,check_in_time,check_out_time,check_in_time_2,check_out_time_2,status,meta',
                'decidedBy:id,full_name',
            ])
            ->orderByDesc('work_date')
            ->orderByDesc('id');
        if (! $this->attendanceAccess->isAdmin($request)) {
            $query->where('legal_entity_id', TenantContext::legalEntityId());
        } elseif ($request->filled('legal_entity_id')) {
            $legalEntityId = (int) $request->query('legal_entity_id');
            if (TenantContext::ownsRow('legal_entities', $legalEntityId)) {
                $query->where('legal_entity_id', $legalEntityId);
            }
        }

        if ($request->query('status') === 'UNRESOLVED') {
            $query->whereIn('status', AttendancePayrollReview::UNRESOLVED_STATUSES);
        } elseif ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }
        foreach (['employee_id', 'work_date'] as $field) {
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

        $page = $query->paginate($perPage);

        return $this->ok([
            'items' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ], 'Danh sách review chấm công.');
    }

    public function decision(
        Request $request,
        int $id,
        AttendancePayrollReviewService $service,
        AttendanceChangePublisher $changes,
    ): JsonResponse {
        if (! $this->canReview($request)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'percent' => ['required', 'integer', 'in:0,25,50,75,100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $reviewQuery = AttendancePayrollReview::query()->whereKey($id);
        if (! $this->attendanceAccess->isAdmin($request)) {
            $reviewQuery->where('legal_entity_id', TenantContext::legalEntityId());
        }
        $review = $reviewQuery->first();
        if (! $review) {
            return response()->json(['status' => 404, 'message' => 'Không tìm thấy review.', 'data' => null], 404);
        }

        $review = $service->decide(
            $review,
            (int) $request->input('percent'),
            $request->input('note'),
            (int) $request->attributes->get('auth_employee_id'),
        );
        $changes->publishById((int) $review->attendance_id, 'payroll_review');

        return $this->ok($review, 'Đã lưu quyết định khấu trừ chấm công.');
    }

    private function canReview(Request $request): bool
    {
        return $this->attendanceAccess->canModifyAttendance($request);
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['status' => 403, 'message' => 'Chỉ HR hoặc Admin được xử lý review chấm công.', 'data' => null], 403);
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }
}

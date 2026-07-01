<?php

namespace App\Http\Controllers\Api;

use App\DTOs\CheckInData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\CheckInRequest;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Controller: Xử lý các HTTP request liên quan đến chấm công.
 *
 * Nguyên tắc thiết kế (Single Responsibility + Thin Controller):
 *  - Controller CHỈ làm: nhận request → validate → gọi Service → trả response.
 *  - KHÔNG chứa business logic (việc đó của Service).
 *  - KHÔNG query database trực tiếp (việc đó của Repository).
 *  - Mọi logic phức tạp được delegate xuống Service layer.
 */
class AttendanceController extends Controller
{
    /**
     * Inject dependencies qua constructor (Dependency Injection).
     * Laravel Container tự động resolve AttendanceService và các dependencies của nó.
     */
    public function __construct(
        private readonly AttendanceService $attendanceService,
    ) {}

    // ══════════════════════════════════════════════════════════
    // CHECK-IN / CHECK-OUT (Async via Queue)
    // ══════════════════════════════════════════════════════════

    /**
     * POST /api/attendance/check-in
     *
     * Tiếp nhận yêu cầu check-in và đưa vào hàng đợi xử lý bất đồng bộ.
     * Response trả về ngay lập tức (202 Accepted) mà không chờ DB write.
     *
     * HTTP 202 Accepted: Request hợp lệ và đã được ghi nhận, đang xử lý.
     * (Khác với 200 OK: đã xử lý xong, hoặc 201 Created: đã tạo resource xong)
     */
    public function checkIn(CheckInRequest $request): JsonResponse
    {
        try {
            // Tạo DTO từ validated request (không dùng $request->all() raw)
            $data = CheckInData::fromRequest($request->validated(), 'CHECK_IN');
            $result = $this->attendanceService->handleCheckIn($data);

            if (! $result['success']) {
                return $this->conflict($result['message'], [
                    'idempotency_key' => $result['idempotency_key'],
                ]);
            }

            return $this->accepted('Yêu cầu check-in đã được ghi nhận', [
                'idempotency_key' => $result['idempotency_key'],
                'queued' => $result['queued'],
                'message' => $result['message'],
            ]);

        } catch (Throwable $e) {
            Log::error('CheckIn API error', [
                'employee_id' => $request->input('employee_id'),
                'error' => $e->getMessage(),
            ]);

            return $this->serverError('Lỗi hệ thống, vui lòng thử lại sau.');
        }
    }

    /**
     * POST /api/attendance/check-out
     * Tương tự check-in nhưng action = 'CHECK_OUT'.
     */
    public function checkOut(CheckInRequest $request): JsonResponse
    {
        try {
            $data = CheckInData::fromRequest($request->validated(), 'CHECK_OUT');
            $result = $this->attendanceService->handleCheckIn($data);

            if (! $result['success']) {
                return $this->conflict($result['message'], [
                    'idempotency_key' => $result['idempotency_key'],
                ]);
            }

            return $this->accepted('Yêu cầu check-out đã được ghi nhận', [
                'idempotency_key' => $result['idempotency_key'],
                'queued' => $result['queued'],
            ]);

        } catch (Throwable $e) {
            Log::error('CheckOut API error', ['error' => $e->getMessage()]);

            return $this->serverError('Lỗi hệ thống, vui lòng thử lại sau.');
        }
    }

    // ══════════════════════════════════════════════════════════
    // Response Helper Methods
    // (Sẽ được chuyển vào BaseApiController nếu dùng nhiều nơi)
    // ══════════════════════════════════════════════════════════

    private function accepted(string $message, mixed $data = null): JsonResponse
    {
        return response()->json([
            'status' => 202,
            'message' => $message,
            'data' => $data,
        ], 202);
    }

    private function conflict(string $message, mixed $data = null): JsonResponse
    {
        return response()->json([
            'status' => 409,
            'message' => $message,
            'data' => $data,
        ], 409);
    }

    private function serverError(string $message): JsonResponse
    {
        return response()->json([
            'status' => 500,
            'message' => $message,
            'data' => null,
        ], 500);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VietnamHolidayService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statutory-holiday helpers on top of the generic /holidays CRUD.
 *
 * List/create/update/delete go through GenericResourceController; this
 * controller only adds the VN-specific value: previewing and one-click seeding
 * of the legally-mandated public holidays for a year (Điều 112 BLLĐ 2019).
 */
class HolidayController extends Controller
{
    public function __construct(private readonly VietnamHolidayService $holidays)
    {
    }

    /** Preview the statutory holidays for a year without writing anything. */
    public function preview(Request $request): JsonResponse
    {
        $year = $this->resolveYear($request);

        return $this->ok([
            'year' => $year,
            'holidays' => $this->holidays->statutoryHolidays($year),
        ], 'Lịch nghỉ lễ chuẩn Việt Nam');
    }

    /** Seed the statutory holidays for the current tenant + year (idempotent). */
    public function seed(Request $request): JsonResponse
    {
        if (! TenantContext::hasTenant()) {
            return $this->validationError(['tenant' => ['Không xác định được công ty hiện tại']]);
        }

        $year = $this->resolveYear($request);
        $result = $this->holidays->seed(TenantContext::id(), $year);

        $message = sprintf(
            'Đã tạo %d ngày lễ cho năm %d (%d ngày đã tồn tại được giữ nguyên)',
            count($result['created']),
            $year,
            count($result['skipped'])
        );

        return $this->ok($result, $message);
    }

    private function resolveYear(Request $request): int
    {
        $year = (int) $request->input('year', $request->query('year', (int) now()->year));

        // Clamp to a sane range so a typo can't generate nonsense lunar math.
        return max(2000, min(2100, $year));
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
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

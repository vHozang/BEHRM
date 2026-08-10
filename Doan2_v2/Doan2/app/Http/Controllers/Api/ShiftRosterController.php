<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShiftRosterService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShiftRosterController extends Controller
{
    public function __construct(private readonly ShiftRosterService $service) {}

    public function calendar(Request $request): JsonResponse
    {
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;
        $weekStart = (string) $request->query(
            'week_start',
            CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY)->toDateString()
        );

        return $this->ok(
            $this->service->calendar($request, $departmentId, $weekStart),
            'Đã tải lịch xếp ca'
        );
    }

    public function template(Request $request): BinaryFileResponse|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'department_id' => 'required|integer|min:1',
            'week_start' => 'required|date',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $file = $this->service->template(
            $request,
            (int) $request->query('department_id'),
            (string) $request->query('week_start')
        );

        return response()->download(
            $file['path'],
            $file['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function rotationPreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'weeks' => 'nullable|integer|min:1|max:26',
            'employee_ids' => 'nullable|array|max:2000',
            'employee_ids.*' => 'integer|min:1',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->ok($this->service->previewRotation($request, $validator->validated()), 'Đã tạo preview lịch xoay');
    }

    public function rotationApply(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'preview_token' => 'required|uuid',
            'overwrite_manual' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->ok(
            $this->service->applyRotation(
                $request,
                (string) $request->input('preview_token'),
                $request->boolean('overwrite_manual')
            ),
            'Đã áp dụng lịch ca xoay'
        );
    }

    public function importPreview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'department_id' => 'required|integer|min:1',
            'file' => 'required|file|mimes:xlsx|max:5120',
        ], [
            'file.mimes' => 'Chỉ chấp nhận file Excel .xlsx tải từ hệ thống',
            'file.max' => 'File Excel không được vượt quá 5 MB',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $path = $request->file('file')?->getRealPath();
        if (! $path) {
            return $this->validationError(['file' => ['Không đọc được file upload']]);
        }

        return $this->ok(
            $this->service->previewImport($request, (int) $request->input('department_id'), $path),
            'Đã đọc và kiểm tra file xếp ca'
        );
    }

    public function importApply(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'preview_token' => 'required|uuid',
            'overwrite_manual' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        return $this->ok(
            $this->service->applyImport(
                $request,
                (string) $request->input('preview_token'),
                $request->boolean('overwrite_manual')
            ),
            'Đã áp dụng lịch xếp ca từ Excel'
        );
    }

    /** Compatibility endpoint for the old ShiftRoster screen. */
    public function generate(Request $request): JsonResponse
    {
        $preview = $this->service->previewRotation($request, $request->all());

        return $this->ok(
            $this->service->applyRotation($request, $preview['preview_token'], $request->boolean('overwrite_manual')),
            'Đã tạo lịch ca xoay'
        );
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

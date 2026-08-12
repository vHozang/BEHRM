<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateTimesheetExport;
use App\Models\AttendanceTimesheetExport;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceTimesheetExportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'month' => ['required', 'date_format:Y-m'],
            'format' => ['nullable', 'in:xlsx,csv'],
            'legal_entity_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }

        $access = (array) $request->attributes->get('access', []);
        $canManage = ! empty($access['full']) || in_array('time', $access['modules'] ?? [], true);
        if (! $canManage) {
            $employeeId = (int) $request->attributes->get('auth_employee_id');
            if ((int) $request->input('employee_id') !== $employeeId || $request->filled('department_id')) {
                return response()->json([
                    'status' => 403,
                    'message' => 'Bạn chỉ được xuất bảng công của chính mình.',
                    'data' => null,
                ], 403);
            }
        }

        $legalEntityId = $this->requestedLegalEntity($request);
        if (! TenantContext::ownsRow('legal_entities', $legalEntityId)) {
            return $this->validationError(['legal_entity_id' => ['Pháp nhân không thuộc công ty hiện tại.']]);
        }
        if ($request->filled('department_id') && ! $this->ownsEntityRow('departments', (int) $request->input('department_id'), $legalEntityId)) {
            return $this->validationError(['department_id' => ['Phòng ban không thuộc pháp nhân được chọn.']]);
        }
        if ($request->filled('employee_id') && ! $this->ownsEntityRow('employees', (int) $request->input('employee_id'), $legalEntityId)) {
            return $this->validationError(['employee_id' => ['Nhân viên không thuộc pháp nhân được chọn.']]);
        }

        $export = AttendanceTimesheetExport::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => TenantContext::id(),
            'legal_entity_id' => $legalEntityId,
            'requested_by' => (int) $request->attributes->get('auth_employee_id'),
            'month' => (string) $request->input('month'),
            'format' => (string) $request->input('format', 'xlsx'),
            'filters' => $request->only(['department_id', 'employee_id']),
            'status' => 'PENDING',
            'expires_at' => now()->addDay(),
        ]);
        GenerateTimesheetExport::dispatch($export->id);

        return response()->json(['status' => 201, 'message' => 'Đã xếp hàng xuất bảng công.', 'data' => $this->resource($export)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $export = $this->owned($request, $id);
        if (! $export) {
            return response()->json(['status' => 404, 'message' => 'Không tìm thấy tác vụ xuất.', 'data' => null], 404);
        }

        return response()->json(['status' => 200, 'message' => 'Trạng thái xuất bảng công.', 'data' => $this->resource($export)]);
    }

    public function download(Request $request, string $id): JsonResponse|BinaryFileResponse
    {
        $export = $this->owned($request, $id);
        if (! $export
            || ($export->expires_at && $export->expires_at->isPast())
            || $export->status !== 'COMPLETED'
            || ! $export->file_path
            || ! Storage::disk('local')->exists($export->file_path)) {
            return response()->json(['status' => 404, 'message' => 'File chưa sẵn sàng hoặc đã hết hạn.', 'data' => null], 404);
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            "bang-cong-{$export->month}.{$export->format}",
            ['Cache-Control' => 'private, no-store']
        );
    }

    private function owned(Request $request, string $id): ?AttendanceTimesheetExport
    {
        $query = AttendanceTimesheetExport::query()->whereKey($id);
        $access = (array) $request->attributes->get('access', []);
        if (empty($access['full'])) {
            $query->where('legal_entity_id', TenantContext::legalEntityId());
        }
        $canManage = ! empty($access['full']) || in_array('time', $access['modules'] ?? [], true);
        if (! $canManage) {
            $query->where('requested_by', (int) $request->attributes->get('auth_employee_id'));
        }

        $export = $query->first();
        if (! $export || $canManage) {
            return $export;
        }

        $filters = is_array($export->filters) ? $export->filters : [];
        $employeeId = (int) ($filters['employee_id'] ?? 0);
        if ($employeeId !== (int) $request->attributes->get('auth_employee_id')) {
            return null;
        }

        return DB::table('employees')
            ->where('id', $employeeId)
            ->where('tenant_id', TenantContext::id())
            ->where('legal_entity_id', $export->legal_entity_id)
            ->exists()
                ? $export
                : null;
    }

    /** @return array<string, mixed> */
    private function resource(AttendanceTimesheetExport $export): array
    {
        return [
            'id' => $export->id,
            'month' => $export->month,
            'format' => $export->format,
            'status' => $export->status,
            'file_size' => $export->file_size,
            'error' => $export->error,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'download_url' => $export->status === 'COMPLETED' ? "/api/v1/attendance/timesheet/exports/{$export->id}/download" : null,
        ];
    }

    private function validationError(array $errors): JsonResponse
    {
        return response()->json(['status' => 422, 'message' => 'Dữ liệu không hợp lệ', 'data' => ['errors' => $errors]], 422);
    }

    private function requestedLegalEntity(Request $request): int
    {
        $access = (array) $request->attributes->get('access', []);
        if (empty($access['full'])) {
            return (int) TenantContext::legalEntityId();
        }

        return (int) ($request->input('legal_entity_id') ?: TenantContext::legalEntityId());
    }

    private function ownsEntityRow(string $table, int $id, int $legalEntityId): bool
    {
        return \Illuminate\Support\Facades\DB::table($table)
            ->where('id', $id)
            ->where('tenant_id', TenantContext::id())
            ->where('legal_entity_id', $legalEntityId)
            ->exists();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\HrmConfig;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Điều phối đồng bộ giữa HRM và bridge chạy trong LAN của máy chấm công.
 * VPS chỉ tạo lệnh; bridge chủ động hỏi lệnh nên không cần mở cổng vào laptop.
 */
class AttendanceDeviceSyncController extends Controller
{
    private const DEFAULT_UPLOAD_DELAY_MINUTES = 15;

    /** HR/Admin xem trạng thái bridge và các yêu cầu đồng bộ của tenant. */
    public function index(Request $request): JsonResponse
    {
        if (! $this->canManageAttendanceSync($request)) {
            return $this->forbidden();
        }

        return $this->ok($this->syncOverview(), 'Trạng thái đồng bộ máy chấm công');
    }

    /** HR/Admin yêu cầu tất cả máy đang hoạt động đồng bộ ngay. */
    public function requestSync(Request $request): JsonResponse
    {
        if (! $this->canManageAttendanceSync($request)) {
            return $this->forbidden();
        }

        $devices = DB::table('attendance_devices')
            ->where('tenant_id', TenantContext::id())
            ->where('status', 'ACTIVE')
            ->when($request->filled('device_id'), fn ($query) => $query->where('id', $request->integer('device_id')))
            ->get();

        if ($devices->isEmpty()) {
            return response()->json([
                'status' => 422,
                'message' => 'Không có máy chấm công đang hoạt động để đồng bộ',
                'data' => null,
            ], 422);
        }

        $requestId = (string) Str::uuid();
        $requestedAt = now()->toIso8601String();
        $employeeId = (int) $request->attributes->get('auth_employee_id');

        DB::transaction(function () use ($devices, $requestId, $requestedAt, $employeeId): void {
            foreach ($devices as $device) {
                $current = DB::table('attendance_devices')->where('id', $device->id)->lockForUpdate()->first();
                if (! $current) {
                    continue;
                }

                $meta = $this->decodeMeta($current->meta);
                $meta['sync_request'] = [
                    'id' => $requestId,
                    'status' => 'PENDING',
                    'requested_at' => $requestedAt,
                    'requested_by' => $employeeId,
                ];

                DB::table('attendance_devices')->where('id', $current->id)->update([
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->ok($this->syncOverview(), 'Đã gửi yêu cầu đồng bộ; bridge sẽ nhận lệnh trong vài giây');
    }

    /** Bridge hỏi cấu hình thời gian chờ và lệnh đồng bộ đang chờ xử lý. */
    public function control(Request $request): JsonResponse
    {
        $device = $this->deviceFromToken($request);
        if (! $device) {
            return $this->invalidDeviceToken();
        }

        TenantContext::set((int) $device->tenant_id, $device->legal_entity_id ? (int) $device->legal_entity_id : null);
        $syncRequest = DB::transaction(function () use ($device): ?array {
            $current = DB::table('attendance_devices')->where('id', $device->id)->lockForUpdate()->first();
            if (! $current) {
                return null;
            }

            $meta = $this->decodeMeta($current->meta);
            $now = now();
            try {
                $lastControlAt = isset($meta['last_control_at']) ? Carbon::parse($meta['last_control_at']) : null;
            } catch (\Throwable) {
                $lastControlAt = null;
            }

            // Heartbeat chỉ ghi tối đa hai lần/phút để bridge poll nhanh mà không gây tải DB.
            if (! $lastControlAt || $lastControlAt->diffInSeconds($now) >= 30) {
                $meta['last_control_at'] = $now->toIso8601String();
                DB::table('attendance_devices')->where('id', $current->id)->update([
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $request = $meta['sync_request'] ?? null;

            return is_array($request) && in_array($request['status'] ?? null, ['PENDING', 'RUNNING'], true)
                ? $request
                : null;
        });

        return $this->ok([
            'device_id' => (int) $device->id,
            'upload_delay_minutes' => $this->uploadDelayMinutes(),
            'sync_request' => $syncRequest,
        ], 'Cấu hình bridge');
    }

    /** Bridge báo đang chạy/hoàn tất/thất bại cho một lệnh đồng bộ tức thời. */
    public function report(Request $request): JsonResponse
    {
        $device = $this->deviceFromToken($request);
        if (! $device) {
            return $this->invalidDeviceToken();
        }

        $validator = Validator::make($request->all(), [
            'request_id' => 'required|string|max:100',
            'status' => 'required|string|in:RUNNING,SUCCESS,FAILED',
            'processed' => 'nullable|integer|min:0',
            'error' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'message' => 'Dữ liệu trạng thái đồng bộ không hợp lệ',
                'data' => ['errors' => $validator->errors()->toArray()],
            ], 422);
        }

        $status = strtoupper((string) $request->input('status'));
        $requestId = (string) $request->input('request_id');
        $updatedMeta = null;

        $matched = DB::transaction(function () use ($device, $request, $status, $requestId, &$updatedMeta): bool {
            $current = DB::table('attendance_devices')->where('id', $device->id)->lockForUpdate()->first();
            if (! $current) {
                return false;
            }

            $meta = $this->decodeMeta($current->meta);
            $syncRequest = is_array($meta['sync_request'] ?? null) ? $meta['sync_request'] : [];
            if (($syncRequest['id'] ?? null) !== $requestId) {
                return false;
            }

            $now = now()->toIso8601String();
            $syncRequest['status'] = $status;
            if ($status === 'RUNNING') {
                $syncRequest['started_at'] = $syncRequest['started_at'] ?? $now;
            } else {
                $syncRequest['completed_at'] = $now;
                $syncRequest['processed'] = max(0, (int) $request->input('processed', 0));
                $syncRequest['error'] = $status === 'FAILED' ? $request->input('error') : null;
                $meta['last_sync'] = [
                    'status' => $status,
                    'completed_at' => $now,
                    'processed' => $syncRequest['processed'],
                    'error' => $syncRequest['error'],
                    'trigger' => 'manual',
                ];
            }

            $meta['sync_request'] = $syncRequest;
            $meta['last_control_at'] = $now;
            DB::table('attendance_devices')->where('id', $current->id)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            $updatedMeta = $meta;

            return true;
        });

        if (! $matched) {
            return response()->json([
                'status' => 409,
                'message' => 'Yêu cầu đồng bộ đã được thay thế hoặc không còn tồn tại',
                'data' => null,
            ], 409);
        }

        return $this->ok([
            'device_id' => (int) $device->id,
            'sync_request' => $updatedMeta['sync_request'] ?? null,
        ], 'Đã cập nhật trạng thái đồng bộ');
    }

    private function syncOverview(): array
    {
        $devices = DB::table('attendance_devices')
            ->where('tenant_id', TenantContext::id())
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get()
            ->map(function ($device): array {
                $meta = $this->decodeMeta($device->meta);
                $lastControlAt = $meta['last_control_at'] ?? null;
                $online = false;
                if ($lastControlAt) {
                    try {
                        $online = Carbon::parse($lastControlAt)->greaterThanOrEqualTo(now()->subMinutes(2));
                    } catch (\Throwable) {
                        $online = false;
                    }
                }

                return [
                    'id' => (int) $device->id,
                    'name' => $device->name,
                    'location' => $device->location,
                    'online' => $online,
                    'last_seen_at' => $device->last_seen_at,
                    'last_control_at' => $lastControlAt,
                    'sync_request' => $meta['sync_request'] ?? null,
                    'last_sync' => $meta['last_sync'] ?? null,
                ];
            })
            ->values()
            ->all();

        return [
            'upload_delay_minutes' => $this->uploadDelayMinutes(),
            'devices' => $devices,
        ];
    }

    private function uploadDelayMinutes(): int
    {
        return min(1440, max(1, (int) HrmConfig::get(
            'attendance.device_upload_delay_minutes',
            self::DEFAULT_UPLOAD_DELAY_MINUTES
        )));
    }

    private function canManageAttendanceSync(Request $request): bool
    {
        $employee = $request->attributes->get('auth_employee');
        if (! is_array($employee) || empty($employee['id'])) {
            return false;
        }
        if (! empty($employee['is_super_admin'])) {
            return true;
        }

        $roles = DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.employee_id', $employee['id'])
            ->where('er.tenant_id', TenantContext::id())
            ->whereRaw('er.is_active = true')
            ->get(['r.role_code', 'r.meta']);

        foreach ($roles as $role) {
            $meta = $this->decodeMeta($role->meta);
            if (in_array(strtoupper((string) $role->role_code), ['ADMIN', 'TENANT_ADMIN', 'HR'], true)
                || ! empty($meta['is_admin'])) {
                return true;
            }
        }

        return false;
    }

    private function deviceFromToken(Request $request): ?object
    {
        $token = $request->header('x-device-token') ?: $request->input('device_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        return DB::table('attendance_devices')
            ->where('device_token', $token)
            ->where('status', 'ACTIVE')
            ->first();
    }

    private function decodeMeta(mixed $meta): array
    {
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($meta) ? $meta : (array) ($meta ?? []);
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return response()->json(['status' => 200, 'message' => $message, 'data' => $data]);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'status' => 403,
            'message' => 'Chỉ HR hoặc Admin được đồng bộ máy chấm công',
            'data' => null,
        ], 403);
    }

    private function invalidDeviceToken(): JsonResponse
    {
        return response()->json([
            'status' => 403,
            'message' => 'Device token không hợp lệ',
            'data' => null,
        ], 403);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TimePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingestion chấm công từ MÁY CHẤM CÔNG (vân tay / khuôn mặt / thẻ).
 *
 * Mọi loại thiết bị đều quy về một "punch" chung: { enroll_id, timestamp,
 * device_id, verify_method }. Một bridge (node-zklib/pyzk đọc máy ZKTeco/Wise Eye
 * qua TCP 4370, hoặc import file Wise Eye On 39) gọi endpoint này.
 *
 * Bảo vệ bằng x-internal-token (env INTERNAL_SERVICE_TOKEN) — KHÔNG dùng JWT vì
 * bridge chạy headless. Tự quyết định check-in/out theo thứ tự thời gian trong
 * ngày, phân loại trễ/sớm theo ca (dùng lại TimePolicy), ghi meta.source='device'.
 */
class DeviceAttendanceController extends Controller
{
    public function punch(Request $request): JsonResponse
    {
        // Ưu tiên xác thực theo THIẾT BỊ (đa-tenant): mỗi máy 1 device_token →
        // tự suy ra đúng công ty. Fallback token nội bộ chung (1 tenant/test).
        $device = null;
        $deviceToken = $request->header('x-device-token') ?: $request->input('device_token');
        if ($deviceToken) {
            $device = DB::table('attendance_devices')
                ->where('device_token', $deviceToken)
                ->where('status', 'ACTIVE')
                ->first();
            if (! $device) {
                return response()->json(['status' => 403, 'message' => 'Device token không hợp lệ', 'data' => null], 403);
            }
            DB::table('attendance_devices')->where('id', $device->id)->update(['last_seen_at' => now()]);
        } else {
            $provided = $request->header('x-internal-token') ?: $request->bearerToken();
            $expected = config('hrm.internal_service_token') ?: env('INTERNAL_SERVICE_TOKEN');
            if (! $expected || $provided !== $expected) {
                return response()->json(['status' => 403, 'message' => 'Thiếu device token hoặc internal token hợp lệ', 'data' => null], 403);
            }
        }

        // Cho phép 1 punch hoặc mảng punches (batch).
        $punches = $request->input('punches');
        if (! is_array($punches)) {
            $punches = [[
                'enroll_id' => $request->input('enroll_id'),
                'timestamp' => $request->input('timestamp'),
                'device_id' => $request->input('device_id'),
                'verify_method' => $request->input('verify_method'),
            ]];
        }

        $processed = 0;
        $errors = [];

        foreach ($punches as $i => $p) {
            try {
                $this->applyPunch($p, $device);
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = ['index' => $i, 'enroll_id' => $p['enroll_id'] ?? null, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'status' => 200,
            'message' => "Đã nhận {$processed} lượt chấm công từ máy",
            'data' => ['processed' => $processed, 'errors' => $errors],
        ]);
    }

    private function applyPunch(array $p, $device = null): void
    {
        $enroll = (string) ($p['enroll_id'] ?? '');
        $ts = $p['timestamp'] ?? null;
        if ($enroll === '' || ! $ts) {
            throw new \RuntimeException('Thiếu enroll_id hoặc timestamp');
        }
        $when = Carbon::parse($ts);
        $workDate = $when->toDateString();
        $time = $when->toTimeString();

        // Khi xác thực bằng device token → chỉ tìm nhân viên trong đúng tenant.
        $employee = $this->resolveEmployee($enroll, $device->tenant_id ?? null);
        if (! $employee) {
            throw new \RuntimeException("Không tìm thấy nhân viên cho enroll_id={$enroll}");
        }
        // device_id ưu tiên tên máy đã đăng ký.
        if ($device) {
            $p['device_id'] = $p['device_id'] ?? $device->name;
        }

        $shift = $this->resolveShift($employee);
        $attendance = DB::table('attendances')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('work_date', $workDate)
            ->first();

        $deviceMeta = [
            'device_id' => $p['device_id'] ?? null,
            'verify_method' => $p['verify_method'] ?? null, // fingerprint|face|card
            'source' => 'device',
        ];

        if (! $attendance) {
            // Lượt đầu trong ngày = check-in.
            $cls = TimePolicy::classifyAttendance($shift, $time, null);
            DB::table('attendances')->insert([
                'employee_id' => $employee->id,
                'work_date' => $workDate,
                'check_in_time' => $time,
                'shift_type_id' => $shift->id ?? null,
                'status' => $cls['status'],
                'meta' => json_encode(array_merge($deviceMeta, [
                    'late_minutes' => $cls['late_minutes'],
                ]), JSON_UNESCAPED_UNICODE),
                'tenant_id' => $employee->tenant_id,
                'legal_entity_id' => $employee->legal_entity_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // Đã có check-in → punch sau đó (muộn hơn) = check-out (lấy mốc muộn nhất).
        $checkIn = (string) $attendance->check_in_time;
        if ($checkIn && $time < substr($checkIn, 0, 8)) {
            // Punch sớm hơn giờ vào hiện tại → cập nhật giờ vào sớm hơn.
            $cls = TimePolicy::classifyAttendance($shift, $time, $attendance->check_out_time);
            $this->update($attendance->id, ['check_in_time' => $time], $cls, $attendance->meta, $deviceMeta);

            return;
        }

        $cls = TimePolicy::classifyAttendance($shift, $checkIn ?: $time, $time);
        $this->update($attendance->id, ['check_out_time' => $time], $cls, $attendance->meta, $deviceMeta);

        // Đi làm ngày nghỉ/lễ → tự đề xuất đơn tăng ca chờ duyệt.
        \App\Support\OvertimeSuggester::suggest([
            'employee_id' => (int) $employee->id,
            'tenant_id' => (int) $employee->tenant_id,
            'work_date' => $workDate,
            'check_in' => $checkIn ?: $time,
            'check_out' => $time,
            'shift' => $shift,
        ]);
    }

    private function update(int $id, array $cols, array $cls, $existingMeta, array $deviceMeta): void
    {
        $meta = is_string($existingMeta) ? (json_decode($existingMeta, true) ?: []) : (array) ($existingMeta ?? []);
        $meta = array_merge($meta, $deviceMeta, [
            'late_minutes' => $cls['late_minutes'],
            'early_leave_minutes' => $cls['early_leave_minutes'],
            'worked_hours' => $cls['worked_hours'],
        ]);
        DB::table('attendances')->where('id', $id)->update(array_merge($cols, [
            'status' => $cls['status'],
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]));
    }

    /** Ánh xạ enroll_id (số đăng ký trên máy) → nhân viên (giới hạn theo tenant nếu có). */
    private function resolveEmployee(string $enroll, ?int $tenantId = null): ?object
    {
        $scope = fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q;

        // 1) Khớp profile.enroll_id (chuẩn).
        $emp = DB::table('employees')
            ->where(fn ($q) => $scope($q))
            ->whereRaw("profile->>'enroll_id' = ?", [$enroll])
            ->first(['id', 'tenant_id', 'legal_entity_id']);
        if ($emp) {
            return $emp;
        }
        // 2) Fallback: khớp employee_code, hoặc id (tiện test).
        return DB::table('employees')
            ->where(fn ($q) => $scope($q))
            ->where(fn ($q) => $q->where('employee_code', $enroll)->orWhere('id', is_numeric($enroll) ? (int) $enroll : 0))
            ->first(['id', 'tenant_id', 'legal_entity_id']);
    }

    private function resolveShift(object $employee): ?object
    {
        $assignment = DB::table('shift_assignments')
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 'INACTIVE')
            ->orderByDesc('effective_date')
            ->first();
        if (! $assignment || ! $assignment->shift_type_id) {
            return null;
        }

        return DB::table('shift_types')->where('id', $assignment->shift_type_id)->first();
    }
}

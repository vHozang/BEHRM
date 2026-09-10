<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\AttendanceDayLock;
use App\Services\AttendanceReconciliationService;
use App\Services\AttendanceTimeCalculator;
use App\Services\ShiftResolver;
use App\Support\OvertimeSuggester;
use App\Support\TenantContext;
use App\Support\TimePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingestion chấm công từ MÁY CHẤM CÔNG (vân tay / khuôn mặt / thẻ).
 *
 * Mọi loại thiết bị đều quy về một "punch" chung: { enroll_id, timestamp,
 * device_id, verify_method, punch_state, device_state }. Một bridge
 * (node-zklib/pyzk đọc máy ZKTeco/Wise Eye qua TCP 4370, hoặc import file Wise
 * Eye On 39) gọi endpoint này.
 *
 * Bảo vệ bằng x-internal-token (env INTERNAL_SERVICE_TOKEN) — KHÔNG dùng JWT vì
 * bridge chạy headless. Ưu tiên trạng thái Check-In/Check-Out do máy gửi; chỉ
 * suy luận theo thời gian với thiết bị cũ không có trạng thái. Phân loại trễ/sớm
 * theo ca (dùng lại TimePolicy), ghi meta.source='device'.
 */
class DeviceAttendanceController extends Controller
{
    private const ENTRY_STATES = ['CHECK_IN', 'OVERTIME_IN'];

    private const EXIT_STATES = ['CHECK_OUT', 'OVERTIME_OUT'];

    private const INFORMATIONAL_STATES = ['BREAK_OUT', 'BREAK_IN'];

    public function __construct(
        private readonly ShiftResolver $shiftResolver,
        private readonly AttendanceTimeCalculator $timeCalculator,
        private readonly AttendanceReconciliationService $attendanceReconciliation,
        private readonly AttendanceDayLock $attendanceDayLock,
    ) {}

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
            $tenantId = (int) config('hrm.internal_attendance_tenant_id');
            if ($tenantId <= 0 || ! DB::table('tenants')->where('id', $tenantId)->exists()) {
                return response()->json([
                    'status' => 403,
                    'message' => 'Legacy internal token chưa được khóa vào tenant cố định.',
                    'data' => null,
                ], 403);
            }
            $device = (object) ['tenant_id' => $tenantId, 'name' => null];
        }

        // Cho phép 1 punch hoặc mảng punches (batch).
        $punches = $request->input('punches');
        if (! is_array($punches)) {
            $punches = [[
                'enroll_id' => $request->input('enroll_id'),
                'timestamp' => $request->input('timestamp'),
                'device_id' => $request->input('device_id'),
                'verify_method' => $request->input('verify_method'),
                'punch_state' => $request->input('punch_state'),
                'device_state' => $request->input('device_state'),
            ]];
        }
        if (count($punches) > 200) {
            return response()->json([
                'status' => 422,
                'message' => 'Mỗi request chỉ được gửi tối đa 200 punch.',
                'data' => ['errors' => ['punches' => ['Tối đa 200 punch/request.']]],
            ], 422);
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
        // Khi xác thực bằng device token → chỉ tìm nhân viên trong đúng tenant.
        $employee = $this->resolveEmployee($enroll, $device->tenant_id ?? null);
        if (! $employee) {
            throw new \RuntimeException("Không tìm thấy nhân viên cho enroll_id={$enroll}");
        }

        $timezone = DB::table('tenants')->where('id', $employee->tenant_id)->value('timezone')
            ?: 'Asia/Ho_Chi_Minh';
        $timestamp = trim((string) $ts);
        $hasExplicitTimezone = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $timestamp);
        $when = $hasExplicitTimezone
            ? Carbon::parse($timestamp)->setTimezone($timezone)
            : Carbon::parse($timestamp, $timezone);
        $time = $when->toTimeString();
        $punchState = $this->normalizePunchState(
            $p['punch_state'] ?? null,
            $p['device_state'] ?? null
        );

        // device_id ưu tiên tên máy đã đăng ký.
        if ($device) {
            $p['device_id'] = $p['device_id'] ?? $device->name;
        }

        [$workDate, $shift] = $this->resolvePunchContext($employee, $when);
        if ($this->attendanceReconciliation->isClosedWorkDate(
            (int) $employee->tenant_id,
            (int) $employee->legal_entity_id,
            $workDate,
        )) {
            throw new \RuntimeException('Kỳ lương chứa ngày chấm công này đã chốt, không thể nhận thêm punch.');
        }
        $deviceMeta = [
            'device_id' => $p['device_id'] ?? null,
            'verify_method' => $p['verify_method'] ?? null, // fingerprint|face|card
            'punch_state' => $punchState,
            'device_state' => $this->normalizeDeviceState($p['device_state'] ?? null),
            'source' => 'device',
        ];
        $deviceEvent = array_merge($deviceMeta, [
            'timestamp' => $when->format('Y-m-d H:i:s'),
        ]);

        TenantContext::set((int) $employee->tenant_id, (int) $employee->legal_entity_id);
        try {
            $this->attendanceDayLock->run(
                (int) $employee->tenant_id,
                (int) $employee->id,
                $workDate,
                function () use ($employee, $workDate, $shift, $time, $punchState, $deviceMeta, $deviceEvent): void {
                    $attendance = DB::table('attendances')
                        ->where('tenant_id', $employee->tenant_id)
                        ->where('employee_id', $employee->id)
                        ->where('work_date', $workDate)
                        ->lockForUpdate()
                        ->first();
                    $this->applyLockedPunch($employee, $workDate, $shift, $time, $punchState, $deviceMeta, $deviceEvent, $attendance);
                },
            );
        } finally {
            TenantContext::clear();
        }
    }

    private function applyLockedPunch(
        object $employee,
        string $workDate,
        ?object $shift,
        string $time,
        string $punchState,
        array $deviceMeta,
        array $deviceEvent,
        ?object $attendance,
    ): void {

        if (! $attendance) {
            $checkIn = in_array($punchState, self::ENTRY_STATES, true) || $punchState === 'AUTO'
                ? $time
                : null;
            $checkOut = in_array($punchState, self::EXIT_STATES, true) ? $time : null;
            $cls = TimePolicy::classifyAttendance($shift, $checkIn, $checkOut);
            $this->insertAttendance(
                $employee,
                $workDate,
                $shift,
                $checkIn,
                $checkOut,
                $cls,
                $deviceMeta,
                $deviceEvent
            );

            return;
        }

        $checkIn = $attendance->check_in_time ? substr((string) $attendance->check_in_time, 0, 8) : null;
        $checkOut = $attendance->check_out_time ? substr((string) $attendance->check_out_time, 0, 8) : null;

        // Hai trạng thái nghỉ chỉ dùng cho audit; không được phép thay đổi mốc
        // vào/ra chính của ngày công.
        if (in_array($punchState, self::INFORMATIONAL_STATES, true)) {
            $this->updateAttendance(
                $attendance->id,
                [],
                null,
                $attendance->meta,
                $deviceMeta,
                $deviceEvent
            );

            return;
        }

        if (in_array($punchState, self::ENTRY_STATES, true)) {
            $nextCheckIn = ! $checkIn || $this->isEarlierPunch($workDate, $time, $checkIn, $shift, true) ? $time : $checkIn;
            $cls = TimePolicy::classifyAttendance($shift, $nextCheckIn, $checkOut);
            $this->updateAttendance(
                $attendance->id,
                $nextCheckIn !== $checkIn ? ['check_in_time' => $nextCheckIn] : [],
                $cls,
                $attendance->meta,
                $deviceMeta,
                $deviceEvent
            );
            $this->suggestOvertime($employee, $workDate, $nextCheckIn, $checkOut, $shift);

            return;
        }

        if (in_array($punchState, self::EXIT_STATES, true)) {
            $nextCheckOut = ! $checkOut || $this->isLaterPunch($workDate, $time, $checkOut, $shift) ? $time : $checkOut;
            $cls = TimePolicy::classifyAttendance($shift, $checkIn, $nextCheckOut);
            $this->updateAttendance(
                $attendance->id,
                $nextCheckOut !== $checkOut ? ['check_out_time' => $nextCheckOut] : [],
                $cls,
                $attendance->meta,
                $deviceMeta,
                $deviceEvent
            );
            $this->suggestOvertime($employee, $workDate, $checkIn, $nextCheckOut, $shift);

            return;
        }

        // Fallback cho thiết bị/nguồn import cũ không gửi trạng thái. Punch đầu
        // vẫn là giờ vào, nhưng punch lặp sớm không được tự biến thành giờ ra.
        if (! $checkIn || $this->isEarlierPunch($workDate, $time, $checkIn, $shift, true)) {
            $nextCheckIn = ! $checkIn || $this->isEarlierPunch($workDate, $time, $checkIn, $shift, true) ? $time : $checkIn;
            $cls = TimePolicy::classifyAttendance($shift, $nextCheckIn, $checkOut);
            $this->updateAttendance(
                $attendance->id,
                $nextCheckIn !== $checkIn ? ['check_in_time' => $nextCheckIn] : [],
                $cls,
                $attendance->meta,
                $deviceMeta,
                $deviceEvent
            );

            return;
        }

        if ($checkOut && ! $this->isLaterPunch($workDate, $time, $checkOut, $shift)) {
            $this->updateAttendance($attendance->id, [], null, $attendance->meta, $deviceMeta, $deviceEvent);

            return;
        }

        $minimumMinutes = max(1, (int) config('hrm.attendance.device_auto_checkout_min_minutes', 60));
        if (! $checkOut && $this->minutesBetween($checkIn, $time) < $minimumMinutes) {
            $this->updateAttendance($attendance->id, [], null, $attendance->meta, $deviceMeta, $deviceEvent);

            return;
        }

        $cls = TimePolicy::classifyAttendance($shift, $checkIn, $time);
        $this->updateAttendance(
            $attendance->id,
            ['check_out_time' => $time],
            $cls,
            $attendance->meta,
            $deviceMeta,
            $deviceEvent
        );
        $this->suggestOvertime($employee, $workDate, $checkIn, $time, $shift);
    }

    private function insertAttendance(
        object $employee,
        string $workDate,
        ?object $shift,
        ?string $checkIn,
        ?string $checkOut,
        array $cls,
        array $deviceMeta,
        array $deviceEvent
    ): void {
        $meta = $this->mergeDeviceMeta([], $deviceMeta, $deviceEvent, $cls);
        DB::table('attendances')->insert([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'check_in_time' => $checkIn,
            'check_out_time' => $checkOut,
            'shift_type_id' => $shift->id ?? null,
            'status' => $cls['status'],
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'tenant_id' => $employee->tenant_id,
            'legal_entity_id' => $employee->legal_entity_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attendance = Attendance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('work_date', $workDate)
            ->first();
        if ($attendance) {
            $this->attendanceReconciliation->reconcileWithShift($attendance, $shift, null, false);
        }
    }

    private function suggestOvertime(object $employee, string $workDate, ?string $checkIn, ?string $checkOut, ?object $shift): void
    {
        if (! $checkIn || ! $checkOut) {
            return;
        }

        OvertimeSuggester::suggest([
            'employee_id' => (int) $employee->id,
            'tenant_id' => (int) $employee->tenant_id,
            'work_date' => $workDate,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'shift' => $shift,
        ]);
    }

    private function updateAttendance(
        int $id,
        array $cols,
        ?array $cls,
        $existingMeta,
        array $deviceMeta,
        array $deviceEvent
    ): void {
        $meta = $this->mergeDeviceMeta($existingMeta, $deviceMeta, $deviceEvent, $cls);
        $changes = array_merge($cols, [
            'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
        if ($cls !== null) {
            $changes['status'] = $cls['status'];
        }
        DB::table('attendances')->where('id', $id)->update($changes);
        $attendance = Attendance::find($id);
        if ($attendance) {
            $this->attendanceReconciliation->reconcile($attendance, null, false);
        }
    }

    private function mergeDeviceMeta($existingMeta, array $deviceMeta, array $deviceEvent, ?array $cls): array
    {
        $meta = is_string($existingMeta)
            ? (json_decode($existingMeta, true) ?: [])
            : (array) ($existingMeta ?? []);
        $meta = array_merge($meta, $deviceMeta);
        if ($cls !== null) {
            $meta = array_merge($meta, [
                'late_minutes' => $cls['late_minutes'],
                'early_leave_minutes' => $cls['early_leave_minutes'],
                'worked_hours' => $cls['worked_hours'],
            ]);
        }

        $events = is_array($meta['device_events'] ?? null) ? $meta['device_events'] : [];
        $alreadyRecorded = collect($events)->contains(fn ($event) => is_array($event)
            && ($event['timestamp'] ?? null) === $deviceEvent['timestamp']
            && ($event['punch_state'] ?? null) === $deviceEvent['punch_state']
            && ($event['device_id'] ?? null) === $deviceEvent['device_id']
        );
        if (! $alreadyRecorded) {
            $events[] = $deviceEvent;
        }
        $meta['device_events'] = array_slice($events, -200);

        return $meta;
    }

    private function normalizePunchState($value, $deviceState): string
    {
        $normalized = $this->parsePunchState($value);
        if ($normalized && $normalized !== 'AUTO') {
            return $normalized;
        }

        return $this->parsePunchState($deviceState) ?: 'AUTO';
    }

    private function parsePunchState($value): ?string
    {
        $byCode = [
            0 => 'CHECK_IN',
            1 => 'CHECK_OUT',
            2 => 'BREAK_OUT',
            3 => 'BREAK_IN',
            4 => 'OVERTIME_IN',
            5 => 'OVERTIME_OUT',
        ];
        if (is_numeric($value) && isset($byCode[(int) $value])) {
            return $byCode[(int) $value];
        }
        if (! is_string($value)) {
            return null;
        }

        $state = strtoupper(str_replace([' ', '-'], '_', trim($value)));
        $aliases = [
            'CHECKIN' => 'CHECK_IN',
            'IN' => 'CHECK_IN',
            'CHECKOUT' => 'CHECK_OUT',
            'OUT' => 'CHECK_OUT',
            'BREAKOUT' => 'BREAK_OUT',
            'BREAKIN' => 'BREAK_IN',
            'OVERTIMEIN' => 'OVERTIME_IN',
            'OVERTIMEOUT' => 'OVERTIME_OUT',
            'OT_IN' => 'OVERTIME_IN',
            'OT_OUT' => 'OVERTIME_OUT',
        ];
        $state = $aliases[$state] ?? $state;

        return in_array($state, array_merge(self::ENTRY_STATES, self::EXIT_STATES, self::INFORMATIONAL_STATES, ['AUTO']), true)
            ? $state
            : null;
    }

    private function normalizeDeviceState($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return mb_substr((string) $value, 0, 50);
    }

    private function minutesBetween(string $from, string $to): int
    {
        $toSeconds = $this->timeToSeconds($to);
        $fromSeconds = $this->timeToSeconds($from);
        if ($toSeconds < $fromSeconds) {
            $toSeconds += 86400;
        }

        return max(0, intdiv($toSeconds - $fromSeconds, 60));
    }

    private function timeToSeconds(string $time): int
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', substr($time, 0, 8))), 3, 0);

        return $hour * 3600 + $minute * 60 + $second;
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

    /** @return array{0:string,1:?object} */
    private function resolvePunchContext(object $employee, Carbon $when): array
    {
        $best = null;
        foreach ([$when->toDateString(), $when->copy()->subDay()->toDateString()] as $date) {
            $assignment = $this->shiftResolver->resolve((int) $employee->id, $date, (int) $employee->tenant_id);
            if (! $assignment || ! $assignment->shift_type_id
                || ! $this->shiftResolver->isAssignmentWorkday($assignment, $date)) {
                continue;
            }
            $shift = DB::table('shift_types')
                ->where('tenant_id', $employee->tenant_id)
                ->where('id', $assignment->shift_type_id)
                ->first();
            if (! $shift) {
                continue;
            }
            $schedule = $this->timeCalculator->calculate($shift, $date, null, null);
            $start = Carbon::parse($schedule['shift_start'], $when->timezone);
            $end = Carbon::parse($schedule['shift_end'], $when->timezone);
            $distance = $when->lt($start)
                ? $when->diffInMinutes($start)
                : ($when->gt($end) ? $end->diffInMinutes($when) : 0);
            if ($best === null || $distance < $best['distance']) {
                $best = ['date' => $date, 'shift' => $shift, 'distance' => $distance];
            }
        }

        return $best
            ? [$best['date'], $best['shift']]
            : [$when->toDateString(), null];
    }

    private function isEarlierPunch(string $workDate, string $candidate, string $current, ?object $shift, bool $entry): bool
    {
        return $this->absolutePunch($workDate, $candidate, $shift, $entry)
            ->lt($this->absolutePunch($workDate, $current, $shift, $entry));
    }

    private function isLaterPunch(string $workDate, string $candidate, string $current, ?object $shift): bool
    {
        return $this->absolutePunch($workDate, $candidate, $shift, false)
            ->gt($this->absolutePunch($workDate, $current, $shift, false));
    }

    private function absolutePunch(string $workDate, string $time, ?object $shift, bool $entry): Carbon
    {
        $base = Carbon::parse($workDate.' '.$time);
        if (! $shift) {
            return $base;
        }

        $schedule = $this->timeCalculator->calculate($shift, $workDate, null, null);
        $target = Carbon::parse($entry ? $schedule['shift_start'] : $schedule['shift_end']);
        $candidates = [$base->copy()->subDay(), $base, $base->copy()->addDay()];
        usort($candidates, fn (Carbon $left, Carbon $right) => $left->diffInSeconds($target) <=> $right->diffInSeconds($target));

        return $candidates[0];
    }
}

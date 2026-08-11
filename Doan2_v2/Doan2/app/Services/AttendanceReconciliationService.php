<?php

namespace App\Services;

use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttendanceReconciliationService
{
    public function __construct(
        private readonly AttendanceTimeCalculator $calculator,
        private readonly AttendancePayrollReviewService $reviews,
        private readonly ShiftResolver $shiftResolver,
    ) {}

    public function reconcile(Attendance $attendance, ?int $actorId = null, bool $guardClosedPeriod = true): array
    {
        return $this->reconcileInternal($attendance, $actorId, $guardClosedPeriod, null, false);
    }

    public function reconcileWithShift(
        Attendance $attendance,
        ?object $shift,
        ?int $actorId = null,
        bool $guardClosedPeriod = true,
    ): array {
        return $this->reconcileInternal($attendance, $actorId, $guardClosedPeriod, $shift, true);
    }

    private function reconcileInternal(
        Attendance $attendance,
        ?int $actorId,
        bool $guardClosedPeriod,
        ?object $shiftOverride,
        bool $hasShiftOverride,
    ): array
    {
        if ($guardClosedPeriod && $this->isClosedDate($attendance)) {
            throw new RuntimeException('Kỳ lương chứa ngày chấm công này đã chốt, không thể sửa trực tiếp.', 409);
        }

        $shift = $hasShiftOverride ? $shiftOverride : $this->shiftForAttendance($attendance);
        $calculation = $this->calculator->calculate(
            $shift,
            $attendance->work_date->toDateString(),
            $attendance->check_in_time,
            $attendance->check_out_time,
            $attendance->check_in_time_2,
            $attendance->check_out_time_2,
        );

        $meta = $this->decodeMeta($attendance->meta);
        foreach ([
            'regular_worked_minutes',
            'raw_presence_minutes',
            'early_arrival_minutes',
            'late_minutes',
            'early_leave_minutes',
            'after_shift_minutes',
            'scheduled_minutes',
            'worked_hours',
            'shift_start',
            'shift_end',
            'shift_intervals',
            'presence_intervals',
            'outside_shift_intervals',
        ] as $key) {
            $meta[$key] = $calculation[$key];
        }
        $meta['attendance_calculation_version'] = 2;
        $meta['classified_by'] = 'attendance-time-calculator';
        $meta['classified_at'] = now()->toIso8601String();

        $changes = ['meta' => $meta];
        if (! in_array(strtoupper((string) $attendance->status), ['APPROVED', 'ĐÃ_DUYỆT'], true)) {
            $changes['status'] = $calculation['status'];
        }
        if (! $attendance->shift_type_id && $shift?->id) {
            $changes['shift_type_id'] = $shift->id;
        }

        $attendance->update($changes);
        $review = $this->reviews->sync($attendance->fresh(), $calculation, $actorId);

        $calculation['payroll_review'] = $review?->toArray();

        return $calculation;
    }

    public function isClosedDate(Attendance $attendance): bool
    {
        return $this->isClosedWorkDate(
            (int) $attendance->tenant_id,
            (int) $attendance->legal_entity_id,
            $attendance->work_date->toDateString(),
        );
    }

    public function isClosedWorkDate(int $tenantId, int $legalEntityId, string $workDate): bool
    {
        return DB::table('salary_periods')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('start_date', '<=', $workDate)
            ->where('end_date', '>=', $workDate)
            ->whereIn('status', ['CLOSED', 'LOCKED', 'PAID', 'ĐÃ_ĐÓNG', 'DA_DONG', 'ĐÃ_TRẢ', 'DA_TRA'])
            ->exists();
    }

    private function shiftForAttendance(Attendance $attendance): ?object
    {
        $assignment = $this->shiftResolver->resolve(
            (int) $attendance->employee_id,
            $attendance->work_date->toDateString(),
            (int) $attendance->tenant_id,
        );
        if ($assignment && ! $this->shiftResolver->isAssignmentWorkday($assignment, $attendance->work_date->toDateString())) {
            return null;
        }

        $shiftId = $attendance->shift_type_id ?: ($assignment->shift_type_id ?? null);
        if (! $shiftId) {
            return null;
        }

        return DB::table('shift_types')
            ->where('id', $shiftId)
            ->where('tenant_id', $attendance->tenant_id)
            ->first();
    }

    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_object($meta)) {
            return (array) $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

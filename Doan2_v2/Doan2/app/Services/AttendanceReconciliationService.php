<?php

namespace App\Services;

use App\Jobs\ReconcileOvertimeDay;
use App\Models\Attendance;
use App\Models\AttendancePayrollReview;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AttendanceReconciliationService
{
    public function __construct(
        private readonly AttendanceTimeCalculator $calculator,
        private readonly AttendancePayrollReviewService $reviews,
        private readonly ShiftResolver $shiftResolver,
    ) {}

    public function reconcile(
        Attendance $attendance,
        ?int $actorId = null,
        bool $guardClosedPeriod = true,
        bool $dispatchOvertime = true,
    ): array {
        return $this->reconcileInternal($attendance, $actorId, $guardClosedPeriod, null, false, $dispatchOvertime);
    }

    public function reconcileWithShift(
        Attendance $attendance,
        ?object $shift,
        ?int $actorId = null,
        bool $guardClosedPeriod = true,
        bool $dispatchOvertime = true,
    ): array {
        return $this->reconcileInternal($attendance, $actorId, $guardClosedPeriod, $shift, true, $dispatchOvertime);
    }

    /**
     * Prepare deterministic attendance changes without writing the row. Batch
     * recompute uses this to issue one upsert per chunk instead of one UPDATE
     * per attendance record.
     *
     * @return array{calculation: array<string, mixed>, changes: array<string, mixed>}
     */
    public function prepareWithShift(
        Attendance $attendance,
        ?object $shift,
        bool $guardClosedPeriod = true,
    ): array {
        return $this->prepareInternal($attendance, $guardClosedPeriod, $shift, true);
    }

    public function syncPreparedReview(
        Attendance $attendance,
        array $calculation,
        ?int $actorId = null,
        bool $notify = true,
    ): ?AttendancePayrollReview {
        return $this->reviews->sync($attendance, $calculation, $actorId, $notify);
    }

    /**
     * @return array{review:?AttendancePayrollReview,changed:bool,notification:?string}
     */
    public function syncPreparedReviewWithOutcome(
        Attendance $attendance,
        array $calculation,
        ?int $actorId = null,
        bool $notify = true,
    ): array {
        return $this->reviews->syncWithOutcome($attendance, $calculation, $actorId, $notify);
    }

    /**
     * @return array{review:?AttendancePayrollReview,changed:bool,notification:?string}
     */
    public function syncPreparedKnownReviewWithOutcome(
        Attendance $attendance,
        array $calculation,
        ?AttendancePayrollReview $review,
        ?int $actorId = null,
        bool $notify = true,
    ): array {
        return $this->reviews->syncKnownReviewWithOutcome(
            $attendance,
            $calculation,
            $review,
            $actorId,
            $notify,
        );
    }

    private function reconcileInternal(
        Attendance $attendance,
        ?int $actorId,
        bool $guardClosedPeriod,
        ?object $shiftOverride,
        bool $hasShiftOverride,
        bool $dispatchOvertime,
    ): array {
        $prepared = $this->prepareInternal(
            $attendance,
            $guardClosedPeriod,
            $shiftOverride,
            $hasShiftOverride,
        );
        $calculation = $prepared['calculation'];
        $changes = $prepared['changes'];

        if ($changes !== []) {
            $attendance->update($changes);
        }
        $review = $this->reviews->sync($attendance->fresh(), $calculation, $actorId);

        $reviewChanged = $review?->wasRecentlyCreated || ($review && $review->wasChanged());
        if ($dispatchOvertime && ($changes !== [] || $reviewChanged)) {
            DB::afterCommit(function () use ($attendance): void {
                ReconcileOvertimeDay::dispatch(
                    (int) $attendance->tenant_id,
                    (int) $attendance->employee_id,
                    $attendance->work_date->toDateString(),
                );
            });
        }

        $calculation['payroll_review'] = $review?->toArray();
        $calculation['reconciliation_changed'] = $changes !== [] || $reviewChanged;

        return $calculation;
    }

    /**
     * @return array{calculation: array<string, mixed>, changes: array<string, mixed>}
     */
    private function prepareInternal(
        Attendance $attendance,
        bool $guardClosedPeriod,
        ?object $shiftOverride,
        bool $hasShiftOverride,
    ): array {
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
        $existingMeta = $this->decodeMeta($attendance->meta);
        $calculationChanged = $this->canonicalize(collect($meta)->except('classified_at')->all())
            !== $this->canonicalize(collect($existingMeta)->except('classified_at')->all());
        if ($calculationChanged || empty($existingMeta['classified_at'])) {
            $meta['classified_at'] = now()->toIso8601String();
        } else {
            $meta['classified_at'] = $existingMeta['classified_at'];
        }

        $changes = [];
        if ($calculationChanged || empty($existingMeta['classified_at'])) {
            $changes['meta'] = $meta;
        }
        if (! in_array(strtoupper((string) $attendance->status), ['APPROVED', 'ĐÃ_DUYỆT'], true)) {
            if ((string) $attendance->status !== (string) $calculation['status']) {
                $changes['status'] = $calculation['status'];
            }
        }
        if (! $attendance->shift_type_id && $shift?->id) {
            $changes['shift_type_id'] = $shift->id;
        }

        return ['calculation' => $calculation, 'changes' => $changes];
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

    private function canonicalize(mixed $value): mixed
    {
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}

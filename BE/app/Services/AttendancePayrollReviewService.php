<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendancePayrollReview;
use App\Support\HrmConfig;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendancePayrollReviewService
{
    private const ALLOWED_PERCENTAGES = [0, 25, 50, 75, 100];

    public function sync(
        Attendance $attendance,
        array $calculation,
        ?int $actorId = null,
        bool $notify = true,
    ): ?AttendancePayrollReview {
        return $this->syncWithOutcome($attendance, $calculation, $actorId, $notify)['review'];
    }

    /**
     * @return array{review:?AttendancePayrollReview,changed:bool,notification:?string}
     */
    public function syncWithOutcome(
        Attendance $attendance,
        array $calculation,
        ?int $actorId = null,
        bool $notify = true,
    ): array {
        $review = AttendancePayrollReview::query()
            ->where('attendance_id', $attendance->id)
            ->first();

        return $this->syncKnownReviewWithOutcome(
            $attendance,
            $calculation,
            $review,
            $actorId,
            $notify,
        );
    }

    /**
     * Sync using a review already loaded by the caller. Batch recompute uses
     * this path so review lookups stay constant per chunk instead of per row.
     *
     * @return array{review:?AttendancePayrollReview,changed:bool,notification:?string}
     */
    public function syncKnownReviewWithOutcome(
        Attendance $attendance,
        array $calculation,
        ?AttendancePayrollReview $review,
        ?int $actorId = null,
        bool $notify = true,
    ): array {
        $lateMinutes = max(0, (int) ($calculation['late_minutes'] ?? 0));
        $earlyLeaveMinutes = max(0, (int) ($calculation['early_leave_minutes'] ?? 0));
        $lateThreshold = max(0, (int) HrmConfig::get('attendance.late_grace_minutes', 15));
        $earlyThreshold = max(0, (int) HrmConfig::get('attendance.early_leave_grace_minutes', 15));
        $hasViolation = ($lateMinutes >= $lateThreshold && $lateMinutes > 0)
            || ($earlyLeaveMinutes >= $earlyThreshold && $earlyLeaveMinutes > 0);
        $defaultPercent = $this->defaultPercent();

        if (! $hasViolation) {
            if (! $review) {
                return $this->outcome(null);
            }
            if (in_array($review->status, ['PENDING', 'STALE'], true)) {
                $review->delete();

                return $this->outcome(null, true);
            }

            if ($this->metricsChanged($review, $lateMinutes, $earlyLeaveMinutes)) {
                $review = $this->markStale(
                    $review,
                    $lateMinutes,
                    $earlyLeaveMinutes,
                    $defaultPercent,
                    $actorId,
                    $notify,
                );

                return $this->outcome($review, true, 'stale');
            }

            return $this->outcome($review);
        }

        if (! $review) {
            $review = AttendancePayrollReview::create([
                'tenant_id' => $attendance->tenant_id,
                'legal_entity_id' => $attendance->legal_entity_id,
                'attendance_id' => $attendance->id,
                'employee_id' => $attendance->employee_id,
                'work_date' => $attendance->work_date,
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'default_percent' => $defaultPercent,
                'status' => 'PENDING',
                'meta' => ['created_by' => $actorId, 'source' => 'attendance-reconciliation'],
            ]);
            if ($notify) {
                $this->notifyHr($review, 'Có vi phạm chấm công chờ duyệt');
            }

            return $this->outcome($review, true, 'created');
        }

        if ($this->metricsChanged($review, $lateMinutes, $earlyLeaveMinutes)) {
            if (in_array($review->status, ['APPROVED', 'WAIVED', 'APPLIED'], true)) {
                $review = $this->markStale($review, $lateMinutes, $earlyLeaveMinutes, $defaultPercent, $actorId, $notify);

                return $this->outcome($review, true, 'stale');
            }

            $review->update([
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'default_percent' => $defaultPercent,
                'status' => $review->status === 'STALE' ? 'STALE' : 'PENDING',
            ]);

            return $this->outcome($review, true);
        } elseif ($review->status === 'PENDING' && $review->default_percent !== $defaultPercent) {
            $review->update(['default_percent' => $defaultPercent]);

            return $this->outcome($review, true);
        }

        return $this->outcome($review);
    }

    public function notifyHrBatch(
        int $tenantId,
        int $legalEntityId,
        int $created,
        int $stale,
        string $start,
        string $end,
        ?string $operationId = null,
    ): void {
        if ($created + $stale === 0) {
            return;
        }

        Notifier::notifyMany(
            $this->hrRecipientIds($tenantId, $legalEntityId),
            'Đối soát chấm công có review cần xử lý',
            "Kỳ {$start} đến {$end}: {$created} review mới, {$stale} review cần duyệt lại.",
            'attendance_recompute',
            null,
            [
                'priority' => 'high',
                'operation_id' => $operationId,
                'legal_entity_id' => $legalEntityId,
                'created_reviews' => $created,
                'stale_reviews' => $stale,
                'start' => $start,
                'end' => $end,
            ],
        );
    }

    public function decide(AttendancePayrollReview $review, int $percent, ?string $note, int $actorId): AttendancePayrollReview
    {
        if (! in_array($review->status, AttendancePayrollReview::UNRESOLVED_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Review đã được xử lý hoặc áp dụng.']]);
        }
        if (! in_array($percent, self::ALLOWED_PERCENTAGES, true)) {
            throw ValidationException::withMessages(['percent' => ['Tỷ lệ chỉ được chọn 0, 25, 50, 75 hoặc 100%.']]);
        }

        $note = trim((string) $note);
        if (($percent === 0 || $percent !== (int) $review->default_percent) && $note === '') {
            throw ValidationException::withMessages([
                'note' => ['Bắt buộc ghi lý do khi miễn hoặc thay đổi mức mặc định.'],
            ]);
        }

        $meta = $review->meta ?? [];
        $audit = is_array($meta['decision_audit'] ?? null) ? $meta['decision_audit'] : [];
        $audit[] = [
            'percent' => $percent,
            'note' => $note !== '' ? $note : null,
            'decided_by' => $actorId,
            'decided_at' => now()->toIso8601String(),
        ];
        $meta['decision_audit'] = $audit;

        $review->update([
            'approved_percent' => $percent,
            'status' => $percent === 0 ? 'WAIVED' : 'APPROVED',
            'decision_note' => $note !== '' ? $note : null,
            'decided_by' => $actorId,
            'decided_at' => now(),
            'stale_at' => null,
            'applied_at' => null,
            'meta' => $meta,
        ]);

        return $review->fresh(['employee:id,employee_code,full_name,department_id', 'decidedBy:id,full_name']);
    }

    private function markStale(
        AttendancePayrollReview $review,
        int $lateMinutes,
        int $earlyLeaveMinutes,
        int $defaultPercent,
        ?int $actorId,
        bool $notify = true,
    ): AttendancePayrollReview {
        $meta = $review->meta ?? [];
        $history = is_array($meta['stale_history'] ?? null) ? $meta['stale_history'] : [];
        $history[] = [
            'previous_status' => $review->status,
            'previous_percent' => $review->approved_percent,
            'previous_late_minutes' => $review->late_minutes,
            'previous_early_leave_minutes' => $review->early_leave_minutes,
            'changed_by' => $actorId,
            'changed_at' => now()->toIso8601String(),
        ];
        $meta['stale_history'] = $history;

        $review->update([
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'default_percent' => $defaultPercent,
            'approved_percent' => null,
            'status' => 'STALE',
            'decision_note' => null,
            'decided_by' => null,
            'decided_at' => null,
            'stale_at' => now(),
            'applied_at' => null,
            'meta' => $meta,
        ]);
        if ($notify) {
            $this->notifyHr($review, 'Review khấu trừ chấm công cần duyệt lại');
        }

        return $review;
    }

    private function metricsChanged(AttendancePayrollReview $review, int $lateMinutes, int $earlyLeaveMinutes): bool
    {
        return (int) $review->late_minutes !== $lateMinutes
            || (int) $review->early_leave_minutes !== $earlyLeaveMinutes;
    }

    private function defaultPercent(): int
    {
        $percent = (int) HrmConfig::get('attendance.violation_default_percent', 0);

        return in_array($percent, self::ALLOWED_PERCENTAGES, true) ? $percent : 0;
    }

    private function notifyHr(AttendancePayrollReview $review, string $title): void
    {
        Notifier::notifyMany(
            $this->hrRecipientIds((int) $review->tenant_id, (int) $review->legal_entity_id),
            $title,
            'Ngày '.$review->work_date->format('d/m/Y').' có lượt đi trễ/về sớm cần HR quyết định.',
            'attendance_payroll_review',
            $review->id,
            ['priority' => 'high'],
        );
    }

    /** @return array<int, int> */
    private function hrRecipientIds(int $tenantId, int $legalEntityId): array
    {
        return DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->join('employees as recipient', function ($join): void {
                $join->on('recipient.id', '=', 'er.employee_id')
                    ->on('recipient.tenant_id', '=', 'er.tenant_id');
            })
            ->where('er.tenant_id', $tenantId)
            ->whereColumn('r.tenant_id', 'er.tenant_id')
            ->whereRaw('er.is_active = true')
            ->where(function ($query) use ($legalEntityId): void {
                $query->whereIn('r.role_code', ['ADMIN', 'TENANT_ADMIN'])
                    ->orWhere(function ($hr) use ($legalEntityId): void {
                        $hr->where('r.role_code', 'HR')
                            ->where('recipient.legal_entity_id', $legalEntityId);
                    });
            })
            ->pluck('er.employee_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{review:?AttendancePayrollReview,changed:bool,notification:?string}
     */
    private function outcome(
        ?AttendancePayrollReview $review,
        bool $changed = false,
        ?string $notification = null,
    ): array {
        return compact('review', 'changed', 'notification');
    }
}

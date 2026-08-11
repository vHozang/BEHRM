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

    public function sync(Attendance $attendance, array $calculation, ?int $actorId = null): ?AttendancePayrollReview
    {
        $lateMinutes = max(0, (int) ($calculation['late_minutes'] ?? 0));
        $earlyLeaveMinutes = max(0, (int) ($calculation['early_leave_minutes'] ?? 0));
        $lateThreshold = max(0, (int) HrmConfig::get('attendance.late_grace_minutes', 15));
        $earlyThreshold = max(0, (int) HrmConfig::get('attendance.early_leave_grace_minutes', 15));
        $hasViolation = ($lateMinutes >= $lateThreshold && $lateMinutes > 0)
            || ($earlyLeaveMinutes >= $earlyThreshold && $earlyLeaveMinutes > 0);
        $defaultPercent = $this->defaultPercent();

        $review = AttendancePayrollReview::query()
            ->where('attendance_id', $attendance->id)
            ->first();

        if (! $hasViolation) {
            if (! $review) {
                return null;
            }
            if ($review->status === 'PENDING') {
                $review->delete();

                return null;
            }

            if ($this->metricsChanged($review, $lateMinutes, $earlyLeaveMinutes)) {
                $review = $this->markStale($review, $lateMinutes, $earlyLeaveMinutes, $defaultPercent, $actorId);
            }

            return $review;
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
            $this->notifyHr($review, 'Có vi phạm chấm công chờ duyệt');

            return $review;
        }

        if ($this->metricsChanged($review, $lateMinutes, $earlyLeaveMinutes)) {
            if (in_array($review->status, ['APPROVED', 'WAIVED', 'APPLIED'], true)) {
                return $this->markStale($review, $lateMinutes, $earlyLeaveMinutes, $defaultPercent, $actorId);
            }

            $review->update([
                'late_minutes' => $lateMinutes,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'default_percent' => $defaultPercent,
                'status' => $review->status === 'STALE' ? 'STALE' : 'PENDING',
            ]);
        } elseif ($review->status === 'PENDING' && $review->default_percent !== $defaultPercent) {
            $review->update(['default_percent' => $defaultPercent]);
        }

        return $review->fresh();
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
        $this->notifyHr($review, 'Review khấu trừ chấm công cần duyệt lại');

        return $review->fresh();
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
        $ids = DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.tenant_id', $review->tenant_id)
            ->whereRaw('er.is_active = true')
            ->whereIn('r.role_code', ['HR', 'ADMIN', 'TENANT_ADMIN'])
            ->pluck('er.employee_id')
            ->unique()
            ->values()
            ->all();

        Notifier::notifyMany(
            $ids,
            $title,
            'Ngày '.$review->work_date->format('d/m/Y').' có lượt đi trễ/về sớm cần HR quyết định.',
            'attendance_payroll_review',
            $review->id,
            ['priority' => 'high'],
        );
    }
}

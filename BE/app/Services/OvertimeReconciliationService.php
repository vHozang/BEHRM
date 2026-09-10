<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\OvertimeRequest;
use Illuminate\Support\Facades\DB;

class OvertimeReconciliationService
{
    private const APPROVED_STATUSES = ['APPROVED', 'ĐÃ_DUYỆT'];

    public function __construct(
        private readonly AttendanceReconciliationService $attendanceReconciliation,
        private readonly CompOffService $compOff,
    ) {}

    public function reconcileDate(int $tenantId, int $employeeId, string $workDate): array
    {
        $requests = OvertimeRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->orderByRaw('CASE WHEN start_time IS NULL OR end_time IS NULL THEN 1 ELSE 0 END')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        if ($requests->isEmpty()) {
            return $this->emptyResult($workDate);
        }

        $attendance = Attendance::query()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $workDate)
            ->first();

        $calculation = $attendance
            ? $this->attendanceReconciliation->reconcile($attendance, null, false, false)
            : null;
        $actualIntervals = $this->deserializeIntervals($calculation['outside_shift_intervals'] ?? []);
        $hasCompletedPunch = (bool) ($calculation['has_completed_session'] ?? false);
        $actualOutsideMinutes = $this->duration($actualIntervals);

        $allocated = [];
        $rows = [];
        $warnings = [];
        $blockingIssues = [];
        $payableTotal = 0;
        $verifiedTotal = 0;

        foreach ($requests as $request) {
            $meta = is_array($request->meta) ? $request->meta : [];
            $legacy = ! $request->start_time || ! $request->end_time;
            $approvedMinutes = max(0, (int) round((float) $request->total_hours * 60));
            $matchedIntervals = [];
            $mode = 'INTERVAL_MATCHED';

            if ($legacy) {
                $available = $this->subtractIntervals($actualIntervals, $allocated);
                $matchedIntervals = $this->takeMinutes($available, $approvedMinutes);
                $mode = 'LEGACY_CAPPED';
            } else {
                $approvedInterval = $this->bestApprovedInterval(
                    (string) $request->start_time,
                    (string) $request->end_time,
                    $actualIntervals,
                );
                $approvedMinutes = $approvedInterval[1] - $approvedInterval[0];
                $matchedIntervals = $this->intersectionIntervals($actualIntervals, [$approvedInterval]);
                $matchedIntervals = $this->subtractIntervals($matchedIntervals, $allocated);
            }

            $matchedMinutes = $this->duration($matchedIntervals);
            $payableMinutes = $hasCompletedPunch ? intdiv($matchedMinutes, 15) * 15 : 0;
            $payableIntervals = $this->takeMinutes($matchedIntervals, $payableMinutes);
            $allocated = $this->mergeIntervals(array_merge($allocated, $matchedIntervals));
            $verifiedTotal += $payableMinutes;
            $convertedToCompOff = ! empty($meta['converted_to_comp_off']);
            if (! $convertedToCompOff) {
                $payableTotal += $payableMinutes;
            }

            $status = 'MATCHED';
            $requestWarnings = [];
            if (! $hasCompletedPunch) {
                $status = 'NO_ATTENDANCE';
                $message = 'Đơn/ticket tăng ca đã duyệt nhưng không có phiên chấm công hoàn chỉnh.';
                $blockingIssues[] = $this->issue($request, 'OT_NO_ATTENDANCE', $message);
                $requestWarnings[] = $message;
            } elseif ($payableMinutes === 0) {
                $status = 'NO_MATCH';
                $requestWarnings[] = 'Không có thời gian thực tế ngoài ca khớp với khung OT đã duyệt.';
            } elseif ($payableMinutes < $approvedMinutes) {
                $status = 'PARTIAL_MATCH';
                $requestWarnings[] = "Chỉ đối soát được {$payableMinutes}/{$approvedMinutes} phút OT đã duyệt.";
            }
            if ($legacy) {
                $requestWarnings[] = 'Dữ liệu cũ thiếu khung giờ, đã chặn trần theo total_hours (LEGACY_CAPPED).';
            }

            $meta['payable_overtime_minutes'] = $payableMinutes;
            $meta['overtime_reconciliation'] = [
                'mode' => $mode,
                'status' => $status,
                'approved_minutes' => $approvedMinutes,
                'actual_outside_minutes' => $actualOutsideMinutes,
                'matched_minutes' => $matchedMinutes,
                'payable_minutes' => $payableMinutes,
                'payable_intervals' => $this->serializeIntervals($payableIntervals),
                'warnings' => $requestWarnings,
            ];
            $previousMeta = is_array($request->meta) ? $request->meta : [];
            $previousReconciliation = (array) ($previousMeta['overtime_reconciliation'] ?? []);
            $nextReconciliation = $meta['overtime_reconciliation'];
            $previousComparable = $previousReconciliation;
            unset($previousComparable['calculated_at']);
            if ($previousComparable !== $nextReconciliation
                || (int) ($previousMeta['payable_overtime_minutes'] ?? 0) !== $payableMinutes) {
                $nextReconciliation['calculated_at'] = now()->toIso8601String();
                $meta['overtime_reconciliation'] = $nextReconciliation;
                $request->update(['meta' => $meta]);
            } elseif (isset($previousReconciliation['calculated_at'])) {
                $meta['overtime_reconciliation']['calculated_at'] = $previousReconciliation['calculated_at'];
            }
            $this->compOff->syncForRequest($request->fresh());

            array_push($warnings, ...$requestWarnings);
            $rows[] = [
                'overtime_request_id' => (int) $request->id,
                'kind' => $meta['kind'] ?? 'EMPLOYEE_REQUEST',
                'status' => $status,
                'approved_minutes' => $approvedMinutes,
                'actual_outside_minutes' => $actualOutsideMinutes,
                'matched_minutes' => $matchedMinutes,
                'payable_minutes' => $payableMinutes,
                'converted_to_comp_off' => $convertedToCompOff,
                'mode' => $mode,
                'warnings' => $requestWarnings,
            ];
        }

        return [
            'work_date' => $workDate,
            'attendance_id' => $attendance?->id,
            'has_completed_punch' => $hasCompletedPunch,
            'actual_outside_minutes' => $actualOutsideMinutes,
            'verified_overtime_minutes' => $verifiedTotal,
            'payable_overtime_minutes' => $payableTotal,
            'payable_overtime_hours' => round($payableTotal / 60, 2),
            'requests' => $rows,
            'warnings' => array_values(array_unique($warnings)),
            'blocking_issues' => $blockingIssues,
        ];
    }

    public function reconcileRange(
        int $tenantId,
        int $employeeId,
        string $startDate,
        string $endDate,
    ): array {
        $dates = DB::table('overtime_requests')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', self::APPROVED_STATUSES)
            ->whereBetween('work_date', [$startDate, $endDate])
            ->orderBy('work_date')
            ->pluck('work_date')
            ->map(fn ($date) => (string) $date)
            ->unique()
            ->values();

        $days = [];
        $minutes = 0;
        $verifiedMinutes = 0;
        $blocking = [];
        $warnings = [];
        foreach ($dates as $date) {
            $day = $this->reconcileDate($tenantId, $employeeId, $date);
            $days[$date] = $day;
            $minutes += $day['payable_overtime_minutes'];
            $verifiedMinutes += $day['verified_overtime_minutes'];
            array_push($blocking, ...$day['blocking_issues']);
            array_push($warnings, ...$day['warnings']);
        }

        return [
            'verified_overtime_minutes' => $verifiedMinutes,
            'payable_overtime_minutes' => $minutes,
            'payable_overtime_hours' => round($minutes / 60, 2),
            'days' => $days,
            'blocking_issues' => $blocking,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function emptyResult(string $workDate): array
    {
        return [
            'work_date' => $workDate,
            'attendance_id' => null,
            'has_completed_punch' => false,
            'actual_outside_minutes' => 0,
            'verified_overtime_minutes' => 0,
            'payable_overtime_minutes' => 0,
            'payable_overtime_hours' => 0.0,
            'requests' => [],
            'warnings' => [],
            'blocking_issues' => [],
        ];
    }

    private function issue(OvertimeRequest $request, string $code, string $message): array
    {
        return [
            'employee_id' => (int) $request->employee_id,
            'overtime_request_id' => (int) $request->id,
            'work_date' => $request->work_date->toDateString(),
            'issue_code' => $code,
            'message' => $message,
        ];
    }

    /** @return array{0:int,1:int} */
    private function bestApprovedInterval(string $startTime, string $endTime, array $actualIntervals): array
    {
        $start = $this->timeToMinute($startTime);
        $end = $this->timeToMinute($endTime);
        if ($end <= $start) {
            $end += 1440;
        }

        $candidates = [
            [$start - 1440, $end - 1440],
            [$start, $end],
            [$start + 1440, $end + 1440],
            [$start + 2880, $end + 2880],
        ];
        usort($candidates, function (array $left, array $right) use ($actualIntervals): int {
            $rightMatch = $this->duration($this->intersectionIntervals($actualIntervals, [$right]));
            $leftMatch = $this->duration($this->intersectionIntervals($actualIntervals, [$left]));
            if ($rightMatch !== $leftMatch) {
                return $rightMatch <=> $leftMatch;
            }

            return abs($left[0]) <=> abs($right[0]);
        });

        return $candidates[0];
    }

    private function deserializeIntervals(array $intervals): array
    {
        $rows = [];
        foreach ($intervals as $interval) {
            if (! is_array($interval)) {
                continue;
            }
            $start = $interval['start_minute'] ?? null;
            $end = $interval['end_minute'] ?? null;
            if (is_numeric($start) && is_numeric($end) && (int) $end > (int) $start) {
                $rows[] = [(int) $start, (int) $end];
            }
        }

        return $this->mergeIntervals($rows);
    }

    private function serializeIntervals(array $intervals): array
    {
        return array_map(fn (array $row) => [
            'start_minute' => $row[0],
            'end_minute' => $row[1],
        ], $intervals);
    }

    private function intersectionIntervals(array $left, array $right): array
    {
        $result = [];
        foreach ($left as [$leftStart, $leftEnd]) {
            foreach ($right as [$rightStart, $rightEnd]) {
                $start = max($leftStart, $rightStart);
                $end = min($leftEnd, $rightEnd);
                if ($end > $start) {
                    $result[] = [$start, $end];
                }
            }
        }

        return $this->mergeIntervals($result);
    }

    private function subtractIntervals(array $source, array $subtract): array
    {
        $result = $source;
        foreach ($this->mergeIntervals($subtract) as [$cutStart, $cutEnd]) {
            $next = [];
            foreach ($result as [$start, $end]) {
                if ($cutEnd <= $start || $cutStart >= $end) {
                    $next[] = [$start, $end];

                    continue;
                }
                if ($cutStart > $start) {
                    $next[] = [$start, min($cutStart, $end)];
                }
                if ($cutEnd < $end) {
                    $next[] = [max($cutEnd, $start), $end];
                }
            }
            $result = $next;
        }

        return $this->mergeIntervals($result);
    }

    private function takeMinutes(array $intervals, int $minutes): array
    {
        if ($minutes <= 0) {
            return [];
        }
        $result = [];
        $remaining = $minutes;
        foreach ($intervals as [$start, $end]) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $end - $start);
            if ($take > 0) {
                $result[] = [$start, $start + $take];
                $remaining -= $take;
            }
        }

        return $result;
    }

    private function mergeIntervals(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, fn (array $row) => $row[1] > $row[0]));
        usort($intervals, fn (array $left, array $right) => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    private function duration(array $intervals): int
    {
        return array_sum(array_map(fn (array $row) => max(0, $row[1] - $row[0]), $intervals));
    }

    private function timeToMinute(string $time): int
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', substr($time, 0, 5))), 2, 0);

        return $hour * 60 + $minute;
    }
}

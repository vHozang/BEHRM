<?php

namespace App\Services;

use App\Support\HrmConfig;
use Carbon\CarbonImmutable;

class AttendanceTimeCalculator
{
    /**
     * Calculate attendance metrics against the assigned shift.
     *
     * All internal intervals use minutes relative to midnight of work_date. An
     * overnight shift can therefore end above 1440 (for example CA3 ends at
     * minute 1800). This keeps split and overnight shifts on one timeline.
     */
    public function calculate(
        object|array|null $shift,
        string $workDate,
        ?string $checkIn,
        ?string $checkOut,
        ?string $checkIn2 = null,
        ?string $checkOut2 = null,
    ): array {
        [$shiftIntervals, $fallbackBreakMinutes] = $this->shiftIntervals($shift);
        $shiftStart = $shiftIntervals !== [] ? min(array_column($shiftIntervals, 0)) : null;
        $shiftEnd = $shiftIntervals !== [] ? max(array_column($shiftIntervals, 1)) : null;

        [$presenceIntervals, $firstCheckIn, $lastCheckOut] = $this->presenceIntervals(
            $shiftStart,
            $shiftEnd,
            $checkIn,
            $checkOut,
            $checkIn2,
            $checkOut2,
        );

        $rawPresenceMinutes = $this->duration($presenceIntervals);
        $regularWorkedMinutes = $this->intersectionDuration($presenceIntervals, $shiftIntervals);
        if ($fallbackBreakMinutes > 0 && $regularWorkedMinutes > 0) {
            $regularWorkedMinutes = max(0, $regularWorkedMinutes - min($fallbackBreakMinutes, $regularWorkedMinutes));
        }

        $earlyArrivalMinutes = $firstCheckIn !== null && $shiftStart !== null
            ? max(0, $shiftStart - $firstCheckIn)
            : 0;
        $lateMinutes = $firstCheckIn !== null && $shiftStart !== null
            ? max(0, $firstCheckIn - $shiftStart)
            : 0;
        $earlyLeaveMinutes = $lastCheckOut !== null && $shiftEnd !== null
            ? max(0, $shiftEnd - $lastCheckOut)
            : 0;
        $afterShiftMinutes = $lastCheckOut !== null && $shiftEnd !== null
            ? max(0, $lastCheckOut - $shiftEnd)
            : 0;

        $lateThreshold = max(0, (int) HrmConfig::get('attendance.late_grace_minutes', 15));
        $earlyLeaveThreshold = max(0, (int) HrmConfig::get('attendance.early_leave_grace_minutes', 15));

        $status = 'ON_TIME';
        if ($firstCheckIn === null) {
            $status = 'ABSENT';
        } elseif ($lateMinutes >= $lateThreshold && $lateMinutes > 0) {
            $status = 'LATE';
        } elseif ($earlyLeaveMinutes >= $earlyLeaveThreshold && $earlyLeaveMinutes > 0) {
            $status = 'EARLY_LEAVE';
        }

        $outsideShiftIntervals = $this->subtractIntervals($presenceIntervals, $shiftIntervals);

        return [
            'status' => $status,
            'shift_start' => $shiftStart === null ? null : $this->dateTime($workDate, $shiftStart),
            'shift_end' => $shiftEnd === null ? null : $this->dateTime($workDate, $shiftEnd),
            'shift_intervals' => $this->serializeIntervals($workDate, $shiftIntervals),
            'presence_intervals' => $this->serializeIntervals($workDate, $presenceIntervals),
            'outside_shift_intervals' => $this->serializeIntervals($workDate, $outsideShiftIntervals),
            'scheduled_minutes' => max(0, $this->duration($shiftIntervals) - $fallbackBreakMinutes),
            'raw_presence_minutes' => $rawPresenceMinutes,
            'regular_worked_minutes' => $regularWorkedMinutes,
            'worked_hours' => round($regularWorkedMinutes / 60, 2),
            'early_arrival_minutes' => $earlyArrivalMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'after_shift_minutes' => $afterShiftMinutes,
            'has_completed_session' => $presenceIntervals !== [],
        ];
    }

    /** @return array{0: array<int, array{0:int,1:int}>, 1:int} */
    private function shiftIntervals(object|array|null $shift): array
    {
        if ($shift === null) {
            return [[], 0];
        }

        $meta = $this->decodeMeta($this->value($shift, 'meta'));
        $segments = $meta['segments'] ?? null;
        $intervals = [];

        if (is_array($segments) && $segments !== []) {
            $previousEnd = null;
            foreach ($segments as $segment) {
                if (! is_array($segment)) {
                    continue;
                }
                $startValue = $segment['start'] ?? $segment['start_time'] ?? null;
                $endValue = $segment['end'] ?? $segment['end_time'] ?? null;
                if (! $startValue || ! $endValue) {
                    continue;
                }

                $start = $this->timeToMinute((string) $startValue);
                if ($previousEnd !== null) {
                    while ($start < $previousEnd) {
                        $start += 1440;
                    }
                }
                $end = $this->timeToMinute((string) $endValue);
                while ($end <= $start) {
                    $end += 1440;
                }
                $intervals[] = [$start, $end];
                $previousEnd = $end;
            }
        }

        if ($intervals === []) {
            $startValue = $this->value($shift, 'start_time');
            $endValue = $this->value($shift, 'end_time');
            if (! $startValue || ! $endValue) {
                return [[], 0];
            }
            $start = $this->timeToMinute((string) $startValue);
            $end = $this->timeToMinute((string) $endValue);
            if ($end <= $start) {
                $end += 1440;
            }
            $intervals = [[$start, $end]];
        }

        $breakStartValue = $this->value($shift, 'break_start') ?? ($meta['break_start'] ?? null);
        $breakEndValue = $this->value($shift, 'break_end') ?? ($meta['break_end'] ?? null);
        if ($breakStartValue && $breakEndValue) {
            $firstStart = min(array_column($intervals, 0));
            $lastEnd = max(array_column($intervals, 1));
            $breakStart = $this->nearestMinute((string) $breakStartValue, $firstStart, $firstStart);
            $breakEnd = $this->nearestMinute((string) $breakEndValue, $breakStart, $breakStart);
            if ($breakEnd <= $breakStart) {
                $breakEnd += 1440;
            }
            if ($breakStart < $lastEnd && $breakEnd > $firstStart) {
                $intervals = $this->subtractIntervals($intervals, [[$breakStart, $breakEnd]]);

                return [$this->mergeIntervals($intervals), 0];
            }
        }

        $fallbackBreak = max(0, (int) ($meta['break_minutes'] ?? 0));

        return [$this->mergeIntervals($intervals), $fallbackBreak];
    }

    /** @return array{0:array<int,array{0:int,1:int}>,1:?int,2:?int} */
    private function presenceIntervals(
        ?int $shiftStart,
        ?int $shiftEnd,
        ?string $checkIn,
        ?string $checkOut,
        ?string $checkIn2,
        ?string $checkOut2,
    ): array {
        $anchorStart = $shiftStart ?? 8 * 60;
        $anchorEnd = $shiftEnd ?? $anchorStart + 8 * 60;
        $first = $checkIn ? $this->nearestMinute($checkIn, $anchorStart) : null;
        $intervals = [];
        $lastOut = null;

        if ($first !== null && $checkOut) {
            $out = $this->nearestMinute($checkOut, $anchorEnd, $first);
            if ($out > $first) {
                $intervals[] = [$first, $out];
                $lastOut = $out;
            }
        }

        $secondIn = null;
        if ($checkIn2) {
            $minimum = $lastOut ?? $first;
            $target = $minimum !== null ? max($minimum, $anchorStart) : $anchorStart;
            $secondIn = $this->nearestMinute($checkIn2, $target, $minimum);
        }
        if ($secondIn !== null && $checkOut2) {
            $out2 = $this->nearestMinute($checkOut2, $anchorEnd, $secondIn);
            if ($out2 > $secondIn) {
                $intervals[] = [$secondIn, $out2];
                $lastOut = $lastOut === null ? $out2 : max($lastOut, $out2);
            }
        }

        return [$this->mergeIntervals($intervals), $first, $lastOut];
    }

    private function nearestMinute(string $time, int $target, ?int $minimum = null): int
    {
        $minute = $this->timeToMinute($time);
        $candidates = [$minute - 1440, $minute, $minute + 1440, $minute + 2880];
        if ($minimum !== null) {
            $candidates = array_values(array_filter($candidates, fn (int $candidate) => $candidate >= $minimum));
        }
        if ($candidates === []) {
            return $minute;
        }

        usort($candidates, function (int $a, int $b) use ($target): int {
            $distance = abs($a - $target) <=> abs($b - $target);

            return $distance !== 0 ? $distance : ($a <=> $b);
        });

        return $candidates[0];
    }

    /** @param array<int,array{0:int,1:int}> $left @param array<int,array{0:int,1:int}> $right */
    private function intersectionDuration(array $left, array $right): int
    {
        $minutes = 0;
        foreach ($left as [$leftStart, $leftEnd]) {
            foreach ($right as [$rightStart, $rightEnd]) {
                $minutes += max(0, min($leftEnd, $rightEnd) - max($leftStart, $rightStart));
            }
        }

        return $minutes;
    }

    /**
     * @param array<int,array{0:int,1:int}> $source
     * @param array<int,array{0:int,1:int}> $subtract
     * @return array<int,array{0:int,1:int}>
     */
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

    /** @param array<int,array{0:int,1:int}> $intervals @return array<int,array{0:int,1:int}> */
    private function mergeIntervals(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, fn (array $row) => $row[1] > $row[0]));
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
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

    /** @param array<int,array{0:int,1:int}> $intervals */
    private function duration(array $intervals): int
    {
        return array_sum(array_map(fn (array $row) => max(0, $row[1] - $row[0]), $intervals));
    }

    /** @param array<int,array{0:int,1:int}> $intervals */
    private function serializeIntervals(string $workDate, array $intervals): array
    {
        return array_map(fn (array $row) => [
            'start_minute' => $row[0],
            'end_minute' => $row[1],
            'start' => $this->dateTime($workDate, $row[0]),
            'end' => $this->dateTime($workDate, $row[1]),
        ], $intervals);
    }

    private function dateTime(string $workDate, int $minute): string
    {
        return CarbonImmutable::parse($workDate)->startOfDay()->addMinutes($minute)->format('Y-m-d H:i:s');
    }

    private function timeToMinute(string $time): int
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', substr($time, 0, 5))), 2, 0);

        return $hour * 60 + $minute;
    }

    private function value(object|array $source, string $key): mixed
    {
        return is_array($source) ? ($source[$key] ?? null) : ($source->{$key} ?? null);
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

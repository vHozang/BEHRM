<?php

namespace App\Support;

use App\Services\AttendanceTimeCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chính sách công / tăng ca theo luật lao động Việt Nam (Đ.98, 106, 107).
 * Mọi tham số đọc qua HrmConfig (tenant override → config/hrm.php).
 *
 * - Phân loại ngày: ngày thường / nghỉ hằng tuần / lễ-tết.
 * - Giờ làm ban đêm (22:00–06:00) để tính phụ cấp đêm.
 * - Hệ số tiền OT: 150% / 200% / 300%; ban đêm +30%; OT ban đêm +20%.
 * - Giới hạn OT: ≤ 4h/ngày, ≤ 40h/tháng, ≤ 200 (300) h/năm.
 */
class TimePolicy
{
    // ── Phân loại ngày ───────────────────────────────────
    public static function dayType($date): string
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        if (self::isHoliday($d)) {
            return 'holiday';
        }
        $rest = (int) HrmConfig::get('attendance.weekly_rest_weekday', 0); // 0=CN
        return $d->dayOfWeek === $rest ? 'weekend' : 'weekday';
    }

    public static function isHoliday(Carbon $d): bool
    {
        if (! Schema::hasTable('holidays')) {
            return false;
        }
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        return DB::table('holidays')
            ->whereDate('holiday_date', $d->toDateString())
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->exists();
    }

    public static function dayTypeLabel(string $type): string
    {
        return ['weekday' => 'Ngày thường', 'weekend' => 'Ngày nghỉ hằng tuần', 'holiday' => 'Ngày lễ/Tết'][$type] ?? $type;
    }

    public static function multiplier(string $dayType): float
    {
        return (float) match ($dayType) {
            'holiday' => HrmConfig::get('overtime.multiplier_holiday', 3.0),
            'weekend' => HrmConfig::get('overtime.multiplier_weekend', 2.0),
            default => HrmConfig::get('overtime.multiplier_weekday', 1.5),
        };
    }

    /**
     * Hệ số OT theo NGÀY cụ thể — cho phép cấu hình riêng Thứ Bảy / Chủ nhật
     * (nhiều DN trả T7 200%, CN 300% trên mức luật). Ưu tiên: lễ → holiday;
     * T7 → multiplier_saturday (nếu >0); CN → multiplier_sunday (nếu >0); còn lại
     * theo loại ngày (nghỉ tuần=weekend 200%, ngày thường=150%).
     *
     * Mức TỐI THIỂU theo Đ.98: ngày thường 150%, nghỉ hằng tuần 200%, lễ 300%.
     */
    public static function multiplierForDate($date): float
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        if (self::isHoliday($d)) {
            return (float) HrmConfig::get('overtime.multiplier_holiday', 3.0);
        }
        if ($d->dayOfWeek === 6) {
            $sat = (float) HrmConfig::get('overtime.multiplier_saturday', 0);
            if ($sat > 0) {
                return $sat;
            }
        }
        if ($d->dayOfWeek === 0) {
            $sun = (float) HrmConfig::get('overtime.multiplier_sunday', 0);
            if ($sun > 0) {
                return $sun;
            }
        }

        return self::multiplier(self::dayType($d));
    }

    /** Nhãn mô tả ngày cho đơn OT. */
    public static function otDayLabel($date): string
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        if (self::isHoliday($d)) {
            return 'Ngày lễ/Tết';
        }
        if ($d->dayOfWeek === 0) {
            return 'Chủ nhật';
        }
        if ($d->dayOfWeek === 6) {
            return 'Thứ Bảy';
        }

        return self::dayTypeLabel(self::dayType($d));
    }

    // ── Giờ ban đêm trong khoảng [start,end) (xử lý qua nửa đêm) ──
    public static function nightHours(?string $start, ?string $end): float
    {
        if (! $start || ! $end) {
            return 0.0;
        }
        $s = self::toMin($start);
        $e = self::toMin($end);
        if ($e <= $s) {
            $e += 1440; // qua nửa đêm
        }
        $ns = self::toMin((string) HrmConfig::get('overtime.night_start', '22:00')); // 1320
        $ne = self::toMin((string) HrmConfig::get('overtime.night_end', '06:00'));   // 360
        $mins = 0;
        for ($m = $s; $m < $e; $m++) {
            $h = $m % 1440;
            // đêm nếu h >= night_start HOẶC h < night_end
            if ($h >= $ns || $h < $ne) {
                $mins++;
            }
        }
        return round($mins / 60, 2);
    }

    private static function toMin(string $t): int
    {
        $p = explode(':', $t);
        return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
    }

    // ── Công chuẩn & phân loại chấm công (Đ.105, 111) ─────────

    /** Ngày nghỉ hằng tuần? (0=CN .. 6=T7 theo attendance.weekly_rest_weekday). */
    public static function isRestDay($date): bool
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        $rest = (int) HrmConfig::get('attendance.weekly_rest_weekday', 0);

        return $d->dayOfWeek === $rest;
    }

    /**
     * Tập hợp ngày lễ (Y-m-d) của tenant trong [start,end] — query 1 lần.
     *
     * @return array<int,string>
     */
    public static function holidaySet(string $start, string $end): array
    {
        if (! Schema::hasTable('holidays')) {
            return [];
        }
        $tenantId = TenantContext::hasTenant() ? TenantContext::id() : null;

        $rows = DB::table('holidays')
            ->whereBetween('holiday_date', [$start, $end])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->pluck('holiday_date')
            ->all();

        return array_values(array_unique(array_map(
            fn ($d) => Carbon::parse($d)->toDateString(),
            $rows
        )));
    }

    /**
     * Công chuẩn tháng/khoảng theo luật VN: số ngày trong [start,end] KHÔNG phải
     * ngày nghỉ hằng tuần (mặc định CN) và KHÔNG phải ngày lễ. KHÔNG trừ thứ Bảy
     * (DN làm 6 ngày/tuần — Đ.105). Đây là mẫu số "công chuẩn" cho payroll.
     */
    public static function standardWorkingDays(string $start, string $end): int
    {
        $s = Carbon::parse($start)->startOfDay();
        $e = Carbon::parse($end)->startOfDay();
        if ($e->lt($s)) {
            return 0;
        }
        $holidays = self::holidaySet($s->toDateString(), $e->toDateString());

        $count = 0;
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            if (self::isRestDay($d)) {
                continue;
            }
            if (in_array($d->toDateString(), $holidays, true)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /** Công chuẩn của một tháng "YYYY-MM". */
    public static function standardWorkingDaysOfMonth(string $month): int
    {
        $start = Carbon::parse($month . '-01')->startOfMonth();

        return self::standardWorkingDays($start->toDateString(), $start->copy()->endOfMonth()->toDateString());
    }

    /**
     * Phân loại một bản ghi chấm công theo ca + dung sai đi trễ.
     *
     * @param  object|array|null  $shift  bản ghi shift_types (start_time/end_time/meta)
     * @return array{status:string, late_minutes:int, early_leave_minutes:int, worked_hours:float}
     *         status ∈ ON_TIME | LATE | EARLY_LEAVE | ABSENT
     */
    public static function classifyAttendance($shift, ?string $checkIn, ?string $checkOut): array
    {
        return (new AttendanceTimeCalculator())->calculate(
            $shift,
            '2000-01-01',
            $checkIn,
            $checkOut,
        );
    }

    /**
     * Phân loại + ước tính hệ số trả lương cho một đơn OT.
     * Trả về: total_hours, day_type, multiplier, night_hours, day_hours,
     *         pay_factor (hệ số trung bình so với đơn giá giờ thường), label.
     */
    public static function classifyOvertime($date, ?string $start, ?string $end, ?float $totalHours = null): array
    {
        $type = self::dayType($date);
        $mult = self::multiplierForDate($date); // hệ số theo ngày (cấu hình T7/CN riêng)

        $total = $totalHours;
        if ($total === null && $start && $end) {
            $s = self::toMin($start);
            $e = self::toMin($end);
            if ($e <= $s) {
                $e += 1440;
            }
            $total = round(($e - $s) / 60, 2);
        }
        $total = (float) ($total ?? 0);

        $night = self::nightHours($start, $end);
        $night = min($night, $total);
        $day = max(0, round($total - $night, 2));

        $nightExtra = (float) HrmConfig::get('overtime.night_ot_extra', 0.2);
        $nightPrem = (float) HrmConfig::get('overtime.night_premium', 0.3);
        // OT ban đêm: mult*(1+20%) + 30% (Đ.98.3).
        $nightFactor = $mult * (1 + $nightExtra) + $nightPrem;

        $payFactor = $total > 0 ? round(($day * $mult + $night * $nightFactor) / $total, 3) : $mult;

        $label = self::otDayLabel($date) . ' · ' . (int) round($mult * 100) . '%';
        if ($night > 0) {
            $label .= ' (+đêm)';
        }

        return [
            'total_hours' => $total,
            'day_type' => $type,
            'multiplier' => $mult,
            'night_hours' => $night,
            'day_hours' => $day,
            'night_factor' => round($nightFactor, 3),
            'pay_factor' => $payFactor,
            'label' => $label,
        ];
    }

    /**
     * Kiểm tra giới hạn OT (ngày/tháng/năm) cho nhân viên. Cộng dồn các đơn
     * APPROVED (+ PENDING) trừ đơn hiện tại ($excludeId).
     */
    public static function overtimeCaps(int $employeeId, $date, float $hours, ?int $excludeId = null, bool $approvedOnly = false): array
    {
        $d = $date instanceof Carbon ? $date : Carbon::parse($date);
        $statuses = $approvedOnly
            ? ['APPROVED', 'ĐÃ_DUYỆT']
            : ['APPROVED', 'PENDING', 'CHỜ_DUYỆT', 'ĐÃ_DUYỆT'];

        $base = DB::table('overtime_requests')
            ->where('employee_id', $employeeId)
            ->whereIn('status', $statuses)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));

        $dailyUsed = (float) (clone $base)->whereDate('work_date', $d->toDateString())->sum('total_hours');
        $monthlyUsed = (float) (clone $base)->whereYear('work_date', $d->year)->whereMonth('work_date', $d->month)->sum('total_hours');
        $yearlyUsed = (float) (clone $base)->whereYear('work_date', $d->year)->sum('total_hours');

        $dMax = (float) HrmConfig::get('overtime.daily_max_hours', 4);
        $mMax = (float) HrmConfig::get('overtime.monthly_max_hours', 40);
        $yMax = (float) HrmConfig::get('overtime.yearly_max_hours', 200);

        $violations = [];
        if ($dailyUsed + $hours > $dMax) {
            $violations[] = "Vượt giới hạn OT trong ngày ({$dMax}h): đã có {$dailyUsed}h + {$hours}h.";
        }
        if ($monthlyUsed + $hours > $mMax) {
            $violations[] = "Vượt giới hạn OT trong tháng ({$mMax}h): đã có {$monthlyUsed}h + {$hours}h.";
        }
        if ($yearlyUsed + $hours > $yMax) {
            $violations[] = "Vượt giới hạn OT trong năm ({$yMax}h): đã có {$yearlyUsed}h + {$hours}h.";
        }

        return [
            'daily_used' => $dailyUsed, 'daily_max' => $dMax,
            'monthly_used' => $monthlyUsed, 'monthly_max' => $mMax,
            'yearly_used' => $yearlyUsed, 'yearly_max' => $yMax,
            'violations' => $violations,
        ];
    }
}

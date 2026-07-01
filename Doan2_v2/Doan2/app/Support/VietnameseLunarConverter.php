<?php

namespace App\Support;

/**
 * Vietnamese lunar ⇄ solar calendar conversion.
 *
 * Faithful port of Hồ Ngọc Đức's algorithm (the de-facto reference used by the
 * Vietnamese government's published holiday calendar). Used to compute the
 * solar dates of lunar-anchored public holidays — Tết Nguyên đán (mùng 1 tháng
 * Giêng) and Giỗ Tổ Hùng Vương (10 tháng 3 âm lịch) — for any year, so the
 * holiday seeder never relies on a hand-maintained lookup table that goes stale.
 *
 * Timezone is fixed at +7 (Asia/Ho_Chi_Minh) for all conversions.
 */
class VietnameseLunarConverter
{
    public const TIMEZONE = 7.0;

    /**
     * Convert a lunar date to its solar (Gregorian) equivalent.
     *
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    public static function lunarToSolar(int $lunarDay, int $lunarMonth, int $lunarYear, bool $lunarLeap = false, float $timeZone = self::TIMEZONE): array
    {
        if ($lunarMonth < 11) {
            $a11 = self::lunarMonth11($lunarYear - 1, $timeZone);
            $b11 = self::lunarMonth11($lunarYear, $timeZone);
        } else {
            $a11 = self::lunarMonth11($lunarYear, $timeZone);
            $b11 = self::lunarMonth11($lunarYear + 1, $timeZone);
        }

        $k = (int) floor(0.5 + ($a11 - 2415021.076998695) / 29.530588853);
        $off = $lunarMonth - 11;
        if ($off < 0) {
            $off += 12;
        }

        if ($b11 - $a11 > 365) {
            $leapOff = self::leapMonthOffset($a11, $timeZone);
            $leapMonth = $leapOff - 2;
            if ($leapMonth < 0) {
                $leapMonth += 12;
            }
            if ($lunarLeap && $lunarMonth !== $leapMonth) {
                return [0, 0, 0];
            } elseif ($lunarLeap || $off >= $leapOff) {
                $off += 1;
            }
        }

        $monthStart = self::newMoonDay($k + $off, $timeZone);

        return self::jdToDate($monthStart + $lunarDay - 1);
    }

    /** Julian day number from a Gregorian date. */
    private static function jdFromDate(int $dd, int $mm, int $yy): int
    {
        $a = intdiv(14 - $mm, 12);
        $y = $yy + 4800 - $a;
        $m = $mm + 12 * $a - 3;
        $jd = $dd + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
        if ($jd < 2299161) {
            $jd = $dd + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - 32083;
        }

        return $jd;
    }

    /**
     * Gregorian date from a Julian day number.
     *
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    private static function jdToDate(int $jd): array
    {
        if ($jd > 2299160) {
            $a = $jd + 32044;
            $b = intdiv(4 * $a + 3, 146097);
            $c = $a - intdiv($b * 146097, 4);
        } else {
            $b = 0;
            $c = $jd + 32082;
        }
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);
        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = $b * 100 + $d - 4800 + intdiv($m, 10);

        return [$year, $month, $day];
    }

    /** Julian day of the k-th new moon since 1900-01-01. */
    private static function newMoon(int $k): float
    {
        $T = $k / 1236.85;
        $T2 = $T * $T;
        $T3 = $T2 * $T;
        $dr = M_PI / 180;
        $Jd1 = 2415020.75933 + 29.53058868 * $k + 0.0001178 * $T2 - 0.000000155 * $T3;
        $Jd1 += 0.00033 * sin((166.56 + 132.87 * $T - 0.009173 * $T2) * $dr);
        $M = 359.2242 + 29.10535608 * $k - 0.0000333 * $T2 - 0.00000347 * $T3;
        $Mpr = 306.0253 + 385.81691806 * $k + 0.0107306 * $T2 + 0.00001236 * $T3;
        $F = 21.2964 + 390.67050646 * $k - 0.0016528 * $T2 - 0.00000239 * $T3;
        $C1 = (0.1734 - 0.000393 * $T) * sin($M * $dr) + 0.0021 * sin(2 * $dr * $M);
        $C1 = $C1 - 0.4068 * sin($Mpr * $dr) + 0.0161 * sin($dr * 2 * $Mpr);
        $C1 = $C1 - 0.0004 * sin($dr * 3 * $Mpr);
        $C1 = $C1 + 0.0104 * sin($dr * 2 * $F) - 0.0051 * sin($dr * ($M + $Mpr));
        $C1 = $C1 - 0.0074 * sin($dr * ($M - $Mpr)) + 0.0004 * sin($dr * (2 * $F + $M));
        $C1 = $C1 - 0.0004 * sin($dr * (2 * $F - $M)) - 0.0006 * sin($dr * (2 * $F + $Mpr));
        $C1 = $C1 + 0.0010 * sin($dr * (2 * $F - $Mpr)) + 0.0005 * sin($dr * (2 * $Mpr + $M));
        if ($T < -11) {
            $deltat = 0.001 + 0.000839 * $T + 0.0002261 * $T2 - 0.00000845 * $T3 - 0.000000081 * $T * $T3;
        } else {
            $deltat = -0.000278 + 0.000265 * $T + 0.000262 * $T2;
        }

        return $Jd1 + $C1 - $deltat;
    }

    /** Apparent solar longitude (radians) at the given Julian day. */
    private static function sunLongitude(float $jdn): float
    {
        $T = ($jdn - 2451545.0) / 36525;
        $T2 = $T * $T;
        $dr = M_PI / 180;
        $M = 357.52910 + 35999.05030 * $T - 0.0001559 * $T2 - 0.00000048 * $T * $T2;
        $L0 = 280.46645 + 36000.76983 * $T + 0.0003032 * $T2;
        $DL = (1.914600 - 0.004817 * $T - 0.000014 * $T2) * sin($dr * $M);
        $DL += (0.019993 - 0.000101 * $T) * sin($dr * 2 * $M) + 0.000290 * sin($dr * 3 * $M);
        $L = ($L0 + $DL) * $dr;

        return $L - M_PI * 2 * floor($L / (M_PI * 2));
    }

    /** Integer solar-term index (0..11) at a local day number. */
    private static function sunLongitudeIndex(int $dayNumber, float $timeZone): int
    {
        return (int) floor(self::sunLongitude($dayNumber - 0.5 - $timeZone / 24.0) / M_PI * 6);
    }

    /** Local-calendar day of the k-th new moon. */
    private static function newMoonDay(int $k, float $timeZone): int
    {
        return (int) floor(self::newMoon($k) + 0.5 + $timeZone / 24.0);
    }

    /** Day that contains lunar month 11 (the month before Tết) of the given year. */
    private static function lunarMonth11(int $yy, float $timeZone): int
    {
        $off = self::jdFromDate(31, 12, $yy) - 2415021;
        $k = (int) floor($off / 29.530588853);
        $nm = self::newMoonDay($k, $timeZone);
        if (self::sunLongitudeIndex($nm, $timeZone) >= 9) {
            $nm = self::newMoonDay($k - 1, $timeZone);
        }

        return $nm;
    }

    private static function leapMonthOffset(int $a11, float $timeZone): int
    {
        $k = (int) floor(($a11 - 2415021.076998695) / 29.530588853 + 0.5);
        $i = 1;
        $arc = self::sunLongitudeIndex(self::newMoonDay($k + $i, $timeZone), $timeZone);
        do {
            $last = $arc;
            $i++;
            $arc = self::sunLongitudeIndex(self::newMoonDay($k + $i, $timeZone), $timeZone);
        } while ($arc !== $last && $i < 14);

        return $i - 1;
    }
}

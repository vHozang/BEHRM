<?php

namespace App\Support;

/**
 * Xác minh tính hợp lệ của lượt chấm công (chống gian lận) theo IP văn phòng,
 * geofence (toạ độ + bán kính) và cờ WFH của ca. Tham số đọc qua HrmConfig
 * (tenant override → config/hrm.php). KHÔNG chặn cứng theo mặc định — chế độ
 * 'flag' chỉ đánh dấu "cần xem xét" cho admin duyệt.
 */
class AttendanceVerification
{
    /**
     * Đánh giá một lượt chấm công.
     *
     * @param  object|array|null  $shift  bản ghi shift_types (đọc meta.allow_wfh)
     * @return array{
     *   mode:string, ip:?string, ip_ok:?bool, geo_ok:?bool, distance_m:?int,
     *   lat:?float, lng:?float, accuracy:?float, source:string, device:?string,
     *   wfh:bool, within_policy:bool, flags:array<int,string>
     * }
     */
    public static function evaluate(?string $ip, $lat, $lng, $shift = null, ?string $userAgent = null, ?string $source = null, ?string $workMode = null): array
    {
        $mode = strtolower((string) HrmConfig::get('attendance.enforce_mode', 'flag'));
        $workMode = in_array($workMode, ['wfh', 'on_site', 'office'], true) ? $workMode : 'office';

        $lat = is_numeric($lat) ? (float) $lat : null;
        $lng = is_numeric($lng) ? (float) $lng : null;

        $allowlist = self::allowlist();
        $geo = self::geofence();
        $wfhAllowed = self::shiftAllowsWfh($shift) && (bool) HrmConfig::get('attendance.allow_wfh_bypass', true);

        $flags = [];

        // ── Kiểm tra IP ──
        $ipOk = null; // null = không cấu hình → bỏ qua
        if (! empty($allowlist) && $ip) {
            $ipOk = self::ipInAnyCidr($ip, $allowlist);
            if (! $ipOk) {
                $flags[] = 'ip_outside_office';
            }
        }

        // ── Kiểm tra geofence ──
        $geoOk = null;
        $distance = null;
        if ($geo) {
            if ($lat !== null && $lng !== null) {
                $distance = (int) round(self::haversine($lat, $lng, $geo['lat'], $geo['lng']));
                $geoOk = $distance <= $geo['radius'];
                if (! $geoOk) {
                    $flags[] = 'outside_geofence';
                }
            } else {
                // Geofence bật nhưng không có toạ độ → thiếu dữ liệu vị trí.
                $geoOk = false;
                $flags[] = 'no_location';
            }
        }

        // ── Tổng hợp ──
        $checks = array_filter([$ipOk, $geoOk], fn ($v) => $v !== null);
        // Hợp lệ nếu KHÔNG có check nào fail (mọi check đã cấu hình đều pass),
        // hoặc ca cho phép WFH (bỏ qua IP/vị trí).
        $withinPolicy = empty($checks) ? true : ! in_array(false, $checks, true);
        if (! $withinPolicy && $wfhAllowed) {
            $withinPolicy = true;
            $flags[] = 'wfh_bypass';
        }
        // Chế độ làm việc khai báo: WFH / đi công trình → không áp geofence văn
        // phòng (vị trí vẫn được GHI để đối soát), coi như trong chính sách.
        if (in_array($workMode, ['wfh', 'on_site'], true)) {
            $withinPolicy = true;
            $flags = array_values(array_diff($flags, ['outside_geofence', 'no_location', 'ip_outside_office']));
            $flags[] = $workMode; // 'wfh' | 'on_site'
        }

        return [
            'mode' => $mode,
            'ip' => $ip,
            'ip_ok' => $ipOk,
            'geo_ok' => $geoOk,
            'distance_m' => $distance,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy' => null,
            'source' => $source ?: 'web',
            'device' => $userAgent ? substr($userAgent, 0, 255) : null,
            'wfh' => $wfhAllowed,
            'work_mode' => $workMode,
            'within_policy' => $withinPolicy,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /** Chế độ áp dụng hiện tại. */
    public static function mode(): string
    {
        return strtolower((string) HrmConfig::get('attendance.enforce_mode', 'flag'));
    }

    /** @return array<int,string> */
    private static function allowlist(): array
    {
        $raw = HrmConfig::get('attendance.ip_allowlist', '');
        if (is_array($raw)) {
            $list = $raw;
        } else {
            $list = preg_split('/[,\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_filter(array_map('trim', $list)));
    }

    /** @return array{lat:float,lng:float,radius:int}|null */
    private static function geofence(): ?array
    {
        $lat = HrmConfig::get('attendance.geofence_lat', '');
        $lng = HrmConfig::get('attendance.geofence_lng', '');
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'radius' => max(1, (int) HrmConfig::get('attendance.geofence_radius_m', 200)),
        ];
    }

    private static function shiftAllowsWfh($shift): bool
    {
        $meta = $shift->meta ?? (is_array($shift) ? ($shift['meta'] ?? null) : null);
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $v = is_array($meta) ? ($meta['allow_wfh'] ?? null) : null;

        return $v === true || $v === 'true' || $v === 1 || $v === '1';
    }

    /** Khoảng cách Haversine (mét). */
    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0; // bán kính Trái Đất (m)
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @param array<int,string> $cidrs */
    private static function ipInAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Hỗ trợ IPv4 đơn lẻ hoặc CIDR (vd 203.0.113.0/24). */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}

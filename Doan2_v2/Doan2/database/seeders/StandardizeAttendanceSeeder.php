<?php

namespace Database\Seeders;

use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn hoá DỮ LIỆU chấm công để Bảng công nhất quán + trực quan + đúng nghiệp vụ
 * (nền tảng cho lương / xếp ca / tăng ca). Idempotent — chạy lại tạo cùng kết quả.
 *
 * Việc làm:
 *  1) Đánh dấu tài khoản kỹ thuật (System Administrator) là `meta.system_account`
 *     → KHÔNG chấm công, loại khỏi bảng công/thống kê.
 *  2) Bảo đảm MỌI nhân viên thật ACTIVE có 1 ca làm việc hiệu lực (mặc định HC).
 *  3) Bù dữ liệu chấm công còn thiếu trong [START..END]: mỗi ngày công (loại CN +
 *     lễ) có giờ vào/ra thực tế quanh ca, phân bố hợp lý ON_TIME/LATE/EARLY_LEAVE/
 *     ABSENT; không ghi đè bản ghi đã có.
 */
class StandardizeAttendanceSeeder extends Seeder
{
    // Dữ liệu legacy bắt đầu từ 01/03/2024; bù liên tục từ mốc đó đến hôm nay.
    // Có thể override bằng env cho tenant/demo khác.
    private static function start(): string
    {
        return env('HRM_SEED_ATT_START', '2024-03-01');
    }

    private static function end(): string
    {
        // Đổ tới HÔM QUA, chừa hôm nay cho chấm công "live" (nút check-in còn mới +
        // dashboard "có mặt hôm nay" tăng dần theo thực tế). Tránh biểu đồ 30 ngày
        // rớt về 0 ở mép phải khi seed cũ hơn ngày chạy.
        return env('HRM_SEED_ATT_END', CarbonImmutable::yesterday()->toDateString());
    }

    public function run(): void
    {
        mt_srand(20260627); // tái lập được

        $this->syncLegacyDates();
        DB::table('attendances')
            ->whereBetween('work_date', [self::start(), self::end()])
            ->where('meta->source', 'dashboard-demo')
            ->delete();

        $tenantIds = DB::table('tenants')->pluck('id')->all();

        foreach ($tenantIds as $tenantId) {
            $this->markSystemAccounts($tenantId);

            $hcShiftId = DB::table('shift_types')
                ->where('tenant_id', $tenantId)
                ->where('shift_code', 'HC')
                ->value('id');

            // Map ca theo id (để phân loại).
            $shifts = DB::table('shift_types')->where('tenant_id', $tenantId)->get()->keyBy('id');

            // Nhân viên thật (loại tài khoản hệ thống).
            // Mọi nhân viên ĐANG LÀM (chính thức + thử việc) đều chấm công.
            $employees = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['ACTIVE', 'PROBATION'])
                ->get(['id', 'legal_entity_id', 'hire_date', 'profile']);

            $real = $employees->filter(fn ($e) => ! $this->isSystemAccount($e));

            $holidays = $this->holidaySet($tenantId);

            $created = 0;
            $absent = 0;
            $late = 0;
            $early = 0;

            foreach ($real as $emp) {
                $shiftId = $this->ensureShiftAssignment($tenantId, $emp, $hcShiftId);
                $shift = $shifts[$shiftId] ?? null;

                $existingDates = DB::table('attendances')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $emp->id)
                    ->whereBetween('work_date', [self::start(), self::end()])
                    ->pluck('work_date')
                    ->mapWithKeys(fn ($date) => [CarbonImmutable::parse($date)->toDateString() => true])
                    ->all();

                $start = CarbonImmutable::parse(self::start());
                $end = CarbonImmutable::parse(self::end());
                // Không tạo công trước ngày vào làm.
                $hire = $emp->hire_date ? CarbonImmutable::parse($emp->hire_date) : null;

                $rows = [];
                for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                    if (TimePolicy::isRestDay($d) || in_array($d->toDateString(), $holidays, true)) {
                        continue; // CN + lễ: không có công
                    }
                    if ($hire && $d->lt($hire->startOfDay())) {
                        continue;
                    }
                    if (isset($existingDates[$d->toDateString()])) {
                        continue;
                    }

                    $rows[] = $this->makeRow($tenantId, $emp, $shiftId, $shift, $d->toDateString(), $created, $absent, $late, $early);
                }

                if ($rows) {
                    DB::table('attendances')->insert($rows);
                }
            }

            $this->command?->info("Tenant {$tenantId}: tạo {$created} bản ghi công ({$late} trễ, {$early} về sớm, {$absent} vắng) cho {$real->count()} NV.");
        }
    }

    /** Khôi phục ngày chấm công legacy đang nằm trong meta JSON. */
    private function syncLegacyDates(): void
    {
        $rows = DB::table('attendances')->whereNull('work_date')->whereNotNull('meta')->get(['id', 'meta']);

        foreach ($rows as $row) {
            $date = $this->decode($row->meta)['attendance_date'] ?? null;
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                DB::table('attendances')->where('id', $row->id)->update([
                    'work_date' => $date,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Tạo 1 dòng chấm công đã phân loại cho 1 ngày. */
    private function makeRow($tenantId, $emp, $shiftId, $shift, string $date, int &$created, int &$absent, int &$late, int &$early): array
    {
        $roll = mt_rand(1, 100);
        $checkIn = null;
        $checkOut = null;

        if ($roll <= 4) {
            // ABSENT — không giờ.
            $absent++;
        } else {
            [$sH, $sM] = $this->hm($shift->start_time ?? '08:00:00');
            [$eH, $eM] = $this->hm($shift->end_time ?? '17:00:00');
            $startMin = $sH * 60 + $sM;
            $endMin = $eH * 60 + $eM;
            if ($endMin <= $startMin) {
                $endMin += 1440; // ca qua đêm
            }

            if ($roll <= 11) {
                // LATE: vào trễ 10–40'
                $inMin = $startMin + mt_rand(10, 40);
                $outMin = $endMin + mt_rand(0, 20);
                $late++;
            } elseif ($roll <= 16) {
                // EARLY_LEAVE: về sớm 15–60'
                $inMin = $startMin - mt_rand(0, 5);
                $outMin = $endMin - mt_rand(15, 60);
                $early++;
            } else {
                // ON_TIME: vào sớm 0–8', ra đúng/muộn 0–25'
                $inMin = $startMin - mt_rand(0, 8);
                $outMin = $endMin + mt_rand(0, 25);
            }
            $checkIn = $this->minToTime($inMin);
            $checkOut = $this->minToTime($outMin);
        }

        $cls = TimePolicy::classifyAttendance($shift, $checkIn, $checkOut);
        $created++;

        $now = now();

        return [
            'employee_id' => $emp->id,
            'work_date' => $date,
            'check_in_time' => $checkIn,
            'check_out_time' => $checkOut,
            'shift_type_id' => $shiftId,
            'status' => $cls['status'],
            'meta' => json_encode([
                'late_minutes' => $cls['late_minutes'],
                'early_leave_minutes' => $cls['early_leave_minutes'],
                'worked_hours' => $cls['worked_hours'],
                'source' => 'standardized-seed',
            ], JSON_UNESCAPED_UNICODE),
            'tenant_id' => $tenantId,
            'legal_entity_id' => $emp->legal_entity_id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Bảo đảm NV có 1 ca hiệu lực; trả về shift_type_id. */
    private function ensureShiftAssignment($tenantId, $emp, $hcShiftId): int
    {
        $existing = DB::table('shift_assignments')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $emp->id)
            ->where('status', '!=', 'INACTIVE')
            ->orderByDesc('effective_date')
            ->first();

        if ($existing && $existing->shift_type_id) {
            return (int) $existing->shift_type_id;
        }

        $now = now();
        DB::table('shift_assignments')->insert([
            'employee_id' => $emp->id,
            'shift_type_id' => $hcShiftId,
            'effective_date' => $emp->hire_date ?: self::start(),
            'is_permanent' => DB::raw('true'),
            'status' => 'ACTIVE',
            'notes' => 'Gán ca mặc định (chuẩn hoá dữ liệu)',
            'meta' => json_encode(['source' => 'standardized-seed'], JSON_UNESCAPED_UNICODE),
            'tenant_id' => $tenantId,
            'legal_entity_id' => $emp->legal_entity_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $hcShiftId;
    }

    /** Đánh dấu tài khoản kỹ thuật là system_account (không tính công). */
    private function markSystemAccounts($tenantId): void
    {
        $rows = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('employee_code', 'AD0001')->orWhere('full_name', 'System Administrator'))
            ->get(['id', 'profile']);

        foreach ($rows as $r) {
            $profile = $this->decode($r->profile);
            $profile['system_account'] = true;
            DB::table('employees')->where('id', $r->id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            // Tài khoản hệ thống không có công.
            DB::table('attendances')->where('tenant_id', $tenantId)->where('employee_id', $r->id)->delete();
        }
    }

    private function isSystemAccount($emp): bool
    {
        $profile = $this->decode($emp->profile ?? null);

        return ! empty($profile['system_account']);
    }

    /** @return array<int,string> */
    private function holidaySet($tenantId): array
    {
        return DB::table('holidays')
            ->where('tenant_id', $tenantId)
            ->whereBetween('holiday_date', [self::start(), self::end()])
            ->pluck('holiday_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->unique()->values()->all();
    }

    private function hm($time): array
    {
        $p = explode(':', (string) $time);

        return [(int) ($p[0] ?? 0), (int) ($p[1] ?? 0)];
    }

    private function minToTime(int $min): string
    {
        $min = (($min % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d:00', intdiv($min, 60), $min % 60);
    }

    private function decode($p): array
    {
        if (! $p) {
            return [];
        }
        $d = is_string($p) ? json_decode($p, true) : (array) $p;

        return is_array($d) ? $d : [];
    }
}

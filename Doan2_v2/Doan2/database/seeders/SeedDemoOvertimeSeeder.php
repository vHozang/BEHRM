<?php

namespace Database\Seeders;

use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dữ liệu tăng ca demo NHẤT QUÁN với chấm công: OT chỉ gắn vào ngày nhân viên
 * THỰC SỰ ĐI LÀM (có bản ghi attendance present). Thêm vài ca làm ngày Chủ nhật
 * (rest) kèm cả bản ghi đi làm + OT để minh hoạ "đi làm ngày nghỉ → OT".
 *
 * Idempotent: xoá OT demo cũ (meta.source='demo-ot') + bản ghi đi-làm-CN demo
 * (meta.source='demo-ot-rest') rồi tạo lại. Nên chạy SAU StandardizeAttendanceSeeder.
 */
class SeedDemoOvertimeSeeder extends Seeder
{
    private const START = '2026-05-01';
    private const END = '2026-06-26';

    private const PRESENT = ['ON_TIME', 'LATE', 'EARLY_LEAVE'];

    public function run(): void
    {
        mt_srand(20260628);

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            // Đặt tenant context để TimePolicy đọc đúng hệ số OT cấu hình riêng
            // (T7/CN) của tenant — CLI seeder mặc định không có tenant context.
            \App\Support\TenantContext::set((int) $tenantId);

            // Dọn dữ liệu demo cũ.
            DB::table('overtime_requests')->where('tenant_id', $tenantId)
                ->whereRaw("meta->>'source' = 'demo-ot'")->delete();
            DB::table('attendances')->where('tenant_id', $tenantId)
                ->whereRaw("meta->>'source' = 'demo-ot-rest'")->delete();

            $employees = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['ACTIVE', 'PROBATION'])
                ->whereRaw("COALESCE((profile->>'system_account')::boolean, false) = false")
                ->get(['id', 'legal_entity_id']);

            $assignments = $this->assignmentMap($tenantId);
            $now = now();
            $created = 0;
            $restCreated = 0;

            foreach ($employees as $emp) {
                $shiftId = $assignments[$emp->id] ?? DB::table('shift_types')->where('tenant_id', $tenantId)->where('shift_code', 'HC')->value('id');

                // 1) OT trên các ngày NV thực sự đi làm (có attendance present).
                $workedDates = DB::table('attendances')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $emp->id)
                    ->whereBetween('work_date', [self::START, self::END])
                    ->whereIn('status', self::PRESENT)
                    ->pluck('work_date')
                    ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
                    ->all();

                foreach ($workedDates as $date) {
                    if (mt_rand(1, 100) > 25) {
                        continue; // ~25% ngày đi làm có OT
                    }
                    // OT ngày thường: LINH HOẠT 1–4h (Đ.107: ≤4h/ngày), bắt đầu sau giờ HC.
                    $h = mt_rand(1, 4);
                    $created += $this->insertOt($tenantId, $emp->id, $date, $now, '18:00:00', sprintf('%02d:00:00', 18 + $h), (float) $h);
                }

                // 2) ~1/3 nhân viên có 1 ca làm Chủ nhật (rest): ĐỦ 1 CA = 8h → OT 8h.
                if (mt_rand(1, 3) === 1) {
                    $sunday = $this->firstRestDayWithout($tenantId, $emp->id);
                    if ($sunday) {
                        DB::table('attendances')->insert([
                            'employee_id' => $emp->id,
                            'work_date' => $sunday,
                            'check_in_time' => '08:00:00',
                            'check_out_time' => '17:00:00',
                            'shift_type_id' => $shiftId,
                            'status' => 'ON_TIME',
                            'meta' => json_encode(['worked_hours' => 8, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'source' => 'demo-ot-rest'], JSON_UNESCAPED_UNICODE),
                            'tenant_id' => $tenantId,
                            'legal_entity_id' => $emp->legal_entity_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        // Làm cả ngày nghỉ = tăng ca đủ 1 ca (8h, 200%).
                        $created += $this->insertOt($tenantId, $emp->id, $sunday, $now, '08:00:00', '17:00:00', 8.0);
                        $restCreated++;
                    }
                }
            }

            $this->command?->info("Tenant {$tenantId}: {$created} đơn OT (gắn ngày đi làm), {$restCreated} ca làm CN demo.");
        }
    }

    private function insertOt($tenantId, $empId, string $date, $now, string $start, string $end, float $hours): int
    {
        $cls = TimePolicy::classifyOvertime($date, $start, $end, $hours);

        DB::table('overtime_requests')->insert([
            'employee_id' => $empId,
            'work_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'total_hours' => $cls['total_hours'],
            'status' => 'APPROVED',
            'meta' => json_encode([
                'day_type' => $cls['day_type'], 'multiplier' => $cls['multiplier'],
                'night_hours' => $cls['night_hours'], 'pay_factor' => $cls['pay_factor'],
                'label' => $cls['label'], 'reason' => 'Tăng ca xử lý công việc',
                'source' => 'demo-ot',
            ], JSON_UNESCAPED_UNICODE),
            'tenant_id' => $tenantId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 1;
    }

    /** Một ngày Chủ nhật trong khoảng mà NV chưa có chấm công. */
    private function firstRestDayWithout($tenantId, $empId): ?string
    {
        $d = CarbonImmutable::parse(self::START);
        $end = CarbonImmutable::parse(self::END);
        for (; $d->lte($end); $d = $d->addDay()) {
            if (! TimePolicy::isRestDay($d)) {
                continue;
            }
            $ds = $d->toDateString();
            $exists = DB::table('attendances')->where('tenant_id', $tenantId)
                ->where('employee_id', $empId)->where('work_date', $ds)->exists();
            if (! $exists) {
                return $ds;
            }
        }

        return null;
    }

    private function assignmentMap($tenantId): array
    {
        $map = [];
        DB::table('shift_assignments')->where('tenant_id', $tenantId)
            ->where('status', '!=', 'INACTIVE')->orderByDesc('effective_date')
            ->get(['employee_id', 'shift_type_id'])
            ->each(function ($r) use (&$map) {
                if (! isset($map[$r->employee_id])) {
                    $map[$r->employee_id] = $r->shift_type_id;
                }
            });

        return $map;
    }
}

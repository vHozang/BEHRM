<?php

namespace Database\Seeders;

use App\Support\TimePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chấm công HÔM NAY cho công nhân (mô hình máy chấm công nhà máy: đã vào ca, CHƯA ra
 * — đang làm, ~5% vắng). StandardizeAttendanceSeeder chỉ đổ tới hôm qua để chừa hôm
 * nay cho chấm công "live"; nhưng dashboard "có mặt hôm nay" sẽ = 0/0 nếu KHÔNG ai
 * chấm. Ở đây đổ sẵn cho công nhân để dashboard "sống", vẫn CHỪA nhân viên văn phòng/
 * demo (NV/AD) để trình diễn nút check-in trực tiếp.
 *
 * Idempotent: chỉ chèn nếu công nhân CHƯA có công hôm nay. Bỏ qua nếu hôm nay nghỉ/lễ.
 */
class LiveTodayAttendanceSeeder extends Seeder
{
    public function run(): void
    {
        if (TimePolicy::isRestDay(CarbonImmutable::today())) {
            return; // chủ nhật/ngày nghỉ: nhà máy không chạy
        }

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            $inserted = DB::affectingStatement(<<<'SQL'
                INSERT INTO attendances (employee_id, shift_type_id, work_date, check_in_time, check_out_time, status, meta, created_at, updated_at, tenant_id, legal_entity_id)
                SELECT e.id, COALESCE(sa.shift_type_id, 1), CURRENT_DATE,
                       CASE WHEN e.id % 22 = 0 THEN NULL ELSE (TIME '06:00:00' + ((e.id % 95) || ' minutes')::interval) END,
                       NULL,
                       CASE WHEN e.id % 22 = 0 THEN 'ABSENT' WHEN e.id % 9 = 0 THEN 'LATE' ELSE 'ON_TIME' END,
                       jsonb_build_object('source', 'live-today-seed'),
                       now(), now(), e.tenant_id, e.legal_entity_id
                FROM employees e
                LEFT JOIN shift_assignments sa ON sa.employee_id = e.id AND sa.status = 'ACTIVE'
                WHERE e.tenant_id = ? AND e.employee_code LIKE 'CN%'
                  AND NOT EXISTS (SELECT 1 FROM attendances a WHERE a.employee_id = e.id AND a.work_date = CURRENT_DATE)
                SQL, [$tenantId]);

            $this->command?->info("LiveTodayAttendanceSeeder: chấm công hôm nay {$inserted} công nhân (tenant {$tenantId}).");
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn hoá dữ liệu theo đúng LUỒNG THỰC TẾ (timeline hợp lý):
 *  1) Ngày ký HĐ (meta.sign_date) = ngày bắt đầu = ngày vào làm (không thể ký
 *     sau ngày hiệu lực 14 năm như trước).
 *  2) Kỳ lương = 3 tháng gần đây (4,5,6 / 2026); mỗi NV chỉ có lương cho kỳ
 *     RƠI VÀO/SAU ngày vào làm (không có lương trước khi vào làm).
 *  3) Năm tốt nghiệp ≤ năm vào làm (tốt nghiệp trước khi đi làm).
 * Idempotent.
 */
class FixDataRealismSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1) Cập nhật 3 kỳ lương về tháng 4/5/6 năm 2026 ──
        $plan = [
            ['P-2026-04', 'Kỳ lương tháng 4/2026', '2026-04-01', '2026-04-30', 'PAID'],
            ['P-2026-05', 'Kỳ lương tháng 5/2026', '2026-05-01', '2026-05-31', 'PAID'],
            ['P-2026-06', 'Kỳ lương tháng 6/2026', '2026-06-01', '2026-06-30', 'OPEN'],
        ];
        $periods = DB::table('salary_periods')->orderBy('id')->get();
        foreach ($periods as $i => $p) {
            if (! isset($plan[$i])) {
                continue;
            }
            [$code, $name, $start, $end, $status] = $plan[$i];
            DB::table('salary_periods')->where('id', $p->id)->update([
                'period_code' => $code, 'period_name' => $name, 'period_type' => 'MONTHLY',
                'start_date' => $start, 'end_date' => $end, 'status' => $status, 'updated_at' => now(),
            ]);
        }
        $periods = DB::table('salary_periods')->orderBy('id')->get();

        // ── 2) Ngày ký = ngày bắt đầu cho mọi hợp đồng ──
        foreach (DB::table('contracts')->get() as $c) {
            $meta = $this->decode($c->meta);
            $meta['sign_date'] = $c->start_date;            // ký = ngày hiệu lực
            $meta['effective_date'] = $c->start_date;
            DB::table('contracts')->where('id', $c->id)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        foreach (DB::table('employees')->orderBy('id')->get() as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }
            $id = (int) $e->id;
            $hire = $e->hire_date ?: '2020-01-01';
            $hireYear = (int) substr((string) $hire, 0, 4);

            // ── 3) Năm tốt nghiệp ≤ năm vào làm ──
            foreach (DB::table('qualifications')->where('employee_id', $id)->get() as $q) {
                if ($q->graduation_year && (int) $q->graduation_year > $hireYear) {
                    DB::table('qualifications')->where('id', $q->id)->update([
                        'graduation_year' => $hireYear,
                        'issued_date' => sprintf('%d-06-15', $hireYear),
                        'updated_at' => now(),
                    ]);
                }
            }

            // ── 4) Lương: chỉ kỳ sau ngày vào làm ──
            $contract = DB::table('contracts')->where('employee_id', $id)->where('status', 'CÓ_HIỆU_LỰC')->orderBy('id')->first();
            $cmeta = $contract ? $this->decode($contract->meta) : [];
            $basic = (float) ($cmeta['basic_salary'] ?? (12000000 + ($id % 10) * 1000000));
            $allow = (float) ($cmeta['allowances'] ?? 1500000);
            $gross = $basic + $allow;
            $net = round($gross * 0.87, 0);

            foreach ($periods as $p) {
                // Kỳ "thuộc về" NV nếu kỳ kết thúc >= ngày vào làm (đã đi làm trong kỳ đó).
                $covers = Carbon::parse($p->end_date)->gte(Carbon::parse($hire));
                $exists = DB::table('salary_details')->where('employee_id', $id)->where('period_id', $p->id)->first();

                if ($covers && ! $exists) {
                    DB::table('salary_details')->insert([
                        'employee_id' => $id, 'tenant_id' => $e->tenant_id, 'legal_entity_id' => $e->legal_entity_id,
                        'period_id' => $p->id, 'contract_id' => $contract->id ?? null,
                        'gross_salary' => $gross, 'net_salary' => $net,
                        'transfer_status' => $p->status === 'PAID' ? 'PAID' : 'PENDING',
                        'meta' => json_encode(['basic_salary' => $basic, 'allowances' => $allow], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif (! $covers && $exists) {
                    DB::table('salary_details')->where('id', $exists->id)->delete(); // bỏ lương trước khi vào làm
                }
            }

            $this->command?->info("Realism #{$id} {$e->full_name} (hire {$hire})");
        }
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

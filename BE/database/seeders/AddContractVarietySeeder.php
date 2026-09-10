<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Thêm sự đa dạng loại hợp đồng (thử việc / xác định thời hạn) cho một số nhân
 * viên bằng cách đặt ngày vào làm gần đây + gán loại HĐ tương ứng — VẪN giữ các
 * quy tắc nhất quán: HĐ bắt đầu = ngày vào làm, end_date tính theo loại, đúng 1
 * HĐ đang hiệu lực, probation_end = vào làm + 60 ngày.
 *
 * Chỉ đụng tới các nhân viên trong $plan; phần còn lại giữ "không xác định thời
 * hạn" như FixEmployeeDataConsistencySeeder đã chuẩn hoá. Idempotent.
 */
class AddContractVarietySeeder extends Seeder
{
    public function run(): void
    {
        // employee_id => [hire_date, contract_type_code]
        // HDTV = thử việc (+60 ngày) | HDLD02 = 12 tháng | HDLD03 = 24 tháng
        $plan = [
            16 => ['2026-05-20', 'HDTV'],
            12 => ['2026-06-10', 'HDTV'],
            18 => ['2026-02-01', 'HDLD02'],
            10 => ['2025-10-01', 'HDLD02'],
            20 => ['2025-04-15', 'HDLD03'],
            8 => ['2024-12-01', 'HDLD03'],
        ];

        $typeIds = DB::table('contract_types')->pluck('id', 'contract_type_code');

        foreach ($plan as $empId => [$hire, $code]) {
            $emp = DB::table('employees')->where('id', $empId)->first();
            if (! $emp || stripos($emp->full_name, 'System Administrator') !== false) {
                continue;
            }

            $typeId = $typeIds[$code] ?? null;
            if (! $typeId) {
                $this->command?->warn("Bỏ qua #{$empId}: không có loại HĐ {$code}");
                continue;
            }

            $start = Carbon::parse($hire);
            $end = match ($code) {
                'HDTV' => $start->copy()->addDays(60),
                'HDLD02' => $start->copy()->addMonths(12)->subDay(),
                'HDLD03' => $start->copy()->addMonths(24)->subDay(),
                default => null,
            };

            // 1) Ngày vào làm + thử việc
            $profile = $this->decode($emp->profile);
            $profile['probation_end_date'] = $start->copy()->addDays(60)->toDateString();
            DB::table('employees')->where('id', $empId)->update([
                'hire_date' => $start->toDateString(),
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // 2) HĐ đang hiệu lực: gán loại + start = vào làm + end theo loại
            $active = DB::table('contracts')
                ->where('employee_id', $empId)
                ->where('status', 'CÓ_HIỆU_LỰC')
                ->orderBy('id')
                ->first();

            if ($active) {
                DB::table('contracts')->where('id', $active->id)->update([
                    'contract_type_id' => $typeId,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end ? $end->toDateString() : null,
                    'updated_at' => now(),
                ]);
            }

            $this->command?->info("#{$empId} {$emp->full_name}: {$code} {$start->toDateString()} → " . ($end ? $end->toDateString() : 'vô thời hạn'));
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

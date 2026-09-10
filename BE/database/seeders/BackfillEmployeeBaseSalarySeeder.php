<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bù `employees.base_salary` từ hợp đồng đang hiệu lực (contracts.meta.basic_salary).
 *
 * PayrollRunService tính lương dựa trên cột denormalized `employees.base_salary`,
 * nhưng dữ liệu lương thực nằm trong meta hợp đồng → trước đây cột này NULL khiến
 * mọi nhân viên bị bỏ qua khi chạy lương. Seeder idempotent: chạy lại cập nhật
 * đúng theo hợp đồng hiện hành. Tài khoản hệ thống (không hợp đồng) được bỏ qua.
 */
class BackfillEmployeeBaseSalarySeeder extends Seeder
{
    /** Trạng thái hợp đồng đang hiệu lực (nhãn VN dùng trong dữ liệu). */
    private const ACTIVE_CONTRACT = ['CÓ_HIỆU_LỰC', 'ACTIVE'];

    public function run(): void
    {
        $employees = DB::table('employees')
            ->whereIn('status', ['ACTIVE', 'PROBATION'])
            ->get(['id', 'employee_code', 'profile']);

        $updated = 0;
        $missing = [];

        foreach ($employees as $emp) {
            $profile = $this->decode($emp->profile);
            if (! empty($profile['system_account'])) {
                continue; // tài khoản hệ thống không tính lương
            }

            // Hợp đồng hiệu lực mới nhất của nhân viên.
            $contract = DB::table('contracts')
                ->where('employee_id', $emp->id)
                ->whereIn('status', self::ACTIVE_CONTRACT)
                ->orderByDesc('id')
                ->first();

            $basic = null;
            if ($contract && $contract->meta) {
                $meta = $this->decode($contract->meta);
                $raw = $meta['basic_salary'] ?? null;
                if (is_numeric($raw) && (float) $raw > 0) {
                    $basic = (float) $raw;
                }
            }

            if ($basic === null) {
                $missing[] = $emp->employee_code;
                continue;
            }

            DB::table('employees')->where('id', $emp->id)->update([
                'base_salary' => $basic,
                'updated_at' => now(),
            ]);
            $updated++;
        }

        $this->command?->info("Backfill base_salary: cập nhật {$updated} nhân viên.");
        if ($missing) {
            $this->command?->warn('Thiếu lương hợp đồng (cần HR nhập): ' . implode(', ', $missing));
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

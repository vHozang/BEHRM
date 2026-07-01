<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sửa các điểm dữ liệu demo vô lý do seeder trước tạo ra + đảm bảo mọi nhân viên
 * đều có hợp đồng:
 *  1) Liên hệ khẩn cấp: tên và quan hệ phải khớp giới tính (vd không để "Trần Thị
 *     Lan" mà quan hệ là "Chồng"); quan hệ vợ/chồng phải khớp giới tính NV.
 *  2) Người phụ thuộc vợ/chồng: quan hệ phải ngược giới tính NV (NV nam → "Vợ").
 *  3) Tạo hợp đồng ACTIVE cho nhân viên chưa có hợp đồng.
 *
 * Dùng DB:: trực tiếp (CLI, bỏ qua tenant scope). An toàn chạy lại nhiều lần.
 */
class FixDemoDataConsistencySeeder extends Seeder
{
    private array $maleNames = ['Nguyễn Văn Hùng', 'Trần Văn Minh', 'Lê Văn Dũng', 'Phạm Văn Sơn', 'Hoàng Văn Nam', 'Vũ Văn Thành'];
    private array $femaleNames = ['Trần Thị Lan', 'Lê Thị Hoa', 'Phạm Thị Thu', 'Nguyễn Thị Mai', 'Vũ Thị Hà', 'Đỗ Thị Hương'];

    public function run(): void
    {
        $employees = DB::table('employees')->orderBy('id')->get();

        foreach ($employees as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }
            $id = (int) $e->id;
            $isMale = strtoupper((string) $e->gender) === 'NAM' || strtoupper((string) $e->gender) === 'MALE';
            $profile = $this->decode($e->profile);
            $married = ($profile['marital_status'] ?? '') === 'MARRIED';

            // ── 1) Liên hệ khẩn cấp: chọn quan hệ + tên khớp giới tính ──
            $pick = $id % 5;
            if ($pick === 4) {
                if ($married) {
                    $rel = $isMale ? 'Vợ' : 'Chồng';
                    $nameIsMale = ! $isMale; // vợ/chồng ngược giới tính NV
                } else {
                    $rel = 'Mẹ';
                    $nameIsMale = false;
                }
            } else {
                [$rel, $g] = [['Bố', 'M'], ['Mẹ', 'F'], ['Anh trai', 'M'], ['Chị gái', 'F']][$pick];
                $nameIsMale = $g === 'M';
            }
            $profile['emergency_contact_relationship'] = $rel;
            $profile['emergency_contact_name'] = $nameIsMale
                ? $this->maleNames[$id % 6]
                : $this->femaleNames[$id % 6];

            DB::table('employees')->where('id', $id)->update([
                'profile' => json_encode($profile, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            // ── 2) Người phụ thuộc vợ/chồng phải khớp giới tính NV ──
            $correctSpouseRel = $isMale ? 'Vợ' : 'Chồng';
            $spouseRows = DB::table('dependents')
                ->where('employee_id', $id)
                ->whereIn('relationship', ['Vợ', 'Chồng'])
                ->get();
            foreach ($spouseRows as $sp) {
                if ($sp->relationship !== $correctSpouseRel) {
                    DB::table('dependents')->where('id', $sp->id)->update([
                        'relationship' => $correctSpouseRel,
                        'full_name' => $isMale ? $this->femaleNames[$id % 6] : $this->maleNames[$id % 6],
                        'updated_at' => now(),
                    ]);
                    $this->command?->warn("Fixed spouse dependent for #{$id}: -> {$correctSpouseRel}");
                }
            }
        }

        // ── 3) Tạo hợp đồng ACTIVE cho nhân viên chưa có hợp đồng ──
        $defaultTypeId = DB::table('contract_types')->where('contract_type_code', 'HDLD01')->value('id')
            ?? DB::table('contract_types')->min('id');

        $withContract = DB::table('contracts')->pluck('employee_id')->unique()->all();
        $missing = DB::table('employees')
            ->whereNotIn('id', $withContract ?: [0])
            ->where('status', '!=', 'TERMINATED')
            ->get();

        $seq = (int) (DB::table('contracts')->count()) + 1;
        foreach ($missing as $e) {
            if (empty($e->full_name) || stripos($e->full_name, 'System Administrator') !== false) {
                continue;
            }
            $start = $e->hire_date ?: '2023-01-01';
            DB::table('contracts')->insert([
                'tenant_id' => $e->tenant_id,
                'legal_entity_id' => $e->legal_entity_id,
                'employee_id' => $e->id,
                'contract_type_id' => $defaultTypeId,
                'department_id' => $e->department_id,
                'position_id' => $e->position_id,
                'contract_number' => sprintf('HĐ/2024/%03d', $seq),
                'status' => 'CÓ_HIỆU_LỰC',
                'start_date' => $start,
                'end_date' => null, // không xác định thời hạn
                'meta' => json_encode([
                    'basic_salary' => 12000000 + ($e->id % 10) * 1000000,
                    'allowances' => 1500000,
                    'effective_date' => $start,
                    'notes' => 'Hợp đồng khởi tạo tự động (demo)',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->command?->info("Created contract {$seq} for #{$e->id} {$e->full_name}");
            $seq++;
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

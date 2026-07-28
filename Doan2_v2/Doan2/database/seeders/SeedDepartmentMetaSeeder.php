<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bù dữ liệu phòng ban để trang Phòng ban có dữ liệu trực quan:
 *  - cost_center (mã chi phí), budget (ngân sách tháng) cho từng phòng.
 *  - manager_id (trưởng phòng) nếu chưa có — lấy 1 nhân viên trong phòng.
 *  - Ngân sách ≈ quỹ lương × 1.2 (để thấy % sử dụng hợp lý); riêng 1 phòng đặt
 *    thấp hơn quỹ lương để demo cảnh báo "vượt ngân sách".
 *  - Xóa phòng ban rác (không tên).
 * Idempotent. Lưu key chuẩn: parent_department_id + manager_id trong meta.
 */
class SeedDepartmentMetaSeeder extends Seeder
{
    public function run(): void
    {
        // Cây phòng ban: BGD (Ban Giám đốc) là gốc; BKS + 8 phòng chính trực
        // thuộc BGD. Idempotent — trước đây chỉ set bằng SQL live, reseed là mất.
        foreach ([['BGD', 'Ban Giám đốc'], ['BKS', 'Ban kiểm soát']] as [$code, $name]) {
            if (! DB::table('departments')->where('department_code', $code)->exists()) {
                DB::table('departments')->insert([
                    'department_code' => $code, 'department_name' => $name,
                    'tenant_id' => 1, 'legal_entity_id' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $bgdId = (int) DB::table('departments')->where('department_code', 'BGD')->value('id');

        // Xóa phòng ban rác (tên rỗng, không nhân viên).
        DB::table('departments')
            ->where(fn ($q) => $q->whereNull('department_name')->orWhere('department_name', ''))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('employees')->whereColumn('employees.department_id', 'departments.id');
            })
            ->delete();

        // Quỹ lương theo phòng.
        $costRows = DB::table('employees as e')
            ->join('contracts as c', function ($j) {
                $j->on('c.employee_id', '=', 'e.id')->where('c.status', '=', 'CÓ_HIỆU_LỰC');
            })
            ->whereNotNull('e.department_id')->where('e.status', '!=', 'TERMINATED')
            ->groupBy('e.department_id')
            ->select('e.department_id', DB::raw("SUM(COALESCE(NULLIF(c.meta->>'basic_salary','')::numeric,0)+COALESCE(NULLIF(c.meta->>'allowances','')::numeric,0)) as total"))
            ->get();
        $cost = [];
        foreach ($costRows as $r) {
            $cost[(int) $r->department_id] = (float) $r->total;
        }

        foreach (DB::table('departments')->orderBy('id')->get() as $d) {
            if (empty($d->department_name)) {
                continue;
            }
            $meta = $this->decode($d->meta);

            // Cây phòng ban: FE chỉ đọc meta.parent_id → normalize key legacy
            // parent_department_id, và gắn phòng chính + BKS vào gốc BGD.
            if (empty($meta['parent_id']) && ! empty($meta['parent_department_id'])) {
                $meta['parent_id'] = (int) $meta['parent_department_id'];
            }
            $mainDepts = ['HCNS', 'KT', 'KD', 'IT', 'MKT', 'CSKH', 'KHO', 'SX', 'BKS'];
            if (empty($meta['parent_id']) && $bgdId && in_array($d->department_code, $mainDepts, true)) {
                $meta['parent_id'] = $bgdId;
            }

            $meta['cost_center'] = $meta['cost_center'] ?? ('CC-' . ($d->department_code ?: str_pad((string) $d->id, 2, '0', STR_PAD_LEFT)));

            $deptCost = (float) ($cost[$d->id] ?? 0);
            if (empty($meta['budget'])) {
                if ($d->id === 6) {
                    $meta['budget'] = 40000000; // < quỹ lương → demo vượt ngân sách
                } else {
                    $meta['budget'] = $deptCost > 0
                        ? (int) (ceil($deptCost * 1.2 / 1000000) * 1000000)
                        : 50000000;
                }
            }

            // Trưởng phòng: nếu chưa có → lấy nhân viên đầu tiên trong phòng.
            if (empty($meta['manager_id'])) {
                $firstEmp = DB::table('employees')->where('department_id', $d->id)
                    ->where('status', '!=', 'TERMINATED')->orderBy('id')->value('id');
                $meta['manager_id'] = $firstEmp ?: null;
            }

            DB::table('departments')->where('id', $d->id)->update([
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            $this->command?->info("Phòng #{$d->id} {$d->department_name}: CC={$meta['cost_center']} budget=" . number_format($meta['budget']) . " QL=" . number_format($deptCost));
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

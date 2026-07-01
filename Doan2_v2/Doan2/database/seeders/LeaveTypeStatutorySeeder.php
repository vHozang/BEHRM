<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chuẩn hoá danh mục loại nghỉ phép theo Bộ luật Lao động 2019 và gắn cấu hình
 * định mức (entitlement) vào `leave_types.meta` cho từng tenant. Idempotent —
 * khoá theo (tenant_id, leave_type_code), chạy lại cập nhật cùng dòng.
 *
 * Mô hình accrual:
 *   - annual    : phép năm (Đ.113/114) — có số dư, trừ vào leave_balances.
 *   - per_event : nghỉ theo sự kiện có trần ngày/lần (cưới, tang, thai sản…),
 *                 KHÔNG trừ phép năm.
 *   - none      : ốm (BHXH chi trả) / không lương — không số dư, không trần cứng.
 *
 * meta = { paid, accrual, requires_balance, default_days, max_days_per_event,
 *          affects_annual_balance, statutory_ref }
 */
class LeaveTypeStatutorySeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'ANNUAL' => [
                'name' => 'Nghỉ phép năm', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'annual', 'requires_balance' => true,
                    'default_days' => 12, 'affects_annual_balance' => true, 'statutory_ref' => 'Điều 113'],
            ],
            'SICK' => [
                'name' => 'Nghỉ ốm', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'none', 'requires_balance' => false,
                    'statutory_ref' => 'Điều 25 Luật BHXH'],
            ],
            'UNPAID' => [
                'name' => 'Nghỉ không lương', 'category' => 'UNPAID',
                'meta' => ['paid' => false, 'accrual' => 'none', 'requires_balance' => false,
                    'max_days_per_event' => 1, 'statutory_ref' => 'Điều 115.2'],
            ],
            'MATERNITY' => [
                'name' => 'Nghỉ thai sản', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                    'max_days_per_event' => 180, 'statutory_ref' => 'Điều 139'],
            ],
            'MARRIAGE' => [
                'name' => 'Nghỉ cưới (bản thân)', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                    'max_days_per_event' => 3, 'statutory_ref' => 'Điều 115.1.a'],
            ],
            'CHILD_MARRIAGE' => [
                'name' => 'Nghỉ con kết hôn', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                    'max_days_per_event' => 1, 'statutory_ref' => 'Điều 115.1.b'],
            ],
            'BEREAVEMENT' => [
                'name' => 'Nghỉ tang chế', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'per_event', 'requires_balance' => false,
                    'max_days_per_event' => 3, 'statutory_ref' => 'Điều 115.1.c'],
            ],
            'COMP_OFF' => [
                'name' => 'Nghỉ bù', 'category' => 'PAID',
                'meta' => ['paid' => true, 'accrual' => 'comp_off', 'requires_balance' => true,
                    'affects_annual_balance' => false, 'statutory_ref' => 'Nghỉ bù từ tăng ca'],
            ],
        ];

        $tenantIds = DB::table('tenants')->pluck('id')->all();
        if (empty($tenantIds)) {
            $tenantIds = DB::table('leave_types')->distinct()->pluck('tenant_id')->all();
        }

        $now = now();
        $upserts = 0;

        foreach ($tenantIds as $tenantId) {
            foreach ($catalog as $code => $def) {
                $existing = DB::table('leave_types')
                    ->where('tenant_id', $tenantId)
                    ->where('leave_type_code', $code)
                    ->first();

                // Giữ lại các key meta cũ không thuộc cấu hình chuẩn (vd demo_seed).
                $meta = $def['meta'];
                if ($existing && $existing->meta) {
                    $old = is_string($existing->meta) ? (json_decode($existing->meta, true) ?: []) : (array) $existing->meta;
                    $meta = array_merge($old, $meta);
                }

                DB::table('leave_types')->updateOrInsert(
                    ['tenant_id' => $tenantId, 'leave_type_code' => $code],
                    [
                        'leave_type_name' => $def['name'],
                        'category' => $def['category'],
                        'status' => $existing->status ?? 'ACTIVE',
                        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                        'updated_at' => $now,
                        'created_at' => $existing->created_at ?? $now,
                    ]
                );
                $upserts++;
            }
        }

        $this->command?->info("LeaveTypeStatutorySeeder: upserted {$upserts} loại nghỉ phép cho " . count($tenantIds) . ' tenant.');
    }
}

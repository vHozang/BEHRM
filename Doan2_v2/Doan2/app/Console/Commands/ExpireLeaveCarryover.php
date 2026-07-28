<?php

namespace App\Console\Commands;

use App\Support\HrmConfig;
use App\Support\Notifier;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hết hạn phép gộp (carryover) sau deadline nội quy — mặc định 31/03.
 *
 * Cơ sở: Đ.113.4 BLLĐ 2019 chỉ cho phép THỎA THUẬN gộp phép (≤3 năm/lần); mốc hết
 * hạn là CHÍNH SÁCH nội quy từng công ty → đọc config leave.carryover_deadline.
 *
 * Quy ước tiêu phép: phép gộp được tiêu TRƯỚC phép năm mới (mọi ngày nghỉ trước
 * deadline trừ vào phần gộp trước) → phần hết hạn = min(carryover, remaining).
 *
 * Idempotent: đánh dấu meta.carryover_expired trên balance; recomputeBalances đọc
 * marker này để không hồi sinh phép đã hết hạn. Ghi sổ leave_transactions (EXPIRE)
 * + leave_carryover_tracking + báo NV. Chạy hàng ngày — tự bỏ qua khi chưa tới hạn.
 */
class ExpireLeaveCarryover extends Command
{
    protected $signature = 'hrm:leave-carryover-expire';

    protected $description = 'Hết hạn phép gộp năm cũ sau deadline nội quy (mặc định 31/03)';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $year = (int) $today->format('Y');

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            TenantContext::set((int) $tenantId);

            [$m, $d] = array_map('intval', explode('-', (string) HrmConfig::get('leave.carryover_deadline', '03-31')) + [1 => 31]);
            $deadline = CarbonImmutable::create($year, max(1, $m), max(1, $d));
            if ($today->lte($deadline)) {
                $this->info("Tenant {$tenantId}: chưa tới hạn ({$deadline->toDateString()}) — bỏ qua.");
                TenantContext::clear();

                continue;
            }

            $expired = 0;
            $balances = DB::table('leave_balances')
                ->where('tenant_id', $tenantId)
                ->where('year', (string) $year)
                ->whereRaw("COALESCE((meta->>'carryover')::numeric, 0) > 0")
                ->whereRaw("meta->>'carryover_expired' IS NULL")
                ->get();

            foreach ($balances as $b) {
                DB::transaction(function () use ($b, $deadline, $year, $tenantId, &$expired) {
                    // Khóa hàng chống race với duyệt đơn nghỉ đang trừ số dư.
                    $row = DB::table('leave_balances')->where('id', $b->id)->lockForUpdate()->first();
                    $meta = json_decode($row->meta ?: '{}', true) ?: [];
                    if (isset($meta['carryover_expired'])) {
                        return; // job song song đã xử lý
                    }
                    $carry = (float) ($meta['carryover'] ?? 0);
                    $unused = min($carry, (float) $row->remaining_days);

                    $meta['carryover_expired'] = $unused;
                    $meta['carryover_expired_at'] = now()->toIso8601String();
                    DB::table('leave_balances')->where('id', $row->id)->update([
                        'total_days' => (float) $row->total_days - $unused,
                        'remaining_days' => (float) $row->remaining_days - $unused,
                        'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);

                    if ($unused > 0) {
                        DB::table('leave_transactions')->insert(TenantContext::stamp([
                            'employee_id' => $row->employee_id,
                            'leave_type_id' => $row->leave_type_id,
                            'transaction_date' => now()->toDateString(),
                            'transaction_type' => 'EXPIRE',
                            'quantity' => -$unused,
                            'before_balance' => (float) $row->remaining_days,
                            'after_balance' => (float) $row->remaining_days - $unused,
                            'reference_id' => $year,
                            'reference_type' => 'CARRYOVER_EXPIRE',
                            'reason' => "Hết hạn {$unused} ngày phép gộp năm ".($year - 1)." (hạn {$deadline->format('d/m/Y')})",
                            'meta' => json_encode(['carried' => $carry, 'expired' => $unused]),
                            'tenant_id' => $tenantId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]));
                    }

                    DB::table('leave_carryover_tracking')->updateOrInsert(
                        ['tenant_id' => $tenantId, 'employee_id' => $row->employee_id,
                            'leave_type_id' => $row->leave_type_id, 'year' => $year - 1],
                        ['carried_days' => $carry, 'used_days' => $carry - $unused, 'expired_days' => $unused,
                            'expiry_date' => $deadline->toDateString(), 'status' => 'EXPIRED',
                            'updated_at' => now(), 'created_at' => now()],
                    );

                    if ($unused > 0) {
                        Notifier::notify((int) $row->employee_id, 'Phép gộp đã hết hạn',
                            "{$unused} ngày phép chuyển từ năm ".($year - 1)." đã hết hạn sử dụng (hạn {$deadline->format('d/m/Y')} theo nội quy).",
                            'leave_balance', $row->id, ['priority' => 'normal']);
                        $expired++;
                    }
                });
            }

            $this->info("Tenant {$tenantId}: hết hạn phép gộp cho {$expired} nhân viên (quét ".$balances->count().' balance).');
            TenantContext::clear();
        }

        return self::SUCCESS;
    }
}

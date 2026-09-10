<?php

namespace App\Console\Commands;

use App\Support\HrmConfig;
use App\Support\Notifier;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nhắc hạn hàng ngày — biến các con số dashboard thành thông báo thật:
 *  1. Hợp đồng sắp hết hạn (≤ contract.expiry_alert_days, mặc định 30) → NV + tóm tắt cho HR.
 *     HDTV (thử việc) có lời nhắc riêng "sắp hết hạn thử việc".
 *  2. Chứng chỉ sắp hết hạn (ATLĐ tái huấn luyện...) → NV.
 *
 * Chống spam: mỗi đối tượng chỉ nhắc lại sau DEDUP_DAYS ngày (query exists trên
 * notifications theo reference). Tóm tắt HR theo tuần. Chạy qua Schedule 07:00.
 */
class SendExpiryReminders extends Command
{
    protected $signature = 'hrm:expiry-reminders';

    protected $description = 'Gửi thông báo hợp đồng/chứng chỉ sắp hết hạn (chạy hàng ngày)';

    private const DEDUP_DAYS = 25;      // nhắc lại tối đa ~1 lần/tháng cho cùng 1 hợp đồng/chứng chỉ
    private const SUMMARY_DEDUP_DAYS = 7; // tóm tắt HR ~1 lần/tuần

    public function handle(): int
    {
        $today = CarbonImmutable::today();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            TenantContext::set((int) $tenantId);
            $days = (int) HrmConfig::get('contract.expiry_alert_days', 30);
            $limit = $today->addDays(max(1, $days));

            $sent = $this->remindContracts($tenantId, $today, $limit, $days);
            $sent += $this->remindCertificates($tenantId, $today, $limit);
            $sent += $this->remindCarryover($tenantId, $today);
            $this->info("Tenant {$tenantId}: gửi {$sent} thông báo nhắc hạn.");
            TenantContext::clear();
        }

        return self::SUCCESS;
    }

    private function remindContracts(int $tenantId, CarbonImmutable $today, CarbonImmutable $limit, int $days): int
    {
        $expiring = DB::table('contracts as c')
            ->leftJoin('contract_types as t', 't.id', '=', 'c.contract_type_id')
            ->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where('c.tenant_id', $tenantId)
            ->whereIn('c.status', ['ACTIVE', 'CÓ_HIỆU_LỰC', 'ĐANG_HIỆU_LỰC'])
            ->whereBetween('c.end_date', [$today->toDateString(), $limit->toDateString()])
            ->get(['c.id', 'c.employee_id', 'c.contract_number', 'c.end_date', 't.contract_type_code', 'e.full_name']);

        $sent = 0;
        foreach ($expiring as $c) {
            if ($this->alreadyNotified('contract', $c->id, self::DEDUP_DAYS)) {
                continue;
            }
            $end = CarbonImmutable::parse($c->end_date)->format('d/m/Y');
            $probation = ($c->contract_type_code ?? '') === 'HDTV';
            Notifier::notify(
                (int) $c->employee_id,
                $probation ? 'Sắp hết hạn thử việc' : 'Hợp đồng sắp hết hạn',
                $probation
                    ? "Thời gian thử việc của bạn kết thúc ngày {$end}. HR sẽ liên hệ về việc ký hợp đồng chính thức."
                    : "Hợp đồng {$c->contract_number} của bạn hết hạn ngày {$end}. Vui lòng liên hệ HR để gia hạn.",
                'contract', $c->id, ['priority' => 'high']
            );
            $sent++;
        }

        // Tóm tắt cho HR (1 thông báo/tuần thay vì 49 cái lẻ).
        if ($expiring->count() > 0 && ! $this->alreadyNotified('expiry_summary', $tenantId, self::SUMMARY_DEDUP_DAYS)) {
            $probationCount = $expiring->where('contract_type_code', 'HDTV')->count();
            Notifier::notifyMany(
                $this->roleHolderIds($tenantId, ['HR', 'ADMIN']),
                'Hợp đồng sắp hết hạn',
                "{$expiring->count()} hợp đồng hết hạn trong {$days} ngày tới"
                    . ($probationCount ? " (trong đó {$probationCount} thử việc)" : '')
                    . '. Xem trang Hợp đồng lao động để xử lý gia hạn.',
                'expiry_summary', $tenantId, ['priority' => 'high']
            );
            $sent++;
        }

        return $sent;
    }

    private function remindCertificates(int $tenantId, CarbonImmutable $today, CarbonImmutable $limit): int
    {
        $expiring = DB::table('certificates')
            ->where('tenant_id', $tenantId)
            ->whereBetween('expiry_date', [$today->toDateString(), $limit->toDateString()])
            ->get(['id', 'employee_id', 'certificate_name', 'expiry_date']);

        $sent = 0;
        foreach ($expiring as $c) {
            if ($this->alreadyNotified('certificate', $c->id, self::DEDUP_DAYS)) {
                continue;
            }
            Notifier::notify(
                (int) $c->employee_id,
                'Chứng chỉ sắp hết hạn',
                "{$c->certificate_name} hết hạn ngày " . CarbonImmutable::parse($c->expiry_date)->format('d/m/Y')
                    . '. Vui lòng đăng ký gia hạn/tái huấn luyện.',
                'certificate', $c->id, ['priority' => 'normal']
            );
            $sent++;
        }

        return $sent;
    }

    /** Nhắc phép gộp sắp hết hạn (≤30 ngày trước deadline nội quy, mặc định 31/03). */
    private function remindCarryover(int $tenantId, CarbonImmutable $today): int
    {
        [$m, $d] = array_map('intval', explode('-', (string) HrmConfig::get('leave.carryover_deadline', '03-31')) + [1 => 31]);
        $deadline = CarbonImmutable::create((int) $today->format('Y'), max(1, $m), max(1, $d));
        if ($today->gt($deadline) || $today->lt($deadline->subDays(30))) {
            return 0; // ngoài cửa sổ nhắc
        }

        $balances = DB::table('leave_balances')
            ->where('tenant_id', $tenantId)
            ->where('year', $today->format('Y'))
            ->whereRaw("COALESCE((meta->>'carryover')::numeric, 0) > 0")
            ->whereRaw("meta->>'carryover_expired' IS NULL")
            ->where('remaining_days', '>', 0)
            ->get(['id', 'employee_id', 'remaining_days', 'meta']);

        $sent = 0;
        foreach ($balances as $b) {
            if ($this->alreadyNotified('carryover', $b->id, self::DEDUP_DAYS)) {
                continue;
            }
            $carry = (float) (json_decode($b->meta ?: '{}', true)['carryover'] ?? 0);
            $atRisk = min($carry, (float) $b->remaining_days);
            if ($atRisk <= 0) {
                continue;
            }
            Notifier::notify((int) $b->employee_id, 'Phép gộp sắp hết hạn',
                "Bạn còn {$atRisk} ngày phép chuyển từ năm trước, hết hạn ngày {$deadline->format('d/m/Y')} theo nội quy — hãy sắp xếp nghỉ trước hạn.",
                'carryover', $b->id, ['priority' => 'high']);
            $sent++;
        }

        return $sent;
    }

    private function alreadyNotified(string $refType, int $refId, int $withinDays): bool
    {
        return DB::table('notifications')
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->where('created_at', '>=', now()->subDays($withinDays))
            ->exists();
    }

    /** id nhân viên đang giữ các role (HR/ADMIN) của tenant — người xử lý gia hạn. */
    private function roleHolderIds(int $tenantId, array $roleCodes): array
    {
        return DB::table('employee_roles as er')
            ->join('roles as r', 'r.id', '=', 'er.role_id')
            ->where('er.tenant_id', $tenantId)
            ->whereRaw('er.is_active = true')
            ->whereIn('r.role_code', $roleCodes)
            ->pluck('er.employee_id')->unique()->values()->all();
    }
}

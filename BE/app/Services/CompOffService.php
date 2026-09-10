<?php

namespace App\Services;

use App\Models\OvertimeRequest;
use App\Support\HrmConfig;
use Illuminate\Support\Facades\DB;

class CompOffService
{
    public function syncForRequest(OvertimeRequest $request): float
    {
        $meta = $request->meta ?? [];
        if (empty($meta['converted_to_comp_off'])) {
            return 0.0;
        }

        $minutes = max(0, (int) ($meta['payable_overtime_minutes'] ?? 0));
        $rate = (float) HrmConfig::get('overtime.comp_off_rate', 1.0);
        $standardHours = max(0.01, (float) HrmConfig::get('attendance.standard_hours_per_day', 8));
        $days = round(($minutes / 60) * $rate / $standardHours, 4);

        $leaveTypeId = DB::table('leave_types')
            ->where('tenant_id', $request->tenant_id)
            ->where('leave_type_code', 'COMP_OFF')
            ->value('id');
        if (! $leaveTypeId) {
            return 0.0;
        }

        DB::transaction(function () use ($request, $meta, $minutes, $days, $leaveTypeId): void {
            $transaction = DB::table('leave_transactions')
                ->where('tenant_id', $request->tenant_id)
                ->where('reference_type', 'OVERTIME_RECONCILED')
                ->where('reference_id', $request->id)
                ->lockForUpdate()
                ->first();
            $previousDays = $transaction ? (float) $transaction->quantity : 0.0;
            $delta = round($days - $previousDays, 4);
            if (abs($delta) < 0.0001) {
                return;
            }

            $year = (string) $request->work_date->year;
            $balance = DB::table('leave_balances')
                ->where('tenant_id', $request->tenant_id)
                ->where('employee_id', $request->employee_id)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();
            $before = $balance ? (float) $balance->remaining_days : 0.0;
            $after = round($before + $delta, 4);
            if ($after < 0) {
                $after = 0.0;
            }

            if ($balance) {
                DB::table('leave_balances')->where('id', $balance->id)->update([
                    'total_days' => max(0, round((float) $balance->total_days + $delta, 4)),
                    'remaining_days' => $after,
                    'updated_at' => now(),
                ]);
            } elseif ($days > 0) {
                DB::table('leave_balances')->insert([
                    'tenant_id' => $request->tenant_id,
                    'employee_id' => $request->employee_id,
                    'leave_type_id' => $leaveTypeId,
                    'year' => $year,
                    'total_days' => $days,
                    'used_days' => 0,
                    'remaining_days' => $days,
                    'meta' => json_encode(['source' => 'comp_off_reconciled'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $after = $days;
            }

            $payload = [
                'employee_id' => $request->employee_id,
                'leave_type_id' => $leaveTypeId,
                'transaction_date' => now()->toDateString(),
                'transaction_type' => 'GRANT',
                'quantity' => $days,
                'before_balance' => $before,
                'after_balance' => $after,
                'reference_id' => $request->id,
                'reference_type' => 'OVERTIME_RECONCILED',
                'reason' => "Nghỉ bù từ {$minutes} phút OT đã đối soát ngày {$request->work_date->toDateString()}",
                'tenant_id' => $request->tenant_id,
                'meta' => json_encode(['payable_overtime_minutes' => $minutes], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ];
            if ($transaction) {
                DB::table('leave_transactions')->where('id', $transaction->id)->update($payload);
            } elseif ($days > 0) {
                DB::table('leave_transactions')->insert(array_merge($payload, ['created_at' => now()]));
            }

            $requestMeta = $meta;
            $requestMeta['comp_off_days'] = $days;
            $requestMeta['comp_off_reconciled_minutes'] = $minutes;
            $requestMeta['comp_off_reconciled_at'] = now()->toIso8601String();
            DB::table('overtime_requests')->where('id', $request->id)->update([
                'meta' => json_encode($requestMeta, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        });

        return $days;
    }
}

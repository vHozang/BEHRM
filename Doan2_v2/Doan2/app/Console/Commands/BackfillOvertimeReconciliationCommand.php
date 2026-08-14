<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileOvertimeDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOvertimeReconciliationCommand extends Command
{
    protected $signature = 'attendance:backfill-overtime {--tenant=} {--from=} {--to=} {--chunk=500}';

    protected $description = 'Queue OT reconciliation for legacy approved requests in chunks.';

    public function handle(): int
    {
        $query = DB::table('overtime_requests')
            ->whereIn('status', ['APPROVED', 'ĐÃ_DUYỆT'])
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', (int) $tenant))
            ->when($this->option('from'), fn ($q, $from) => $q->whereDate('work_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->whereDate('work_date', '<=', $to))
            ->orderBy('id');
        $queued = 0;
        $seen = [];
        $query->chunkById(max(1, min(2000, (int) $this->option('chunk'))), function ($rows) use (&$queued, &$seen): void {
            foreach ($rows as $row) {
                $key = $row->tenant_id.'|'.$row->employee_id.'|'.$row->work_date;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                ReconcileOvertimeDay::dispatch((int) $row->tenant_id, (int) $row->employee_id, (string) $row->work_date);
                $queued++;
            }
        }, 'id');
        $this->info("Queued {$queued} OT reconciliation days.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\AttendanceOperation;
use App\Services\AttendanceSummaryService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RunAttendanceSummaryOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public string $operationId)
    {
        $this->onQueue('attendance');
    }

    public function handle(AttendanceSummaryService $summaries): void
    {
        $operation = AttendanceOperation::withoutTenantScope()->find($this->operationId);
        if (! $operation || $operation->status === 'COMPLETED') {
            return;
        }
        TenantContext::set((int) $operation->tenant_id, $operation->legal_entity_id ? (int) $operation->legal_entity_id : null);
        $filters = $operation->filters ?? [];
        $periodId = (int) ($filters['salary_period_id'] ?? 0);
        $operation->update(['status' => 'PROCESSING', 'started_at' => now(), 'error' => null]);
        try {
            $employeeQuery = DB::table('employees')
                ->where('tenant_id', $operation->tenant_id)
                ->where('legal_entity_id', $operation->legal_entity_id)
                ->whereIn('status', ['ACTIVE', 'PROBATION'])
                ->where(function ($query): void {
                    if (DB::getDriverName() === 'pgsql') {
                        $query->whereNull('profile->system_account')->orWhere('profile->system_account', false);
                    } else {
                        $query->whereNull('profile')->orWhereRaw("COALESCE(json_extract(profile, '$.system_account'), 0) = 0");
                    }
                })
                ->orderBy('id');
            $total = (clone $employeeQuery)->count();
            $operation->update(['total_items' => $total]);
            $processed = 0;
            $upserted = 0;
            $employeeQuery->chunkById(100, function ($employees) use ($summaries, $periodId, $operation, &$processed, &$upserted): void {
                $ids = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
                $result = $summaries->buildForEmployees($periodId, $ids);
                $processed += count($ids);
                $upserted += (int) $result['rows_upserted'];
                $operation->update([
                    'processed_items' => $processed,
                    'succeeded_items' => $upserted,
                ]);
            }, 'id');
            $result = ['period_id' => $periodId, 'employees' => $processed, 'rows_upserted' => $upserted];
            $operation->update(['status' => 'COMPLETED', 'result' => $result, 'completed_at' => now()]);
        } catch (\Throwable $exception) {
            $operation->update(['status' => 'FAILED', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'completed_at' => now()]);
            throw $exception;
        } finally {
            TenantContext::clear();
        }
    }
}

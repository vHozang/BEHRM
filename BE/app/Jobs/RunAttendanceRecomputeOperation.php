<?php

namespace App\Jobs;

use App\Models\AttendanceOperation;
use App\Services\TimesheetService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RunAttendanceRecomputeOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public string $operationId)
    {
        $this->onQueue('attendance');
    }

    public function handle(TimesheetService $timesheets): void
    {
        $operation = AttendanceOperation::withoutTenantScope()->find($this->operationId);
        if (! $operation || $operation->status === 'COMPLETED') {
            return;
        }
        TenantContext::set((int) $operation->tenant_id, $operation->legal_entity_id ? (int) $operation->legal_entity_id : null);
        $filters = $operation->filters ?? [];
        $operation->update(['status' => 'PROCESSING', 'started_at' => now(), 'error' => null]);
        try {
            $employeeIds = ! empty($filters['employee_ids']) ? (array) $filters['employee_ids'] : null;
            $total = DB::table('attendances')
                ->where('tenant_id', $operation->tenant_id)
                ->where('legal_entity_id', $operation->legal_entity_id)
                ->whereBetween('work_date', [(string) $filters['start'], (string) $filters['end']])
                ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
                ->count();
            $operation->update(['total_items' => $total, 'processed_items' => 0, 'succeeded_items' => 0]);
            $result = $timesheets->recompute(
                (int) $operation->tenant_id,
                (int) $operation->legal_entity_id,
                (string) $filters['start'],
                (string) $filters['end'],
                $employeeIds,
                function (int $processed, int $updated, int $skipped) use ($operation): void {
                    $operation->update([
                        'processed_items' => $processed,
                        'succeeded_items' => $updated,
                        'failed_items' => $skipped,
                    ]);
                },
                $operation->id,
            );
            $operation->update([
                'status' => 'COMPLETED',
                'total_items' => $result['scanned'],
                'processed_items' => $result['scanned'],
                'succeeded_items' => $result['updated'],
                'result' => $result,
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $operation->update(['status' => 'FAILED', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'completed_at' => now()]);
            throw $exception;
        } finally {
            TenantContext::clear();
        }
    }
}

<?php

namespace App\Jobs;

use App\Services\OvertimeReconciliationService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReconcileOvertimeDay implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $employeeId,
        public readonly string $workDate,
    ) {
        $this->onQueue('attendance');
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->employeeId}:{$this->workDate}";
    }

    public function handle(OvertimeReconciliationService $service): void
    {
        $legalEntityId = DB::table('employees')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->employeeId)
            ->value('legal_entity_id');
        if (! $legalEntityId) {
            return;
        }

        TenantContext::set($this->tenantId, (int) $legalEntityId);
        try {
            $service->reconcileDate($this->tenantId, $this->employeeId, $this->workDate);
        } finally {
            TenantContext::clear();
        }
    }
}

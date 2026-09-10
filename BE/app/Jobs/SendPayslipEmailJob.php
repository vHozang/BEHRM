<?php

namespace App\Jobs;

use App\Models\PayslipDocument;
use App\Services\PayslipDeliveryService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPayslipEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 300;

    public function __construct(
        public int $documentId,
        public int $tenantId,
        public int $legalEntityId,
    ) {}

    public function handle(PayslipDeliveryService $delivery): void
    {
        TenantContext::set($this->tenantId, $this->legalEntityId);
        try {
            $delivery->send(PayslipDocument::findOrFail($this->documentId));
        } finally {
            TenantContext::clear();
        }
    }

    public function uniqueId(): string
    {
        return 'payslip-document:'.$this->documentId;
    }
}

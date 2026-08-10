<?php

namespace App\Jobs;

use App\Services\PayslipDeliveryService;
use App\Services\PayslipDocumentService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishPayslipJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 600;

    public function __construct(
        public int $salaryDetailId,
        public int $tenantId,
        public int $legalEntityId,
        public ?int $publishedBy = null,
    ) {}

    public function handle(PayslipDocumentService $documents, PayslipDeliveryService $delivery): void
    {
        TenantContext::set($this->tenantId, $this->legalEntityId);
        try {
            $document = $documents->generate($this->salaryDetailId, $this->publishedBy);
            if ($document->email_status !== 'SENT') {
                $delivery->send($document);
            }
        } finally {
            TenantContext::clear();
        }
    }

    public function uniqueId(): string
    {
        return 'salary-detail:'.$this->salaryDetailId;
    }
}

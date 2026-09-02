<?php

namespace App\Jobs;

use App\Models\InterviewSchedule;
use App\Models\RecruitmentCandidate;
use App\Services\RecruitmentMailService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendRecruitmentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public string $type,
        public int $candidateId,
        public int $tenantId,
        public ?int $legalEntityId = null,
        public ?int $interviewId = null,
        public ?int $recruiterId = null,
        public array $extra = [],
    ) {}

    public function handle(RecruitmentMailService $mailService): void
    {
        TenantContext::set($this->tenantId, $this->legalEntityId);
        try {
            $candidate = RecruitmentCandidate::with('position:id,position_name')->findOrFail($this->candidateId);

            match ($this->type) {
                'interview_invitation' => $mailService->sendInterviewInvitation(
                    $candidate,
                    InterviewSchedule::findOrFail($this->interviewId),
                    $this->recruiterId,
                ),
                'hired' => $mailService->sendHired(
                    $candidate,
                    $this->extra,
                    $this->recruiterId,
                ),
                'rejected' => $mailService->sendRejected(
                    $candidate,
                    $this->extra['reason'] ?? null,
                    $this->recruiterId,
                ),
                default => null,
            };
        } finally {
            TenantContext::clear();
        }
    }
}

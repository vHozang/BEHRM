<?php

namespace App\Jobs;

use App\Models\InterviewSchedule;
use App\Services\GoogleMeetService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateGoogleMeetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $interviewId,
        public int $candidateId,
        public int $tenantId,
        public ?int $legalEntityId,
        public string $scheduledAt,
        public int $durationMinutes,
        public string $summary,
        public string $description,
        public ?string $attendeeEmail,
        public ?int $recruiterId,
    ) {}

    public function handle(GoogleMeetService $meetService): void
    {
        TenantContext::set($this->tenantId, $this->legalEntityId);
        try {
            $interview = InterviewSchedule::findOrFail($this->interviewId);

            $created = $meetService->createMeeting(
                Carbon::parse($this->scheduledAt),
                $this->durationMinutes,
                $this->summary,
                $this->description,
                $this->attendeeEmail,
            );

            $meta = $interview->meta ?? [];
            $meta['meeting_link'] = $created['meeting_link'];
            $meta['meeting_provider'] = 'GOOGLE_MEET';
            $meta['google_calendar_event_id'] = $created['event_id'];
            $meta['google_calendar_event_url'] = $created['event_url'];
            unset($meta['meeting_status']);
            $interview->update(['meta' => $meta]);

            SendRecruitmentEmailJob::dispatch(
                'interview_invitation',
                $this->candidateId,
                $this->tenantId,
                $this->legalEntityId,
                $this->interviewId,
                $this->recruiterId,
            );
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CreateGoogleMeetJob failed', [
            'interview_id' => $this->interviewId,
            'candidate_id' => $this->candidateId,
            'error' => $exception->getMessage(),
        ]);

        TenantContext::set($this->tenantId, $this->legalEntityId);
        try {
            $interview = InterviewSchedule::find($this->interviewId);
            if ($interview) {
                $meta = $interview->meta ?? [];
                $meta['meeting_status'] = 'FAILED';
                $meta['meeting_error'] = $exception->getMessage();
                $interview->update(['meta' => $meta]);
            }

            SendRecruitmentEmailJob::dispatch(
                'interview_invitation',
                $this->candidateId,
                $this->tenantId,
                $this->legalEntityId,
                $this->interviewId,
                $this->recruiterId,
            );
        } finally {
            TenantContext::clear();
        }
    }
}

<?php

namespace App\Services;

use App\Mail\RecruitmentNotificationMail;
use App\Models\InterviewSchedule;
use App\Models\RecruitmentCandidate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RecruitmentMailService
{
    public function sendApplicationReceived(RecruitmentCandidate $candidate): bool
    {
        return $this->send(
            $candidate,
            RecruitmentNotificationMail::APPLICATION_RECEIVED,
            [
                'response_days' => (int) config('recruitment.mail.application_response_days', 3),
            ],
        );
    }

    public function sendInterviewInvitation(
        RecruitmentCandidate $candidate,
        InterviewSchedule $interview,
        ?int $recruiterId = null,
    ): bool {
        $meta = is_array($interview->meta) ? $interview->meta : [];
        $date = Carbon::parse($interview->interview_date)->format('d/m/Y');
        $time = $interview->interview_time
            ? Carbon::parse($interview->interview_time)->format('H:i')
            : 'Theo lịch đã trao đổi';
        $mode = match (strtoupper((string) $interview->interview_mode)) {
            'ONLINE' => 'Phỏng vấn trực tuyến',
            'HYBRID' => 'Trực tiếp hoặc trực tuyến',
            default => 'Phỏng vấn trực tiếp',
        };

        $confirmationDeadline = $meta['confirmation_deadline'] ?? Carbon::parse($interview->interview_date)
            ->subDay()
            ->format('d/m/Y');

        return $this->send(
            $candidate,
            RecruitmentNotificationMail::INTERVIEW_INVITATION,
            [
                'interview_date' => $date,
                'interview_time' => $time,
                'interview_mode' => $mode,
                'interview_location' => $meta['location'] ?? '',
                'meeting_link' => $meta['meeting_link'] ?? '',
                'interviewer_name' => $meta['interviewer'] ?? $this->employeeName($interview->interviewer_id),
                'duration_minutes' => (int) ($meta['duration_minutes'] ?? config('recruitment.mail.interview_duration_minutes', 60)),
                'confirmation_deadline' => $confirmationDeadline,
                'recruiter_name' => $this->employeeName($recruiterId),
            ],
        );
    }

    public function sendHired(
        RecruitmentCandidate $candidate,
        array $offerDetails,
        ?int $recruiterId = null,
    ): bool {
        return $this->send(
            $candidate,
            RecruitmentNotificationMail::HIRED,
            [
                'start_date' => Carbon::parse($offerDetails['start_date'] ?? now()->addWeekday())->format('d/m/Y'),
                'arrival_time' => $offerDetails['arrival_time'] ?? config('recruitment.mail.default_start_time', '08:30'),
                'work_location' => $offerDetails['work_location'] ?? '',
                'offer_note' => $offerDetails['offer_note'] ?? '',
                'recruiter_name' => $this->employeeName($recruiterId),
            ],
        );
    }

    public function sendRejected(
        RecruitmentCandidate $candidate,
        ?string $reason = null,
        ?int $recruiterId = null,
    ): bool {
        return $this->send(
            $candidate,
            RecruitmentNotificationMail::REJECTED,
            [
                'rejection_reason' => $reason,
                'recruiter_name' => $this->employeeName($recruiterId),
            ],
        );
    }

    private function send(RecruitmentCandidate $candidate, string $type, array $extra): bool
    {
        $mailData = array_merge($this->baseData($candidate), $extra);

        try {
            Mail::to($candidate->email, $candidate->full_name)
                ->send(new RecruitmentNotificationMail($type, $mailData));
            $deliversExternally = ! in_array(config('mail.default'), ['log', 'array'], true);
            $this->recordAttempt($candidate, $type, $deliversExternally ? 'SENT' : 'CAPTURED');

            return $deliversExternally;
        } catch (Throwable $exception) {
            Log::error("Recruitment email failed for candidate #{$candidate->id}", [
                'notification_type' => $type,
                'recipient' => $candidate->email,
                'error' => $exception->getMessage(),
            ]);
            $this->recordAttempt($candidate, $type, 'FAILED');

            return false;
        }
    }

    private function baseData(RecruitmentCandidate $candidate): array
    {
        $position = $candidate->recruitment_position_id
            ? DB::table('recruitment_positions')->where('id', $candidate->recruitment_position_id)->first()
            : null;
        $tenant = $candidate->tenant_id
            ? DB::table('tenants')->where('id', $candidate->tenant_id)->first()
            : null;
        $legalEntity = $candidate->tenant_id
            ? DB::table('legal_entities')->where('tenant_id', $candidate->tenant_id)->orderBy('id')->first()
            : null;

        return [
            'candidate_name' => $candidate->full_name,
            'candidate_email' => $candidate->email,
            'candidate_phone' => $candidate->phone_number,
            'position_name' => $position?->position_name ?: 'vị trí đang tuyển dụng',
            'company_name' => config('recruitment.mail.company_name') ?: $tenant?->name ?: config('app.name', 'HRM System'),
            'company_address' => config('recruitment.mail.company_address') ?: $legalEntity?->address ?: '',
            'company_phone' => config('recruitment.mail.company_phone', ''),
            'company_website' => config('recruitment.mail.website_url', config('app.url')),
            'recruitment_email' => config('recruitment.mail.from_address', 'hr@devtapcode.io.vn'),
            'recruiter_name' => config('recruitment.mail.recruiter_name', 'Bộ phận Tuyển dụng'),
            'recruiter_title' => config('recruitment.mail.recruiter_title', 'HR / Talent Acquisition'),
        ];
    }

    private function employeeName(?int $employeeId): string
    {
        if ($employeeId) {
            $name = DB::table('employees')->where('id', $employeeId)->value('full_name');
            if ($name) {
                return $name;
            }
        }

        return (string) config('recruitment.mail.recruiter_name', 'Bộ phận Tuyển dụng');
    }

    private function recordAttempt(RecruitmentCandidate $candidate, string $type, string $status): void
    {
        try {
            $meta = $candidate->meta ?? [];
            $meta['email_notifications'][$type] = [
                'status' => $status,
                'attempted_at' => now()->toIso8601String(),
            ];
            $candidate->updateQuietly(['meta' => $meta]);
        } catch (Throwable $exception) {
            Log::warning("Could not record recruitment email status for candidate #{$candidate->id}", [
                'notification_type' => $type,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

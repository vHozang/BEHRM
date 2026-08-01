<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleMeetService
{
    public function configured(): bool
    {
        return collect([
            config('services.google_calendar.client_id'),
            config('services.google_calendar.client_secret'),
            config('services.google_calendar.refresh_token'),
            config('services.google_calendar.calendar_id'),
        ])->every(fn ($value) => is_string($value) && trim($value) !== '');
    }

    /**
     * @return array{meeting_link:string,event_id:string,event_url:?string}
     */
    public function createMeeting(
        CarbonInterface $startsAt,
        int $durationMinutes,
        string $summary,
        string $description,
        ?string $attendeeEmail = null,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException(
                'Chưa cấu hình Google Calendar API. Hãy nhập link phòng họp thủ công hoặc cấu hình OAuth cho Google Calendar.'
            );
        }

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'refresh_token' => config('services.google_calendar.refresh_token'),
                'grant_type' => 'refresh_token',
            ])
            ->throw();

        $accessToken = $tokenResponse->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google OAuth không trả về access token hợp lệ.');
        }

        $timezone = (string) config('services.google_calendar.timezone', 'Asia/Ho_Chi_Minh');
        $start = $startsAt->copy()->setTimezone($timezone);
        $end = $start->copy()->addMinutes($durationMinutes);
        $event = [
            'summary' => $summary,
            'description' => $description,
            'start' => [
                'dateTime' => $start->toIso8601String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toIso8601String(),
                'timeZone' => $timezone,
            ],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => (string) Str::uuid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];

        if (is_string($attendeeEmail) && filter_var($attendeeEmail, FILTER_VALIDATE_EMAIL)) {
            $event['attendees'] = [['email' => $attendeeEmail]];
        }

        $calendarId = rawurlencode((string) config('services.google_calendar.calendar_id'));
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post(
                "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events?conferenceDataVersion=1&sendUpdates=none",
                $event,
            )
            ->throw();

        $eventId = $response->json('id');
        if (! is_string($eventId) || $eventId === '') {
            throw new RuntimeException('Google Calendar không trả về mã sự kiện hợp lệ.');
        }

        $meetingLink = $this->extractMeetingLink($response->json());
        // Conference creation can briefly remain pending after the event is inserted.
        for ($attempt = 0; $attempt < 4 && $meetingLink === null; $attempt++) {
            usleep(250_000);
            $eventResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(20)
                ->get(
                    "https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/".rawurlencode($eventId),
                    ['conferenceDataVersion' => 1],
                )
                ->throw();
            $meetingLink = $this->extractMeetingLink($eventResponse->json());
        }

        if (! is_string($meetingLink) || ! $this->isUsableMeetingLink($meetingLink)) {
            throw new RuntimeException('Google Calendar đã tạo sự kiện nhưng không trả về link Google Meet hợp lệ.');
        }

        return [
            'meeting_link' => $meetingLink,
            'event_id' => $eventId,
            'event_url' => is_string($response->json('htmlLink')) ? $response->json('htmlLink') : null,
        ];
    }

    private function extractMeetingLink(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        $meetingLink = $payload['hangoutLink'] ?? null;
        if (is_string($meetingLink) && $this->isUsableMeetingLink($meetingLink)) {
            return $meetingLink;
        }

        $entryPoints = $payload['conferenceData']['entryPoints'] ?? [];
        foreach (is_array($entryPoints) ? $entryPoints : [] as $entryPoint) {
            if (($entryPoint['entryPointType'] ?? null) !== 'video') {
                continue;
            }
            $uri = $entryPoint['uri'] ?? null;
            if (is_string($uri) && $this->isUsableMeetingLink($uri)) {
                return $uri;
            }
        }

        return null;
    }

    public function isUsableMeetingLink(?string $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'meet.google.com') {
            return true;
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/i', $path) === 1
            || str_starts_with(strtolower($path), 'lookup/');
    }
}

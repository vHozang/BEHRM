<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Proxy public CV screening through Laravel so the resume service stays private. */
class AutoRecruitScreeningService
{
    /**
     * @return array{job_id:int|null,candidate:array<string,mixed>}
     */
    public function screen(UploadedFile $file, string $jdText): array
    {
        $url = rtrim((string) config('services.autorecruit.url', 'http://resume-backend:8000'), '/');

        try {
            $response = Http::connectTimeout(5)
                ->timeout(120)
                ->acceptJson()
                ->attach(
                    'file',
                    $file->get(),
                    $file->getClientOriginalName(),
                    ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
                )
                ->post($url.'/screen', ['jd_text' => $jdText]);
        } catch (\Throwable $exception) {
            Log::warning('AutoRecruit screening request failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Dịch vụ chấm CV hiện không khả dụng', 0, $exception);
        }

        if (! $response->successful()) {
            Log::warning('AutoRecruit screening returned an error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            throw new RuntimeException('Dịch vụ chấm CV hiện không khả dụng');
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['candidate'] ?? null)) {
            throw new RuntimeException('Kết quả chấm CV không hợp lệ');
        }

        return [
            'job_id' => isset($payload['job_id']) ? (int) $payload['job_id'] : null,
            'candidate' => $payload['candidate'],
        ];
    }
}

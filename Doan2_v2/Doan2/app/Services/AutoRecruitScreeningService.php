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
        $urls = array_values(array_unique(array_filter(array_map(
            fn ($url) => rtrim((string) $url, '/'),
            array_merge(
                [(string) config('services.autorecruit.url', 'http://resume-backend:8000')],
                (array) config('services.autorecruit.fallback_urls', [])
            )
        ))));
        $lastException = null;

        foreach ($urls as $url) {
            try {
                $response = Http::connectTimeout((int) config('services.autorecruit.connect_timeout', 5))
                    ->timeout((int) config('services.autorecruit.timeout', 120))
                    ->acceptJson()
                    ->attach(
                        'file',
                        $file->get(),
                        $file->getClientOriginalName(),
                        ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream']
                    )
                    ->post($url.'/screen', ['jd_text' => $jdText]);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                Log::warning('AutoRecruit screening request failed', [
                    'endpoint' => $url,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                Log::warning('AutoRecruit screening returned an error', [
                    'endpoint' => $url,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                continue;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['candidate'] ?? null)) {
                Log::warning('AutoRecruit screening returned an invalid payload', ['endpoint' => $url]);

                continue;
            }

            return [
                'job_id' => isset($payload['job_id']) ? (int) $payload['job_id'] : null,
                'candidate' => $payload['candidate'],
            ];
        }

        throw new RuntimeException('Dịch vụ chấm CV hiện không khả dụng', 0, $lastException);
    }
}

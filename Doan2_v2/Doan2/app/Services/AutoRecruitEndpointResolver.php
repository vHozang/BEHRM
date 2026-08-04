<?php

namespace App\Services;

/** Keep every Resume AI integration on the same Mac-first endpoint order. */
final class AutoRecruitEndpointResolver
{
    /** @return list<string> */
    public function urls(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($url) => rtrim(trim((string) $url), '/'),
            array_merge(
                [
                    (string) config('services.autorecruit.mac_url'),
                    (string) config('services.autorecruit.url'),
                ],
                (array) config('services.autorecruit.fallback_urls', [])
            )
        ))));
    }
}

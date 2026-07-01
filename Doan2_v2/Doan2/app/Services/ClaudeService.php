<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Anthropic Claude Messages API.
 *
 * Calls the HTTP endpoint directly via the Laravel Http facade (no SDK).
 * Reads its API key + model + tuning from config('hrm.ai.*'); never hardcodes
 * a key. NEVER throws to the caller — every failure path returns an
 * ['ok' => false, ...] envelope so controllers can degrade gracefully.
 */
class ClaudeService
{
    /**
     * True only when the feature is enabled AND an API key is present.
     */
    public function isConfigured(): bool
    {
        return (bool) config('hrm.ai.enabled', true)
            && filled(config('hrm.ai.api_key'));
    }

    /**
     * Send a chat completion request to the Messages API.
     *
     * @param  array<int, array{role:string, content:string}>  $messages
     * @param  string  $system  System prompt string.
     * @param  array<string, mixed>  $opts  Optional overrides: model, max_tokens, effort.
     * @return array{ok:bool, text:string, usage:array<string,mixed>|null, error:string|null}
     */
    public function chat(array $messages, string $system, array $opts = []): array
    {
        if (! $this->isConfigured()) {
            return $this->fail('AI: chưa được cấu hình');
        }

        $base = rtrim((string) config('hrm.ai.base_url', 'https://api.anthropic.com'), '/');

        // Body per the Claude API contract: NO temperature/top_p/top_k/thinking
        // (claude-opus-4-8 returns HTTP 400 for those).
        $body = [
            'model' => $opts['model'] ?? config('hrm.ai.model', 'claude-opus-4-8'),
            'max_tokens' => (int) ($opts['max_tokens'] ?? config('hrm.ai.max_tokens', 1024)),
            'system' => $system,
            'messages' => $this->normalizeMessages($messages),
            'output_config' => [
                'effort' => $opts['effort'] ?? config('hrm.ai.effort', 'low'),
            ],
        ];

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('hrm.ai.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post($base . '/v1/messages', $body);
        } catch (\Throwable $e) {
            Log::warning('ClaudeService network error', ['error' => $e->getMessage()]);

            return $this->fail('AI: không kết nối được tới dịch vụ, vui lòng thử lại sau');
        }

        if ($response->failed()) {
            $status = $response->status();
            $error = match (true) {
                $status === 401 => 'AI: API key không hợp lệ',
                $status === 429 => 'AI: hệ thống đang quá tải, thử lại sau',
                default => 'AI: dịch vụ tạm thời gián đoạn, vui lòng thử lại sau',
            };

            Log::warning('ClaudeService API error', [
                'status' => $status,
                'body' => $response->json() ?? $response->body(),
            ]);

            return $this->fail($error);
        }

        $json = $response->json();

        // Refusal -> return a safe canned message.
        if (($json['stop_reason'] ?? null) === 'refusal') {
            return [
                'ok' => true,
                'text' => 'Xin lỗi, tôi không thể hỗ trợ yêu cầu này.',
                'usage' => $json['usage'] ?? null,
                'error' => null,
            ];
        }

        $text = $this->extractText($json);

        if ($text === '') {
            return $this->fail('AI: không nhận được nội dung trả lời');
        }

        return [
            'ok' => true,
            'text' => $text,
            'usage' => $json['usage'] ?? null,
            'error' => null,
        ];
    }

    /**
     * Concatenate every content block of type "text".
     *
     * @param  array<string, mixed>  $json
     */
    private function extractText(array $json): string
    {
        $parts = [];

        foreach (($json['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return trim(implode('', $parts));
    }

    /**
     * Keep only valid {role, content} pairs with string content.
     *
     * @param  array<int, mixed>  $messages
     * @return array<int, array{role:string, content:string}>
     */
    private function normalizeMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            if (! is_array($m)) {
                continue;
            }

            $role = $m['role'] ?? null;
            $content = $m['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                continue;
            }

            $out[] = ['role' => $role, 'content' => $content];
        }

        return $out;
    }

    /**
     * @return array{ok:bool, text:string, usage:null, error:string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'text' => '', 'usage' => null, 'error' => $error];
    }
}

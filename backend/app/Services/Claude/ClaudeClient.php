<?php

namespace App\Services\Claude;

use Composer\CaBundle\CaBundle;
use RuntimeException;

/**
 * One request to the Claude messages API.
 * The key stays empty until it is set in the environment.
 */
class ClaudeClient
{
    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    public function messages(array $content, int $maxTokens = 8000): array
    {
        $key = (string) config('claude.claude_api_key');
        if ($key === '') {
            throw new RuntimeException('Липсва CLAUDE_API_KEY. Задайте го и пуснете четенето отново.');
        }

        $payload = json_encode([
            'model' => (string) config('claude.model'),
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Заявката към Claude не се сглоби.');
        }

        $url = (string) config('claude.claude_api_url');
        $retryable = [429, 500, 502, 503, 529];
        $waits = [2, 5, 10];
        $last = 'Claude не отговори.';

        for ($attempt = 0; $attempt <= count($waits); $attempt++) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_CAINFO => CaBundle::getSystemCaRootBundlePath(),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: '.$key,
                    'anthropic-version: 2023-06-01',
                ],
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);

            if ($body === false) {
                $last = 'Няма връзка с Claude: '.$error;
                if (in_array($errno, [60, 77], true)) {
                    throw new RuntimeException($last);
                }
            } elseif ($status >= 200 && $status < 300) {
                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : [];
            } else {
                $last = $this->errorText($status, is_string($body) ? $body : '');
                if (! in_array($status, $retryable, true)) {
                    throw new RuntimeException($last);
                }
            }

            if ($attempt < count($waits)) {
                sleep($waits[$attempt]);
            }
        }

        throw new RuntimeException($last);
    }

    public function textFromResponse(array $response): string
    {
        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }

    private function errorText(int $status, string $body): string
    {
        return match ($status) {
            401 => 'Ключът за Claude е отказан.',
            429 => 'Claude е зает. Опитайте след малко.',
            529 => 'Claude е претоварен. Опитайте след малко.',
            default => 'Claude върна грешка '.$status.'.',
        };
    }
}

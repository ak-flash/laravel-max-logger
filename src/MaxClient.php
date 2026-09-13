<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger;

use AkFlash\MaxLogger\Contracts\MaxClientContract;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

final class MaxClient implements MaxClientContract
{
    public function __construct(
        private readonly Factory $http,
        private readonly array $options,
    ) {}

    public function sendMessage(string $text): bool
    {
        if (trim((string) ($this->options['token'] ?? '')) === ''
            || ! preg_match('/^-?[1-9][0-9]*$/D', (string) ($this->options['recipient_id'] ?? ''))) {
            return false;
        }

        try {
            $type = $this->options['recipient_type'] ?? 'auto';
            $response = $this->post($text, $type === 'user' ? 'user_id' : 'chat_id');

            if ($type === 'auto' && $response->clientError()
                && str_contains($response->body(), 'Unknown recipient')) {
                $response = $this->post($text, 'user_id');
            }

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function post(string $text, string $recipientKey): Response
    {
        $url = rtrim((string) $this->options['api_url'], '/').'/messages?'.http_build_query([
            $recipientKey => $this->options['recipient_id'],
        ]);

        return $this->http
            ->timeout(max(1, (int) $this->options['timeout']))
            ->connectTimeout(max(1, (int) $this->options['connect_timeout']))
            ->withHeaders(['Authorization' => $this->options['token']])
            ->withOptions(['verify' => $this->options['ca_path'] ?: true])
            ->post($url, ['text' => $text]);
    }
}

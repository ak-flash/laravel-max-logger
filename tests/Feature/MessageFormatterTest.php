<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger\Tests\Feature;

use AkFlash\MaxLogger\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MessageFormatterTest extends TestCase
{
    public function test_redacts_nested_secrets_and_token_in_exception_and_message(): void
    {
        Http::fake();
        $this->channel(['context_limit' => 2000])->error('test-token password=hidden user@mail.ru 192.168.0.1', [
            'exception' => new RuntimeException('test-token'),
            'nested' => ['access_token' => 'private-value', 'Authorization' => 'Bearer private-auth'],
            'session' => ['id' => 'private-session'],
            'safe' => 'useful context',
        ]);
        Http::assertSentCount(1);
        $text = Http::recorded()[0][0]['text'];
        foreach (['test-token', 'hidden', 'user@mail.ru', '192.168.0.1', 'private-value', 'private-auth', 'private-session'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertStringContainsString('useful context', $text);
    }

    public function test_handles_recursive_context_and_invalid_utf8_without_serializing_objects(): void
    {
        Http::fake();
        $context = ['invalid' => "\xB1\x31", 'object' => new class
        {
            public function __toString(): string
            {
                throw new RuntimeException('must not be called');
            }
        }];
        $context['recursive'] = &$context;
        $this->channel()->error('Ошибка', $context);
        Http::assertSentCount(1);
        $this->assertTrue(mb_check_encoding(Http::recorded()[0][0]['text'], 'UTF-8'));
    }

    public function test_unicode_message_respects_exact_length_and_keeps_summary(): void
    {
        Http::fake();
        $logger = $this->channel(['message_limit' => 100, 'dedup_ttl' => 60]);
        $message = str_repeat('Ошибка🚨', 1000);
        $logger->error($message);
        $logger->error($message);
        $this->travel(61)->seconds();
        $logger->error($message);
        $text = Http::recorded()[1][0]['text'];
        $this->assertLessThanOrEqual(100, mb_strlen($text));
        $this->assertStringContainsString('duplicates: 1', $text);
    }
}

<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger\Tests\Feature;

use AkFlash\MaxLogger\Contracts\MaxClientContract;
use AkFlash\MaxLogger\MaxHandler;
use AkFlash\MaxLogger\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use RuntimeException;

final class MaxLoggerTest extends TestCase
{
    public function test_sends_exception_with_raw_authorization_and_legacy_recipient(): void
    {
        Http::fake();
        $this->channel(['chat_id' => 987])->error('Ошибка', ['exception' => new RuntimeException('boom')]);

        Http::assertSent(fn ($request) => $request->url() === 'https://platform-api2.max.ru/messages?chat_id=987'
            && $request->hasHeader('Authorization', 'test-token')
            && str_contains($request['text'], 'RuntimeException: boom')
            && str_contains($request['text'], 'https://example.test'));
    }

    public function test_falls_back_only_for_unknown_recipient(): void
    {
        Http::fakeSequence()->push(['message' => 'Unknown recipient'], 400)->push([], 200);
        $this->channel()->error('Ошибка');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'user_id=12345'));
    }

    public function test_explicit_user_recipient_and_custom_transport_options(): void
    {
        $observed = [];
        Http::fake(function ($request, $options) use (&$observed) {
            $observed = $options;

            return Http::response();
        });
        $this->channel([
            'recipient_type' => 'user', 'api_url' => 'https://max.example.test/',
            'timeout' => 4, 'connect_timeout' => 2, 'ca_path' => '/custom/ca.pem',
        ])->error('Ошибка');
        Http::assertSent(fn ($request) => $request->url() === 'https://max.example.test/messages?user_id=12345');
        $this->assertEquals(4, $observed['timeout']);
        $this->assertEquals(2, $observed['connect_timeout']);
        $this->assertSame('/custom/ca.pem', $observed['verify']);
    }

    public function test_system_ca_is_the_default(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertTrue($options['verify']);

            return Http::response();
        });
        $this->channel()->error('Ошибка');
        Http::assertSentCount(1);
    }

    public function test_variable_messages_are_deduplicated_and_counted_after_ttl(): void
    {
        Http::fake();
        $logger = $this->channel(['dedup_ttl' => 60]);
        foreach (['123', '456', 'user8@mail.ru'] as $id) {
            $logger->error("Пользователь {$id} не найден");
        }
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $logger->error('Пользователь 789 не найден');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'duplicates: 2'));
    }

    public function test_rate_limited_message_can_be_sent_after_window_and_reports_suppression(): void
    {
        Http::fake();
        $logger = $this->channel(['rate_limit' => 1, 'rate_window' => 60]);
        $logger->error('alpha');
        $logger->error('bravo');
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $logger->error('bravo');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'bravo') && str_contains($request['text'], 'suppressed: 1'));
    }

    public function test_failed_delivery_does_not_mark_error_as_delivered(): void
    {
        Http::fakeSequence()->push([], 500)->push([], 200);
        $logger = $this->channel();
        $logger->error('same error');
        $logger->error('same error');
        $logger->error('same error');
        Http::assertSentCount(2);
    }

    public function test_failed_delivery_preserves_duplicate_summary(): void
    {
        Http::fakeSequence()->push([], 200)->push([], 500)->push([], 200);
        $logger = $this->channel(['dedup_ttl' => 60]);
        $logger->error('same error');
        $logger->error('same error');
        $this->travel(61)->seconds();
        $logger->error('same error');
        $logger->error('same error');
        Http::assertSentCount(3);
        $this->assertStringContainsString('duplicates: 1', Http::recorded()[2][0]['text']);
    }

    public function test_limits_can_be_disabled_and_level_is_respected(): void
    {
        Http::fake();
        $logger = $this->channel(['dedup_ttl' => 0, 'rate_limit' => 0, 'level' => 'critical']);
        $logger->error('ignored');
        foreach (range(1, 7) as $unused) {
            $logger->critical('same error');
        }
        Http::assertSentCount(7);
        $this->assertSame(Level::Error, MaxHandler::parseLevel('invalid'));
    }

    public function test_missing_credentials_or_disabled_channel_do_not_send(): void
    {
        Http::fake();
        foreach ([['token' => null], ['chat_id' => null], ['recipient_id' => 'abc'], ['enabled' => false]] as $options) {
            $this->channel($options)->error('ignored');
        }
        Http::assertNothingSent();
    }

    public function test_destinations_and_applications_have_independent_limits(): void
    {
        Http::fake();
        $this->channel(['rate_limit' => 1])->error('same error');
        $this->channel(['rate_limit' => 1, 'recipient_id' => 54321], 'other')->error('same error');
        $this->channel(['rate_limit' => 1, 'app_url' => 'https://other.test'], 'third')->error('same error');
        Http::assertSentCount(3);
    }

    public function test_connection_failure_does_not_break_other_stack_handlers(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->channel();
        config(['logging.channels.memory' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        $other = Log::channel('memory')->getLogger()->getHandlers()[0];
        Log::stack(['max', 'memory'])->error('application error');
        $this->assertTrue($other->hasErrorRecords());
    }

    public function test_nested_logging_is_not_recursive(): void
    {
        $calls = 0;
        app()->bind(MaxClientContract::class, function () use (&$calls) {
            return new class($calls) implements MaxClientContract
            {
                public function __construct(private int &$calls) {}

                public function sendMessage(string $text): bool
                {
                    $this->calls++;
                    Log::channel('max')->error('nested error');

                    return true;
                }
            };
        });
        $this->channel()->error('outer error');
        $this->assertSame(1, $calls);
    }

    public function test_busy_destination_lock_prevents_concurrent_delivery(): void
    {
        Http::fake();
        $namespace = 'max-logger:'.hash('sha256', 'https://example.test|testing|test-token|12345|auto');
        $lock = Cache::store()->getStore()->lock($namespace.':lock', 30);
        $this->assertTrue($lock->get());
        try {
            $this->channel()->error('busy');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        Log::channel('max')->error('busy');
        Http::assertSentCount(1);
    }

    public function test_file_cache_supports_deduplication(): void
    {
        Http::fake();
        $logger = $this->channel(['cache_store' => 'file', 'cache_prefix' => 'max-test-'.bin2hex(random_bytes(8))]);
        $logger->error('file cache error');
        $logger->error('file cache error');
        Http::assertSentCount(1);
    }

    public function test_provider_publishes_configuration(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'max-logger-config', '--force' => true])->assertSuccessful();
        $this->assertFileExists(config_path('max-logger.php'));
    }
}

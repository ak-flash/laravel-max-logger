<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger\Tests;

use AkFlash\MaxLogger\MaxLoggerFactory;
use AkFlash\MaxLogger\MaxLoggerServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Log\LoggerInterface;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MaxLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('max-logger.token', 'test-token');
        $app['config']->set('max-logger.recipient_id', '12345');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function channel(array $options = [], string $name = 'max'): LoggerInterface
    {
        config(['logging.channels.'.$name => array_replace([
            'driver' => 'custom',
            'via' => MaxLoggerFactory::class,
        ], $options)]);
        Log::forgetChannel($name);

        return Log::channel($name);
    }
}

<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger;

use AkFlash\MaxLogger\Contracts\MaxClientContract;
use AkFlash\MaxLogger\Contracts\MessageFormatterContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class MaxLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/max-logger.php', 'max-logger');

        $this->app->bind(MaxClientContract::class, function (Application $app, array $parameters) {
            return new MaxClient($app->make(Factory::class), $parameters['options'] ?? $app['config']->get('max-logger'));
        });

        $this->app->bind(MessageFormatterContract::class, function (Application $app, array $parameters) {
            return new MessageFormatter($parameters['options'] ?? array_replace($app['config']->get('max-logger'), [
                'app_url' => $app['config']->get('app.url'),
                'environment' => $app['config']->get('app.env'),
            ]));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/max-logger.php' => $this->app->configPath('max-logger.php'),
            ], 'max-logger-config');
        }
    }
}

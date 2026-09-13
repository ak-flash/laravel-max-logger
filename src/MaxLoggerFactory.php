<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger;

use AkFlash\MaxLogger\Contracts\MaxClientContract;
use AkFlash\MaxLogger\Contracts\MessageFormatterContract;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

final class MaxLoggerFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    public function __invoke(array $config): Logger
    {
        if (array_key_exists('chat_id', $config) && ! array_key_exists('recipient_id', $config)) {
            $config['recipient_id'] = $config['chat_id'];
        }

        $options = array_replace($this->config->get('max-logger', []), $config);
        $logger = new Logger('max');
        if (! ($options['enabled'] ?? true)
            || trim((string) ($options['token'] ?? '')) === ''
            || ! preg_match('/^-?[1-9][0-9]*$/D', (string) ($options['recipient_id'] ?? ''))) {
            return $logger->pushHandler(new NullHandler);
        }

        $options['app_url'] ??= (string) $this->config->get('app.url', '');
        $options['environment'] ??= (string) $this->config->get('app.env', 'production');
        $options['cache_namespace'] = $options['cache_prefix'].':'.hash('sha256', implode('|', [
            $options['app_url'], $options['environment'], $options['token'],
            $options['recipient_id'], $options['recipient_type'],
        ]));

        return $logger->pushHandler(new MaxHandler(
            $this->container->make(MaxClientContract::class, ['options' => $options]),
            $this->container->make(MessageFormatterContract::class, ['options' => $options]),
            $this->container->make(CacheFactory::class)->store($options['cache_store']),
            $options,
        ));
    }
}

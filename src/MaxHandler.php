<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger;

use AkFlash\MaxLogger\Contracts\MaxClientContract;
use AkFlash\MaxLogger\Contracts\MessageFormatterContract;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class MaxHandler extends AbstractProcessingHandler
{
    private bool $sending = false;

    public function __construct(
        private readonly MaxClientContract $client,
        private readonly MessageFormatterContract $messageFormatter,
        private readonly Repository $cache,
        private readonly array $options,
    ) {
        parent::__construct(self::parseLevel((string) $options['level']), true);
    }

    public static function parseLevel(string $level): Level
    {
        foreach (Level::cases() as $candidate) {
            if ($candidate->getName() === strtoupper($level)) {
                return $candidate;
            }
        }

        return Level::Error;
    }

    protected function write(LogRecord $record): void
    {
        if ($this->sending) {
            return;
        }

        $this->sending = true;
        $lock = null;
        $acquired = false;

        try {
            $store = $this->cache->getStore();
            if (! $store instanceof LockProvider) {
                return;
            }

            // One destination lock protects counters and delivery across workers.
            $lock = $store->lock($this->key('lock'), max(30, 2 * (int) $this->options['timeout'] + 10));
            $acquired = $lock->get();
            if (! $acquired) {
                return;
            }

            $fingerprint = $this->fingerprint($record);
            $ttl = max(0, (int) $this->options['dedup_ttl']);
            if ($ttl > 0 && $this->cache->has($this->key('dedup:'.$fingerprint))) {
                $this->increment('duplicates:'.$fingerprint, $ttl + 60);

                return;
            }

            $rateLimit = (int) $this->options['rate_limit'];
            if ($rateLimit > 0 && $this->increment('rate', max(1, (int) $this->options['rate_window'])) > $rateLimit) {
                $this->increment('suppressed', max(3600, (int) $this->options['rate_window'] + 60));

                return;
            }

            $duplicates = (int) $this->cache->get($this->key('duplicates:'.$fingerprint), 0);
            $suppressed = (int) $this->cache->get($this->key('suppressed'), 0);

            if (! $this->client->sendMessage($this->messageFormatter->format($record, $duplicates, $suppressed))) {
                return;
            }

            if ($ttl > 0) {
                $this->cache->put($this->key('dedup:'.$fingerprint), true, $ttl);
            }
            $this->cache->forget($this->key('duplicates:'.$fingerprint));
            $this->cache->forget($this->key('suppressed'));
        } catch (Throwable) {
            // Never report transport/cache failures into the same logging stack.
        } finally {
            try {
                if ($acquired) {
                    $lock?->release();
                }
            } catch (Throwable) {
                // A failed lock release must not replace the application error.
            }
            $this->sending = false;
        }
    }

    private function key(string $suffix): string
    {
        return $this->options['cache_namespace'].':'.$suffix;
    }

    private function increment(string $suffix, int $ttl): int
    {
        $key = $this->key($suffix);
        $this->cache->add($key, 0, $ttl);

        return (int) $this->cache->increment($key);
    }

    private function fingerprint(LogRecord $record): string
    {
        $normalized = preg_replace([
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
            '/\b[0-9a-f]{12,}\b/i',
            '/\d+/',
        ], '<var>', $record->message) ?? $record->message;

        $parts = [$record->level->getName(), trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized)];
        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $parts[] = $exception::class;
            $parts[] = $exception->getFile().':'.$exception->getLine();
        }

        return sha1(implode('|', $parts));
    }
}

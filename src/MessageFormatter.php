<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger;

use AkFlash\MaxLogger\Contracts\MessageFormatterContract;
use Monolog\LogRecord;
use Throwable;

final class MessageFormatter implements MessageFormatterContract
{
    public function __construct(private readonly array $options) {}

    public function format(LogRecord $record, int $duplicates = 0, int $suppressed = 0): string
    {
        $lines = [
            $this->options['app_url'],
            sprintf('🚨 [%s] %s', $this->options['environment'], $record->level->getName()),
            $record->message,
        ];

        $exception = $record->context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $lines[] = $exception::class.': '.$exception->getMessage();
            $lines[] = $exception->getFile().':'.$exception->getLine();
        }

        $context = $record->context;
        unset($context['exception']);
        $context = array_filter($context, static fn ($value) => $value !== null);

        if ($context !== []) {
            $json = json_encode($this->sanitize($context), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $lines[] = $this->limit($this->redact($json ?: ''), (int) $this->options['context_limit']);
        }

        $summary = '';
        if ($duplicates > 0) {
            $summary .= "\nduplicates: {$duplicates}";
        }
        if ($suppressed > 0) {
            $summary .= "\nsuppressed: {$suppressed}";
        }

        $limit = max(1, (int) $this->options['message_limit']);

        return $this->limit(
            $this->limit($this->redact(implode("\n", $lines)), max(0, $limit - mb_strlen($summary))).$summary,
            $limit,
        );
    }

    private function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[depth limit]';
        }

        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 50, true) as $key => $item) {
                $sensitive = false;
                $normalized = strtolower(str_replace(['-', '_'], '', (string) $key));
                foreach ($this->options['redact_keys'] as $pattern) {
                    if ($pattern !== '' && str_contains($normalized, strtolower(str_replace(['-', '_'], '', $pattern)))) {
                        $sensitive = true;
                        break;
                    }
                }
                $result[$key] = $sensitive ? '[redacted]' : $this->sanitize($item, $depth + 1);
            }

            return $result;
        }

        if (is_string($value)) {
            return $this->limit($this->redact($value), (int) $this->options['context_limit']);
        }

        return is_scalar($value) || $value === null ? $value : '['.get_debug_type($value).']';
    }

    private function redact(string $text): string
    {
        $values = array_filter([
            (string) ($this->options['token'] ?? ''),
            ...$this->options['redact_values'],
        ], static fn ($value) => is_string($value) && $value !== '');

        foreach ($values as $value) {
            $text = str_replace([$value, rawurlencode($value), urlencode($value)], '[redacted]', $text);
        }

        $text = preg_replace('/\b(password|token|secret|api[_-]?key|authorization|cookie)\b\s*[:=]\s*(?:Bearer\s+)?(?:"[^"]*"|\x27[^\x27]*\x27|[^\s&,;]+)/i', '$1=[redacted]', $text) ?? $text;
        $text = preg_replace('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', '[email]', $text) ?? $text;

        return preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $text) ?? $text;
    }

    private function limit(string $text, int $length): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, max(0, $length));
    }
}

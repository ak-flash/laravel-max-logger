<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger\Contracts;

use Monolog\LogRecord;

interface MessageFormatterContract
{
    public function format(LogRecord $record, int $duplicates = 0, int $suppressed = 0): string;
}

<?php

declare(strict_types=1);

namespace AkFlash\MaxLogger\Contracts;

interface MaxClientContract
{
    public function sendMessage(string $text): bool;
}

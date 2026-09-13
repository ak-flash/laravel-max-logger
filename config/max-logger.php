<?php

return [
    'enabled' => env('MAX_LOGGER_ENABLED', true),
    'token' => env('MAX_LOGGER_TOKEN', env('MAX_BOT_TOKEN')),
    'recipient_id' => env('MAX_LOGGER_RECIPIENT_ID', env('MAX_CHAT_ID')),
    'recipient_type' => 'auto', // auto, chat, user
    'level' => env('MAX_LOG_LEVEL', 'error'),
    'api_url' => env('MAX_LOGGER_API_URL', 'https://platform-api2.max.ru'),
    'timeout' => (int) env('MAX_LOGGER_TIMEOUT', 10),
    'connect_timeout' => 3,
    'ca_path' => env('MAX_LOGGER_CA_PATH'),
    'dedup_ttl' => (int) env('MAX_LOG_DEDUP_TTL', 600),
    'rate_limit' => (int) env('MAX_LOG_RATE_LIMIT', 5),
    'rate_window' => (int) env('MAX_LOG_RATE_WINDOW', 300),
    'cache_store' => env('MAX_LOGGER_CACHE_STORE'),
    'cache_prefix' => 'max-logger',
    'message_limit' => 3900,
    'context_limit' => 400,
    'redact_keys' => [
        'password', 'token', 'authorization', 'cookie', 'secret',
        'api_key', 'apikey', 'headers', 'session', 'body', 'email', 'phone', 'ip',
    ],
    'redact_values' => [],
];

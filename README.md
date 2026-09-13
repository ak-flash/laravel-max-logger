# Laravel MAX logger

Канал Laravel/Monolog для отправки ошибок в MAX. PHP 8.2+, Laravel 12, Monolog 3.

## Установка

### Через Packagist (после публикации) или VCS

Добавьте в `composer.json` приложения:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ak-flash/laravel-max-logger.git"
        }
    ],
    "require": {
        "ak-flash/laravel-max-logger": "dev-main"
    }
}
```

```bash
composer update ak-flash/laravel-max-logger
```

### Локальная копия пакета

Склонируйте репозиторий, например в `E:\laragon\www\packages\laravel-max-logger`, и подключите по относительному пути:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/laravel-max-logger",
            "options": {
                "symlink": true,
                "versions": {"ak-flash/laravel-max-logger": "dev-main"}
            }
        }
    ],
    "require": {
        "ak-flash/laravel-max-logger": "dev-main"
    }
}
```

```bash
composer update ak-flash/laravel-max-logger
php artisan vendor:publish --tag=max-logger-config
```

Провайдер регистрируется через Laravel auto-discovery. Публикация конфигурации необязательна. Пакет пока не опубликован в Packagist.

В `config/logging.php` добавьте канал:

```php
'max' => [
    'driver' => 'custom',
    'via' => \AkFlash\MaxLogger\MaxLoggerFactory::class,
],
```

В `.env` задайте:

```dotenv
MAX_LOGGER_TOKEN=your-bot-token
MAX_LOGGER_RECIPIENT_ID=12345
MAX_LOG_LEVEL=error
```

Добавьте `max` в список `channels` нужного стека, например `['single', 'max']`.
Репортируемые Laravel исключения и записи `Log::error()` будут проходить через стек.
Для отдельного уведомления: `Log::channel('max')->error('Ошибка обработки', ['job_id' => 123]);`.

## Настройки

Все значения из `config/max-logger.php` можно переопределить в описании канала.

| Ключ | По умолчанию | Назначение |
| --- | --- | --- |
| `enabled` | `true` | Включение канала; env `MAX_LOGGER_ENABLED` |
| `token` | env | `MAX_LOGGER_TOKEN`, fallback `MAX_BOT_TOKEN` |
| `recipient_id` | env | `MAX_LOGGER_RECIPIENT_ID`, fallback `MAX_CHAT_ID`; строковый числовой ID |
| `recipient_type` | `auto` | `chat`, `user` или fallback chat → user при Unknown recipient |
| `level` | `error` | Минимальный уровень; env `MAX_LOG_LEVEL` |
| `api_url` | `https://platform-api2.max.ru` | env `MAX_LOGGER_API_URL` |
| `timeout` / `connect_timeout` | `10` / `3` | Таймауты в секундах |
| `ca_path` | `null` | Системное CA-хранилище или путь к PEM; env `MAX_LOGGER_CA_PATH` |
| `dedup_ttl` | `600` | Срок дедупликации; `0` отключает; env `MAX_LOG_DEDUP_TTL` |
| `rate_limit` / `rate_window` | `5` / `300` | Попытки доставки за окно; `rate_limit=0` отключает |
| `cache_store` | `null` | Cache приложения или именованное хранилище; env `MAX_LOGGER_CACHE_STORE` |
| `cache_prefix` | `max-logger` | Префикс ключей |
| `message_limit` / `context_limit` | `3900` / `400` | Максимальная длина текста и контекста в Unicode-символах |
| `redact_keys` / `redact_values` | см. config / `[]` | Маскируемые ключи и явно заданные секретные строки |
| `app_url` / `environment` | config приложения | Подпись уведомления |

Старый ключ канала `chat_id` поддерживается. Явный `recipient_id` имеет приоритет.
Пустые credentials или `enabled=false` дают `NullHandler`.

## Доставка и cache

Отправка синхронная, без фонового worker. Одна попытка включает максимум два HTTP-запроса при fallback получателя. Автоматических повторов после сетевого сбоя нет: повторная запись ошибки может попробовать доставку заново, в пределах rate limit.

Дедупликация нормализует числа, UUID, email, IPv4 и длинные hex-значения, учитывает уровень и место исключения. Успешная доставка запускает TTL. Сводки `duplicates` и `suppressed` очищаются после успешной отправки. Это best-effort уведомления, без гарантированной очереди доставки.

Хранилище должно поддерживать Laravel `LockProvider`: стандартные array/file/Redis/database поддерживают locks при правильной настройке. На недоступном или неподдерживаемом cache отправка пропускается. Для нескольких серверов используйте общее хранилище, например Redis. Array действует только внутри процесса; file — в пределах общего файлового пути.

Блокировка получателя защищает счётчики и отправку. Конкурирующая запись при занятой блокировке пропускается без ожидания и не входит в сводку `suppressed`. Это ограничивает задержку обработки исходной ошибки. Ключи изолированы по URL приложения, окружению, токену и получателю. Фактические многопроцессные проверки Redis/database ещё предстоят.

## Форматирование и расширение

Сообщение содержит URL приложения, окружение, уровень, текст, класс и место исключения и ограниченный контекст. Вложенные чувствительные ключи маскируются, объекты не сериализуются, глубина и количество элементов ограничены. Токен бота и `redact_values` удаляются из текста, email/IPv4 заменяются. Маскирование не является универсальным распознаванием персональных данных в свободном тексте.

Можно переопределить container binding `MaxClientContract` или `MessageFormatterContract`. При разрешении контракта фабрика передаёт объединённую конфигурацию в параметре `options`. Важно: фабрика вызывает `make()` с параметрами, поэтому контейнер игнорирует `instance()`-биндинги — переопределяйте через `bind()`-closure, при необходимости читая `options` из второго аргумента. Для ручной отправки доступен `app(MaxClientContract::class)->sendMessage('...')`; эта отправка обходит форматирование и лимиты handler.

## Перенос существующей интеграции

1. Подключите Composer dependency и замените import фабрики на `AkFlash\MaxLogger\MaxLoggerFactory`.
2. Существующие token, chat_id, level и лимиты в `logging.channels.max` продолжат работать.
3. Если ранее применялся PEM из приложения, задайте `ca_path` в канале: `env('MAX_LOGGER_CA_PATH', resource_path('certs/russian-trusted-root-ca.pem'))`.
4. Проверьте доставку через нужный стек и обновите долгоживущие worker-процессы штатным способом деплоя.

Авторизация MAX использует собственный сервис приложения. Команда приложения `max:check` проверяет старый клиент; она не является проверкой канала этого пакета.

## Диагностика

Если уведомление отсутствует, проверьте credentials, уровень, состав stack, TTL/лимиты, доступность cache и CA-файл. Транспорт возвращает `false` при ошибке; handler не пишет свои сбои в тот же лог. Для диагностики транспорта используйте контракт клиента и его bool-результат. URL и сырые ответы API с токеном не выводятся.

## Разработка

В каталоге пакета:

```bash
composer install
composer validate --strict
composer test
composer lint
composer analyse
```

Тесты используют Orchestra Testbench, fake HTTP, изолированное приложение и cache. Настроен workflow для PHP 8.2–8.5 и Laravel 12. Чтобы запускать его внутри текущего монорепозитория, есть отдельный workflow приложения; после переноса каталога в самостоятельный репозиторий применяется `.github/workflows/tests.yml` пакета.

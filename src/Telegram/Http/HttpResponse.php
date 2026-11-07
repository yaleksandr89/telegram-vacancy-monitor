<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Http;

/**
 * EN: An HTTP status and response body returned by the transport.
 * RU: HTTP-статус и тело ответа, полученные от транспорта.
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}
}

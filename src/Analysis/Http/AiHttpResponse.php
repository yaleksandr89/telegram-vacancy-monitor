<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis\Http;

/**
 * RU: Передаёт статус и ограниченное по размеру тело ответа транспорта ИИ.
 * EN: Carries the status and bounded response body from the AI transport.
 */
final readonly class AiHttpResponse
{
    public function __construct(public int $statusCode, public string $body) {}
}

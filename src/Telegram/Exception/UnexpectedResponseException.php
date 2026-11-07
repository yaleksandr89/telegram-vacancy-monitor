<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Exception;

use RuntimeException;

/**
 * EN: An invalid or unsupported Bot API response structure.
 * RU: Некорректная или неподдерживаемая структура ответа Bot API.
 */
final class UnexpectedResponseException extends RuntimeException
{
}

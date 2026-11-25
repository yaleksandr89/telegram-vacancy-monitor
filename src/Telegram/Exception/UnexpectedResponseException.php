<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Exception;

use RuntimeException;

/**
 * RU: Некорректная или неподдерживаемая структура ответа Bot API.
 * EN: An invalid or unsupported Bot API response structure.
 */
final class UnexpectedResponseException extends RuntimeException {}

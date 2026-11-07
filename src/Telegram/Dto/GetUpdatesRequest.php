<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Dto;

use InvalidArgumentException;

/**
 * EN: Parameters for receiving pending Bot API updates.
 * RU: Параметры получения ожидающих обновлений Bot API.
 */
final readonly class GetUpdatesRequest
{
    public function __construct(
        public int $offset,
        public int $timeoutSeconds,
    ) {
        if ($offset < 0 || $timeoutSeconds < 0 || $timeoutSeconds > 50) {
            throw new InvalidArgumentException('Недопустимые параметры получения обновлений.');
        }
    }
}

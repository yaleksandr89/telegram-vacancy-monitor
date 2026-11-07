<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Dto;

use InvalidArgumentException;

/**
 * EN: A plain-text reply addressed to a Bot API chat.
 * RU: Текстовый ответ, адресованный чату через Bot API.
 */
final readonly class SendMessageRequest
{
    public function __construct(
        public int $chatId,
        public string $text,
    ) {
        if ($chatId === 0 || trim($text) === '' || mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Недопустимые параметры исходящего сообщения.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Storage;

/**
 * EN: A message ready for persistent storage, with optional verified channel origin.
 * RU: Сообщение для сохранения с необязательным подтверждённым источником-каналом.
 */
final readonly class StoredMessage
{
    public function __construct(
        public int $recipientChatId,
        public int $recipientMessageId,
        public string $originType,
        public ?int $originChatId,
        public ?int $originMessageId,
        public string $body,
    ) {}
}

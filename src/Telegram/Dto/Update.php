<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Dto;

use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * EN: A Bot API update with an optional ordinary message.
 * RU: Обновление Bot API с необязательным обычным сообщением.
 */
final readonly class Update
{
    public function __construct(
        public int $id,
        public ?IncomingMessage $message,
    ) {}

    /**
     * EN: Reject malformed update identifiers rather than losing the processing cursor.
     * RU: Отклоняет некорректный ID обновления, чтобы не потерять позицию обработки.
     *
     * @param array<string, mixed> $payload
     * @throws UnexpectedResponseException
     */
    public static function fromArray(array $payload): self
    {
        if (!is_int($payload['update_id'] ?? null) || $payload['update_id'] < 0) {
            throw new UnexpectedResponseException('Bot API вернул обновление без корректного update_id.');
        }

        $message = $payload['message'] ?? null;

        if ($message !== null && !is_array($message)) {
            throw new UnexpectedResponseException('Bot API вернул некорректный формат сообщения.');
        }

        return new self(
            id: $payload['update_id'],
            message: $message === null ? null : IncomingMessage::fromArray($message),
        );
    }
}

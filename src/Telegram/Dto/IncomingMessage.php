<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Dto;

use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * EN: Only the incoming message fields required by the vacancy inbox.
 * RU: Только поля входящего сообщения, необходимые для хранения вакансий.
 */
final readonly class IncomingMessage
{
    public function __construct(
        public int $chatId,
        public string $chatType,
        public int $messageId,
        public ?int $senderId,
        public ?string $body,
        public string $originType,
        public ?int $originChatId,
        public ?int $originMessageId,
    ) {}

    /**
     * EN: Interpret optional forward metadata without inventing a missing source.
     * RU: Разбирает необязательные данные пересылки, не выдумывая отсутствующий источник.
     *
     * @param array<string, mixed> $payload
     * @throws UnexpectedResponseException
     */
    public static function fromArray(array $payload): self
    {
        $chat = $payload['chat'] ?? null;

        if (
            !is_array($chat)
            || !is_int($chat['id'] ?? null)
            || !is_string($chat['type'] ?? null)
            || !is_int($payload['message_id'] ?? null)
        ) {
            throw new UnexpectedResponseException('Bot API вернул сообщение без обязательных полей.');
        }

        $origin = is_array($payload['forward_origin'] ?? null) ? $payload['forward_origin'] : [];
        $channel = ($origin['type'] ?? null) === 'channel';
        $body = $payload['text'] ?? $payload['caption'] ?? null;

        return new self(
            chatId: $chat['id'],
            chatType: $chat['type'],
            messageId: $payload['message_id'],
            senderId: is_int($payload['from']['id'] ?? null) ? $payload['from']['id'] : null,
            body: is_string($body) ? $body : null,
            originType: is_string($origin['type'] ?? null) ? $origin['type'] : 'direct',
            originChatId: $channel && is_int($origin['chat']['id'] ?? null) ? $origin['chat']['id'] : null,
            originMessageId: $channel && is_int($origin['message_id'] ?? null) ? $origin['message_id'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Dto;

use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * RU: Только поля входящего сообщения, необходимые для хранения вакансий.
 * EN: Only the incoming message fields required by the vacancy inbox.
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
        private bool $plainStartText = false,
    ) {}

    public function isStartCommand(): bool
    {
        return $this->chatType === 'private' && $this->senderId !== null && $this->plainStartText;
    }

    /**
     * RU: Разбирает необязательные данные пересылки, не выдумывая отсутствующий источник.
     * EN: Interpret optional forward metadata without inventing a missing source.
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
            plainStartText: self::isPlainStartText($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function isPlainStartText(array $payload): bool
    {
        if (
            ($payload['text'] ?? null) !== '/start'
            || array_key_exists('forward_origin', $payload)
            || array_key_exists('caption', $payload)
        ) {
            return false;
        }
        if (!array_key_exists('entities', $payload)) {
            return true;
        }

        $entities = $payload['entities'];
        $command = $entities[0] ?? null;
        return is_array($entities) && array_is_list($entities) && count($entities) === 1
            && is_array($command) && ($command['type'] ?? null) === 'bot_command'
            && ($command['offset'] ?? null) === 0 && ($command['length'] ?? null) === 6;
    }
}

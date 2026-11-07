<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram;

use JsonException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\SendMessageRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

/**
 * EN: Typed gateway to the official Bot API; maps JSON into application DTOs.
 * RU: Типизированный шлюз к официальному Bot API; преобразует JSON в DTO приложения.
 */
final readonly class BotApiClient
{
    public function __construct(
        private string $token,
        private HttpTransport $transport,
    ) {}

    /**
     * EN: Returns updates in Bot API order without acknowledging them locally.
     * RU: Возвращает обновления в порядке Bot API без локального подтверждения обработки.
     *
     * @return list<Update>
     * @throws BotApiException
     * @throws BotTransportException
     * @throws UnexpectedResponseException
     */
    public function getUpdates(GetUpdatesRequest $request): array
    {
        $result = $this->request('getUpdates', [
            'offset' => $request->offset,
            'timeout' => $request->timeoutSeconds,
            'limit' => 100,
            'allowed_updates' => ['message'],
        ], $request->timeoutSeconds + 10);

        if (!is_array($result) || !array_is_list($result)) {
            throw new UnexpectedResponseException('Bot API вернул неожиданный список обновлений.');
        }

        $updates = [];

        foreach ($result as $item) {
            if (!is_array($item)) {
                throw new UnexpectedResponseException('Bot API вернул некорректный элемент обновлений.');
            }

            $updates[] = Update::fromArray($item);
        }

        return $updates;
    }

    /**
     * EN: Sends a plain-text confirmation and verifies the returned message object.
     * RU: Отправляет текстовое подтверждение и проверяет объект отправленного сообщения.
     *
     * @throws BotApiException
     * @throws BotTransportException
     * @throws UnexpectedResponseException
     */
    public function sendMessage(SendMessageRequest $message): void
    {
        $result = $this->request('sendMessage', [
            'chat_id' => $message->chatId,
            'text' => $message->text,
        ], 15);

        if (!is_array($result) || !is_int($result['message_id'] ?? null)) {
            throw new UnexpectedResponseException('Bot API вернул некорректное подтверждение отправки.');
        }
    }

    /**
     * EN: This is the only method that builds token-bearing Bot API URLs.
     * RU: Только этот метод формирует URL Bot API, содержащие токен.
     *
     * @param array<string, mixed> $parameters
     * @throws BotApiException
     * @throws BotTransportException
     * @throws UnexpectedResponseException
     */
    private function request(string $method, array $parameters, int $timeoutSeconds): mixed
    {
        try {
            $body = json_encode($parameters, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedResponseException('Не удалось сформировать JSON для Bot API.', previous: $exception);
        }

        $http = $this->transport->postJson(
            'https://api.telegram.org/bot' . $this->token . '/' . $method,
            $body,
            $timeoutSeconds,
        );

        return ApiResponse::fromHttp($http)->result;
    }
}

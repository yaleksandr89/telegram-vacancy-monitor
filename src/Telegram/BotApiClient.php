<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram;

use JsonException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\SendMessageRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\WebhookInfo;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

/**
 * RU: Типизированный шлюз к официальному Bot API; преобразует JSON в DTO приложения.
 * EN: Typed gateway to the official Bot API; maps JSON into application DTOs.
 */
final readonly class BotApiClient
{
    public function __construct(
        private string $token,
        private HttpTransport $transport,
    ) {}

    /**
     * RU: Возвращает обновления в порядке Bot API без локального подтверждения обработки.
     * EN: Returns updates in Bot API order without acknowledging them locally.
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
     * RU: Отправляет текстовое подтверждение и проверяет объект отправленного сообщения.
     * EN: Sends a plain-text confirmation and verifies the returned message object.
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
            'link_preview_options' => ['is_disabled' => true],
        ], 15);

        if (!is_array($result) || !is_int($result['message_id'] ?? null)) {
            throw new UnexpectedResponseException('Bot API вернул некорректное подтверждение отправки.');
        }
    }

    public function setWebhook(string $url, #[\SensitiveParameter] string $secret): void
    {
        $result = $this->request('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
            'max_connections' => 1,
        ], 15);

        if ($result !== true) {
            throw new UnexpectedResponseException('Bot API не подтвердил регистрацию webhook.');
        }
    }

    public function getWebhookInfo(): WebhookInfo
    {
        $result = $this->request('getWebhookInfo', [], 15);

        if (
            !is_array($result)
            || !is_string($result['url'] ?? null)
            || !is_int($result['pending_update_count'] ?? null)
            || $result['pending_update_count'] < 0
        ) {
            throw new UnexpectedResponseException('Bot API вернул некорректный статус webhook.');
        }

        $hasErrorDate = array_key_exists('last_error_date', $result);
        $hasErrorMessage = array_key_exists('last_error_message', $result);
        if (
            ($hasErrorDate && (!is_int($result['last_error_date']) || $result['last_error_date'] < 0))
            || ($hasErrorMessage && !is_string($result['last_error_message']))
        ) {
            throw new UnexpectedResponseException('Bot API вернул некорректные сведения об ошибке доставки webhook.');
        }

        return new WebhookInfo(
            $result['url'] !== '',
            $result['pending_update_count'],
            $hasErrorDate || $hasErrorMessage,
            $result['last_error_date'] ?? null,
        );
    }

    public function deleteWebhook(): void
    {
        $result = $this->request('deleteWebhook', ['drop_pending_updates' => false], 15);

        if ($result !== true) {
            throw new UnexpectedResponseException('Bot API не подтвердил удаление webhook.');
        }
    }

    /**
     * RU: Только этот метод формирует URL Bot API, содержащие токен.
     * EN: This is the only method that builds token-bearing Bot API URLs.
     *
     * @param array<string, mixed> $parameters
     * @throws BotApiException
     * @throws BotTransportException
     * @throws UnexpectedResponseException
     */
    private function request(string $method, array $parameters, int $timeoutSeconds): mixed
    {
        try {
            $body = json_encode((object) $parameters, JSON_THROW_ON_ERROR);
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

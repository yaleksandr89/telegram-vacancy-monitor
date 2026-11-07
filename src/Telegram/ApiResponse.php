<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram;

use JsonException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;

/**
 * EN: Validates the common Bot API response envelope before mapping the result.
 * RU: Проверяет общий формат ответа Bot API перед преобразованием результата.
 */
final readonly class ApiResponse
{
    private function __construct(public mixed $result) {}

    /**
     * EN: Separates remote API rejections from invalid JSON and protocol errors.
     * RU: Отделяет отказ Bot API от некорректного JSON и ошибок протокола.
     *
     * @throws BotApiException
     * @throws UnexpectedResponseException
     */
    public static function fromHttp(HttpResponse $response): self
    {
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedResponseException('Bot API вернул некорректный JSON.', previous: $exception);
        }

        if (!is_array($payload) || !is_bool($payload['ok'] ?? null)) {
            throw new UnexpectedResponseException('Bot API вернул некорректную структуру ответа.');
        }

        if ($payload['ok'] !== true || $response->statusCode < 200 || $response->statusCode >= 300) {
            $apiCode = is_int($payload['error_code'] ?? null) ? $payload['error_code'] : null;
            throw new BotApiException($response->statusCode, $apiCode);
        }

        if (!array_key_exists('result', $payload)) {
            throw new UnexpectedResponseException('Bot API вернул ответ без поля result.');
        }

        return new self($payload['result']);
    }
}

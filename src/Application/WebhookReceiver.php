<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use Closure;
use JsonException;
use Throwable;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;

/**
 * RU: Проверяет HTTP-запрос до разбора JSON и ленивого открытия хранилища.
 * EN: Validates HTTP requests before parsing JSON and lazily opening storage.
 */
final readonly class WebhookReceiver
{
    public const int MAX_BODY_BYTES = 262144;

    /** @param Closure(): IncomingUpdateProcessor $processorFactory */
    public function __construct(private string $secret, private Closure $processorFactory)
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $secret) !== 1) {
            throw new ConfigurationException('Укажите TELEGRAM_WEBHOOK_SECRET: 1–256 символов A–Z, a–z, 0–9, _ или -.');
        }
    }

    /**
     * RU: Возвращает 204 только после коммита; ошибки хранилища допускают повтор доставки.
     * EN: Returns 204 only after commit; storage errors allow redelivery.
     *
     * @param Closure(): string $readBody
     */
    public function receive(string $method, string $contentType, ?string $secret, Closure $readBody): int
    {
        if ($method !== 'POST') {
            return 405;
        }
        if ($secret === null || !hash_equals($this->secret, $secret)) {
            return 403;
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            return 415;
        }

        try {
            $body = $readBody();
            if (strlen($body) > self::MAX_BODY_BYTES) {
                return 413;
            }
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                return 400;
            }
            $update = Update::fromArray($payload);
        } catch (JsonException|UnexpectedResponseException) {
            return 400;
        } catch (Throwable) {
            return 503;
        }

        try {
            ($this->processorFactory)()->process($update, webhook: true);
        } catch (Throwable) {
            error_log('Не удалось сохранить webhook-обновление.');
            return 503;
        }

        return 204;
    }
}

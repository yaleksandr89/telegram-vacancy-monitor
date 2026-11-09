<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis\Http;

use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;

/**
 * RU: Позволяет заменять HTTP-транспорт ИИ детерминированным тестовым транспортом.
 * EN: Allows the AI HTTP transport to be replaced by a deterministic test transport.
 */
interface AiHttpTransport
{
    /**
     * RU: Отправляет JSON, не раскрывая учётные данные и содержимое ответа в ошибках транспорта.
     * EN: Sends JSON without exposing credentials or response contents in transport errors.
     *
     * @throws AiException
     */
    public function postJson(string $url, string $json, ?string $apiKey): AiHttpResponse;
}

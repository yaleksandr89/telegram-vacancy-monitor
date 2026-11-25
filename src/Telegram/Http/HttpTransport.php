<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Http;

use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;

/**
 * RU: Отправляет запросы Bot API и позволяет тестировать код без сети.
 * EN: Sends Bot API requests and allows deterministic tests without network access.
 */
interface HttpTransport
{
    /**
     * RU: Никогда не включать URL в диагностику: он содержит токен бота.
     * EN: Never include the URL in diagnostics: it contains the bot token.
     *
     * @throws BotTransportException
     */
    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse;
}

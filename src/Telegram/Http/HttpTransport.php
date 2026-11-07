<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Http;

use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;

/**
 * EN: Sends Bot API requests and allows deterministic tests without network access.
 * RU: Отправляет запросы Bot API и позволяет тестировать код без сети.
 */
interface HttpTransport
{
    /**
     * EN: Never include the URL in diagnostics: it contains the bot token.
     * RU: Никогда не включать URL в диагностику: он содержит токен бота.
     *
     * @throws BotTransportException
     */
    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse;
}

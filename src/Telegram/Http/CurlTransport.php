<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Http;

use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;

/**
 * EN: HTTPS transport for Bot API requests using the PHP cURL extension.
 * RU: HTTPS-транспорт запросов Bot API через расширение PHP cURL.
 */
final readonly class CurlTransport implements HttpTransport
{
    /**
     * EN: Uses fixed TLS-verifying cURL options and returns the raw HTTP response.
     * RU: Использует постоянные параметры cURL с проверкой TLS и возвращает HTTP-ответ.
     *
     * @throws BotTransportException
     */
    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new BotTransportException('Не удалось создать HTTPS-запрос к Bot API.');
        }

        if (!curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ])) {
            throw new BotTransportException('Не удалось настроить HTTPS-запрос к Bot API.');
        }

        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (!is_string($body)) {
            throw new BotTransportException(sprintf(
                'Сетевая ошибка Bot API (cURL %d). Проверьте соединение и сертификаты.',
                curl_errno($curl),
            ));
        }

        return new HttpResponse((int) $status, $body);
    }
}

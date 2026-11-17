<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis\Http;

use CurlHandle;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;

/**
 * RU: Отправляет ограниченные HTTP-запросы с проверкой TLS, без перенаправлений и прокси.
 * EN: Sends bounded HTTP requests with TLS checks and no redirects or proxy.
 */
final readonly class CurlAiTransport implements AiHttpTransport
{
    public const int DEFAULT_REQUEST_TIMEOUT_SECONDS = 180;

    public function __construct(private int $requestTimeoutSeconds = self::DEFAULT_REQUEST_TIMEOUT_SECONDS) {}

    /**
     * RU: Ограничивает размер ответа и время; при сбое сообщает только безопасные категории для повтора.
     * EN: Enforces response and time limits; reports only safe retry categories on failure.
     *
     * @throws AiException
     */
    public function postJson(string $url, string $json, ?string $apiKey): AiHttpResponse
    {
        if (strlen($json) > 262144) {
            throw new AiException('request_size', false);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new AiException('transport_setup', false);
        }
        $body = '';
        $oversized = false;
        $headers = ['Content-Type: application/json'];
        if ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }
        if (!curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    $oversized = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ])) {
            throw new AiException('transport_setup', false);
        }
        if (curl_exec($curl) === false) {
            $code = curl_errno($curl);
            if ($oversized) {
                throw new AiException('response_size', false);
            }
            $transient = in_array($code, [
                CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT,
                CURLE_SEND_ERROR, CURLE_RECV_ERROR, CURLE_GOT_NOTHING, CURLE_PARTIAL_FILE,
            ], true);
            throw new AiException($code === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport', $transient);
        }
        return new AiHttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body);
    }
}

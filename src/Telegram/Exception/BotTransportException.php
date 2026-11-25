<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Exception;

use RuntimeException;

/**
 * RU: Сетевая ошибка или ошибка TLS до получения корректного ответа Bot API.
 * EN: A network or TLS failure before a valid Bot API response is received.
 */
final class BotTransportException extends RuntimeException {}

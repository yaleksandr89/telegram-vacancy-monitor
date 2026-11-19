<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Exception;

use RuntimeException;

/**
 * RU: Bot API отклонил запрос или вернул неуспешный HTTP-статус.
 * EN: The Bot API rejected a request or returned a non-success HTTP status.
 */
final class BotApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?int $apiCode,
        public readonly ?int $retryAfter = null,
    ) {
        $reason = match ($apiCode ?? $httpStatus) {
            400 => 'Недопустимые параметры запроса.',
            401 => 'Проверьте токен бота.',
            403 => 'Бот не может отправить сообщение в указанный чат.',
            409 => 'Проверьте другой getUpdates-процесс или активный webhook.',
            429 => 'Превышен лимит запросов Bot API.',
            default => 'Запрос отклонён на стороне Bot API.',
        };

        parent::__construct(sprintf(
            'Ошибка Bot API (HTTP %d, API %s). %s',
            $httpStatus,
            $apiCode === null ? 'нет' : (string) $apiCode,
            $reason,
        ));
    }
}

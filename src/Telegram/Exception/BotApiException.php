<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Exception;

use RuntimeException;

/**
 * EN: The Bot API rejected a request or returned a non-success HTTP status.
 * RU: Bot API отклонил запрос или вернул неуспешный HTTP-статус.
 */
final class BotApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?int $apiCode,
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

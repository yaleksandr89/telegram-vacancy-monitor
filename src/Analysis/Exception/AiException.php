<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis\Exception;

use RuntimeException;
use Throwable;

/**
 * RU: Передаёт безопасную категорию ошибки и признак возможности повторной попытки.
 * EN: Carries a safe error category and whether another attempt may help.
 */
final class AiException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Не удалось выполнить анализ вакансии.', previous: $previous);
    }
}

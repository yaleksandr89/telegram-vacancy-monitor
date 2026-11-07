<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application\Exception;

use RuntimeException;

/**
 * EN: A runtime initialization failure, such as an unavailable process lock.
 * RU: Ошибка запуска приложения, например недоступная блокировка процесса.
 */
final class StartupException extends RuntimeException
{
}

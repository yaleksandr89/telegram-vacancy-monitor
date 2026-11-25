<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application\Exception;

use RuntimeException;

/**
 * RU: Ошибка запуска приложения, например недоступная блокировка процесса.
 * EN: A runtime initialization failure, such as an unavailable process lock.
 */
final class StartupException extends RuntimeException {}

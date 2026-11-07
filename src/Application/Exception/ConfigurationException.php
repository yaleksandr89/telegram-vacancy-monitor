<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application\Exception;

use RuntimeException;

/**
 * EN: A missing or invalid local application configuration value.
 * RU: Отсутствующий или некорректный параметр локальной конфигурации приложения.
 */
final class ConfigurationException extends RuntimeException
{
}

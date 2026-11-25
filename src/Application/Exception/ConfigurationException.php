<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application\Exception;

use RuntimeException;

/**
 * RU: Отсутствующий или некорректный параметр локальной конфигурации приложения.
 * EN: A missing or invalid local application configuration value.
 */
final class ConfigurationException extends RuntimeException {}

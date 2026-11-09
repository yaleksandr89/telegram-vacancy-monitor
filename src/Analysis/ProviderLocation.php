<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;

/**
 * RU: Явно выбирает правила проверки локального или облачного подключения.
 * EN: Selects local or cloud network validation explicitly.
 */
enum ProviderLocation: string
{
    case Local = 'local';
    case Cloud = 'cloud';

    /**
     * RU: Отклоняет неизвестные значения INI до отправки запросов.
     * EN: Rejects unknown INI values before sending any request.
     *
     * @throws ConfigurationException
     */
    public static function fromConfig(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new ConfigurationException('Укажите AI_PROVIDER_LOCATION: local или cloud.');
    }
}

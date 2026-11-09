<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

/**
 * RU: Определяет допустимые приоритеты уведомления.
 * EN: Defines the supported notification priorities.
 */
enum Priority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}

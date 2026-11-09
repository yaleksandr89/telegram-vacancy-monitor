<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

/**
 * RU: Определяет три допустимых решения об отклике на вакансию.
 * EN: Defines the three supported decisions about applying for a vacancy.
 */
enum Verdict: string
{
    case Apply = 'apply';
    case Borderline = 'borderline';
    case Skip = 'skip';
}

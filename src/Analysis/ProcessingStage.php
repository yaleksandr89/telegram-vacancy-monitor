<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

/**
 * RU: Задаёт названия независимо повторяемых этапов анализа и доставки.
 * EN: Names the independently retried analysis and delivery stages.
 */
enum ProcessingStage: string
{
    case Analysis = 'analysis';
    case Delivery = 'delivery';
}

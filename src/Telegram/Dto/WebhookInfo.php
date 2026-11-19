<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Dto;

final readonly class WebhookInfo
{
    public function __construct(
        public bool $configured,
        public int $pendingUpdateCount,
        public bool $hasDeliveryError,
        public ?int $lastErrorDate,
    ) {}
}

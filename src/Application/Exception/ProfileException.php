<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application\Exception;

use RuntimeException;

/**
 * RU: Сообщает о непригодном локальном профиле или инструкциях, не раскрывая содержимое.
 * EN: Reports unusable local profile or instructions without revealing their contents.
 */
final class ProfileException extends RuntimeException
{
}

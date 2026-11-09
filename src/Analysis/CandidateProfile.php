<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ProfileException;

/**
 * RU: Проверяет сведения о кандидате и определяет их версию для оценки.
 * EN: Validates and versions candidate information for evaluation.
 */
final readonly class CandidateProfile
{
    public const int MAX_BYTES = 32768;
    public const int MAX_CHARACTERS = 16000;

    public string $text;
    public string $fingerprint;

    /**
     * RU: Проверяет исходный размер, UTF-8 и управляющие символы перед приёмом непустого Markdown.
     * EN: Checks original size, UTF-8 and controls before accepting nonempty Markdown.
     *
     * @throws ProfileException
     */
    public function __construct(string $text)
    {
        // RU: Проверяем исходные байты до обрезки пробелов, чтобы не потерять NUL в конце.
        // RU: Разрешаем табуляцию и переводы строк, но отклоняем остальные управляющие символы Unicode.
        // EN: Check raw bytes before trimming so a trailing NUL cannot disappear.
        // EN: Permit tabs and line breaks, but reject the remaining Unicode control characters.
        if (
            strlen($text) > self::MAX_BYTES || !mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{009F}]/u', $text) === 1
        ) {
            throw new ProfileException('Профиль должен быть UTF-8 без управляющих символов, не более 32768 байт.');
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_CHARACTERS) {
            throw new ProfileException('Профиль не должен превышать 16000 символов.');
        }
        // RU: Учитываем исходный файл в версии, даже если нормализация не меняет текст запроса.
        // EN: Version the original file even when normalization leaves the request text unchanged.
        $fingerprint = hash('sha256', $text);
        $text = mb_trim(str_replace(["\r\n", "\r"], "\n", $text), encoding: 'UTF-8');
        if ($text === '') {
            throw new ProfileException('Профиль должен быть непустым.');
        }
        $this->text = $text;
        $this->fingerprint = $fingerprint;
    }
}

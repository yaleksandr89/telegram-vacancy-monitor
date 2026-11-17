<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ProfileException;

/**
 * RU: Читает локальный профиль с ограничением размера, не раскрывая содержимое в ошибках.
 * EN: Reads a bounded local candidate profile without exposing its contents in errors.
 */
final readonly class CandidateProfileLoader
{
    /**
     * RU: Требует обычный читаемый файл; отклоняет некорректный или слишком большой текст.
     * EN: Requires a regular readable file; rejects invalid or oversized text.
     *
     * @throws ProfileException
     */
    public function load(string $path): CandidateProfile
    {
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            throw new ProfileException('Недоступен обычный файл профиля кандидата.');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new ProfileException('Не удалось открыть профиль кандидата.');
        }
        try {
            // RU: Читаем на один байт больше ограничения, чтобы обнаружить превышение без чтения всего файла.
            // EN: Read one byte above the limit to detect oversize without an unbounded read.
            $text = stream_get_contents($handle, CandidateProfile::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($text)) {
            throw new ProfileException('Не удалось прочитать профиль кандидата.');
        }

        return new CandidateProfile($text);
    }
}

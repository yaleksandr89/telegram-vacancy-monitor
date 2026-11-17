<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ProfileException;

/**
 * RU: Читает обязательные локальные инструкции с ограничением объёма чтения.
 * EN: Reads required local instructions with a bounded file read.
 */
final readonly class EvaluationInstructionsLoader
{
    /**
     * RU: Требует обычный читаемый файл; отклоняет некорректный или слишком большой текст.
     * EN: Requires a regular readable file; rejects invalid or oversized text.
     *
     * @throws ProfileException
     */
    public function load(string $path): EvaluationInstructions
    {
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            throw new ProfileException('Недоступен обычный файл инструкций анализа.');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new ProfileException('Не удалось открыть инструкции анализа.');
        }
        try {
            // RU: Читаем на один байт больше ограничения, чтобы обнаружить превышение без чтения всего файла.
            // EN: Read one byte above the limit to detect oversize without an unbounded read.
            $text = stream_get_contents($handle, EvaluationInstructions::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($text)) {
            throw new ProfileException('Не удалось прочитать инструкции анализа.');
        }

        return new EvaluationInstructions($text);
    }
}

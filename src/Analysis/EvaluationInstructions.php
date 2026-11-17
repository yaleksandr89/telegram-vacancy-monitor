<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ProfileException;

/**
 * RU: Проверяет инструкции оператора и сохраняет их точный текст для запроса.
 * EN: Validates operator instructions and preserves their exact text for the request.
 */
final readonly class EvaluationInstructions
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
        // RU: Табуляция и переводы строк разрешены; остальные управляющие символы Unicode запрещены.
        // EN: Check raw bytes before trimming so a trailing NUL cannot disappear.
        // EN: Tabs and line breaks are allowed; other Unicode control characters are rejected.
        if (
            strlen($text) > self::MAX_BYTES || !mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{009F}]/u', $text) === 1
        ) {
            throw new ProfileException('Инструкции должны быть UTF-8 без управляющих символов, не более 32768 байт.');
        }
        $trimmed = mb_trim($text, encoding: 'UTF-8');
        if ($trimmed === '' || mb_strlen($text, 'UTF-8') > self::MAX_CHARACTERS) {
            throw new ProfileException('Инструкции должны быть непустыми и не превышать 16000 символов.');
        }
        $this->text = $text;
        $this->fingerprint = hash('sha256', $text);
    }
}

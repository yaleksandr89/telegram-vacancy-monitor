<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Application;

use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResult;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Priority;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Verdict;

/**
 * RU: Формирует ограниченные текстовые уведомления и отличает подтверждённые ID источника от ссылок в тексте.
 * EN: Formats bounded plain-text notifications and distinguishes verified source IDs from text links.
 */
final readonly class VacancyResultFormatter
{
    public const int SOURCE_LIMIT = 768;
    private const int MESSAGE_LIMIT = 4096;

    /**
     * RU: Сокращает только второстепенные списки; сохраняет вердикт, основные условия и строку источника.
     * EN: Shortens optional lists only; preserves verdict, core conditions and the source footer.
     */
    public function format(?AnalysisResult $result, int $messageId, ?string $rejection = null, ?string $source = null): string
    {
        $lines = [];
        $sections = [];
        if ($result !== null) {
            $lines[] = match ($result->verdict) {
                Verdict::Apply => 'Откликаться', Verdict::Borderline => 'Погранично', Verdict::Skip => 'Пропустить',
            };
            $lines[] = 'Приоритет: ' . match ($result->priority) {
                Priority::High => 'высокий', Priority::Medium => 'средний', Priority::Low => 'низкий',
            };
            $lines[] = $result->company . ' — ' . $result->position;
            $lines[] = $result->rationale;
            $lines[] = 'География и формат: ' . $result->geography;
            $lines[] = 'Оплата: ' . $result->pay;
            foreach (['matches' => 'Совпадения', 'risks' => 'Риски', 'missing' => 'Уточнить'] as $property => $label) {
                if ($result->$property !== []) {
                    $sections[$label] = $result->$property;
                }
            }
        } else {
            $lines[] = $rejection === null ? 'Не удалось обработать' : 'Пропустить';
            $lines[] = $rejection ?? 'Техническая ошибка анализа. Исходная вакансия сохранена.';
        }
        $footer = ['Источник: ' . ($source ?? 'ссылка недоступна'), 'Сообщение в чате: ' . $messageId];
        $sectionLines = [];
        foreach ($sections as $label => $items) {
            $sectionLines[] = $label . ': ' . implode('; ', $items);
        }
        // RU: Сохраняем основные поля и источник целиком; оставшееся место делим между второстепенными списками.
        // EN: Keep core fields and the source intact; share the remaining space among optional lists.
        if ($sections !== [] && $this->telegramLength(implode("\n", [...$lines, ...$sectionLines, ...$footer])) > self::MESSAGE_LIMIT) {
            $budget = intdiv(self::MESSAGE_LIMIT - $this->telegramLength(implode("\n", [...$lines, ...$footer])), count($sections)) - 1;
            $sectionLines = [];
            foreach ($sections as $label => $items) {
                $heading = $label . ' (сокращено): ';
                $itemBudget = intdiv($budget - $this->telegramLength($heading) - 2 * (count($items) - 1), count($items));
                $sectionLines[] = $heading . implode('; ', array_map(fn (string $item): string => $this->shorten($item, $itemBudget), $items));
            }
        }

        return implode("\n", [...$lines, ...$sectionLines, ...$footer]);
    }

    private function shorten(string $text, int $budget): string
    {
        if ($this->telegramLength($text) <= $budget) {
            return $text;
        }
        while ($this->telegramLength($text) > $budget - 1) {
            $text = mb_substr($text, 0, -1, 'UTF-8');
        }

        return $text . '…';
    }

    private function telegramLength(string $text): int
    {
        // RU: Некоторые эмодзи занимают две позиции при подсчёте длины сообщения; учитываем это перед отправкой.
        // RU: Преобразование в UTF-16 позволяет посчитать эти позиции, не разрезая символы.
        // EN: Some emoji take two positions in message length; count them before sending.
        // EN: UTF-16 conversion counts those positions without splitting characters.
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * RU: Помечает готовую безопасную ссылку в зависимости от совпадения её ID с данными пересылки.
     * EN: Labels an existing safe link according to whether its IDs match forwarded metadata.
     */
    public function sourceDescription(array $job): ?string
    {
        $verified = $this->verifiedSource($job);
        if ($verified !== null) {
            return $verified . ' (совпадает с ID пересланной публикации)';
        }
        $url = $this->textLinks($job['body'])[0] ?? null;

        return $url === null ? null : $url . ' — ссылка из объявления (не проверена)';
    }

    /** @return list<string> */
    private function textLinks(string $text): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return [];
        }
        preg_match_all('~(?<![\w/@])https?://[^\s<>"]+~iu', $text, $matches);
        $links = [];
        foreach ($matches[0] as $match) {
            $url = rtrim($match, '.,;!)]}');
            if (
                strlen($url) > self::SOURCE_LIMIT || !filter_var($url, FILTER_VALIDATE_URL)
                || preg_match('/[^\x21-\x7E]|\\\\|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) === 1
            ) {
                continue;
            }
            $parts = parse_url($url);
            if (
                !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
                || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            ) {
                continue;
            }
            $links[] = $url;
        }

        return $links;
    }

    /**
     * RU: Возвращает только готовую ссылку, совпавшую с подтверждёнными ID; не придумывает адрес источника.
     * EN: Returns only an existing link matching confirmed origin IDs; never invents a source URL.
     */
    public function verifiedSource(array $job): ?string
    {
        if (($job['origin_type'] ?? null) !== 'channel' || !isset($job['origin_chat_id'], $job['origin_message_id'])) {
            return null;
        }
        $chatId = (int) $job['origin_chat_id'];
        if ($chatId > -1000000000001 || $chatId < -1997852516352) {
            return null;
        }
        // RU: Сверяем готовую ссылку по документированному преобразованию ID канала Bot API; URL не создаём.
        // EN: Compare an existing link with the documented Bot API channel-ID conversion; never build a URL.
        $channelId = (string) (-$chatId - 1000000000000);
        foreach ($this->textLinks($job['body']) as $url) {
            if ($url === 'https://t.me/c/' . $channelId . '/' . $job['origin_message_id']) {
                return $url;
            }
        }
        return null;
    }
}

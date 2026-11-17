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
     * RU: Сохраняет полный текст, если он помещается; иначе сокращает представление с неизменным источником.
     * EN: Keeps the full text when it fits; otherwise compacts presentation with an unchanged source.
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
        $full = implode("\n", [...$lines, ...$sectionLines, ...$footer]);
        if ($result === null || $this->telegramLength($full) <= self::MESSAGE_LIMIT) {
            return $full;
        }

        $fields = [
            'Компания: ' => $result->company,
            'Позиция: ' => $result->position,
            'Обоснование: ' => $result->rationale,
            'География и формат: ' => $result->geography,
            'Оплата: ' => $result->pay,
        ];
        foreach ($sections as $label => $items) {
            $fields[$label . ': '] = implode('; ', $items);
        }
        $header = [$lines[0], $lines[1], '(сокращено для Telegram)'];
        $fixed = implode("\n", [...$header, ...array_keys($fields), ...$footer]);
        $remaining = self::MESSAGE_LIMIT - $this->telegramLength($fixed);
        $lengths = array_map($this->telegramLength(...), $fields);
        $budgets = [];
        foreach ($lengths as $label => $length) {
            $budgets[$label] = min(120, $length);
            $remaining -= $budgets[$label];
        }
        // RU: Каждому разделу оставляем место; остаток отдаём прежде всего обоснованию и условиям.
        // RU: Число проходов ограничено бюджетом сообщения, а не длиной входного текста.
        // EN: Reserve room for every section; favor rationale and conditions with the remaining space.
        // EN: The message budget bounds the number of passes, regardless of input length.
        $weights = ['Обоснование: ' => 4, 'География и формат: ' => 3, 'Оплата: ' => 3];
        do {
            $allocated = 0;
            foreach ($lengths as $label => $length) {
                $extra = min($remaining, $length - $budgets[$label], $weights[$label] ?? 1);
                $budgets[$label] += $extra;
                $remaining -= $extra;
                $allocated += $extra;
            }
        } while ($remaining > 0 && $allocated > 0);
        $compact = [];
        foreach ($fields as $label => $text) {
            $compact[] = $label . $this->shorten($text, $budgets[$label]);
        }

        return implode("\n", [...$header, ...$compact, ...$footer]);
    }

    private function shorten(string $text, int $budget): string
    {
        if ($this->telegramLength($text) <= $budget) {
            return $text;
        }
        $prefix = '';
        $used = 0;
        foreach (mb_str_split(mb_substr($text, 0, $budget - 1, 'UTF-8'), 1, 'UTF-8') as $character) {
            $units = strlen($character) === 4 ? 2 : 1;
            if ($used + $units > $budget - 1) {
                break;
            }
            $prefix .= $character;
            $used += $units;
        }

        return $prefix . '…';
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

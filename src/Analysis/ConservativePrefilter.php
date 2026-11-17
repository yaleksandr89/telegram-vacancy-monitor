<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;

/**
 * RU: Отклоняет только полные совпадения сообщений без настроенных слов неоднозначности.
 * EN: Rejects only whole-message matches without configured ambiguity terms.
 */
final readonly class ConservativePrefilter
{
    /**
     * RU: Принимает буквальные однострочные правила, не выполняя и не интерпретируя их.
     * EN: Accepts literal single-line rules without executing or interpreting them.
     *
     * @param list<string> $exactRejectLines
     * @param list<string> $ambiguityTerms
     * @throws ConfigurationException
     */
    public function __construct(private array $exactRejectLines = [], private array $ambiguityTerms = [])
    {
        // RU: Управляющие символы, невидимое форматирование и разделители строк недопустимы в однострочном правиле.
        // EN: Controls, invisible formatting and line separators cannot form a safe single-line rule.
        foreach ([$exactRejectLines, $ambiguityTerms] as $rules) {
            if (!array_is_list($rules)) {
                throw new ConfigurationException('Правила предфильтра должны быть списком строк INI.');
            }
            foreach ($rules as $rule) {
                if (
                    !is_string($rule) || !mb_check_encoding($rule, 'UTF-8') || mb_trim($rule, encoding: 'UTF-8') === ''
                    || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $rule) === 1
                ) {
                    throw new ConfigurationException('Правило предфильтра должно быть непустой строкой UTF-8 без управляющих символов.');
                }
            }
        }
    }

    /**
     * RU: Возвращает причину отказа только при однозначном полном совпадении сообщения.
     * EN: Returns a reason only for an unambiguous full-message match.
     */
    public function reject(string $text): ?string
    {
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $text) === 1) {
            return null;
        }
        $text = mb_trim($text, encoding: 'UTF-8');
        foreach ($this->ambiguityTerms as $term) {
            if (mb_stripos($text, $term, encoding: 'UTF-8') !== false) {
                return null;
            }
        }
        // RU: Сверяем только всё сообщение; дополнительный контекст всегда передаётся оценщику.
        // EN: Match only the entire message; extra context always belongs to the evaluator.
        foreach ($this->exactRejectLines as $rule) {
            if ($text === mb_trim($rule, encoding: 'UTF-8')) {
                return 'Всё сообщение точно совпало с настроенным оператором правилом исключения.';
            }
        }
        return null;
    }
}

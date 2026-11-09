<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use JsonException;
use stdClass;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;

/**
 * RU: Отклоняет ответы модели, не соответствующие фиксированному формату оценки вакансии.
 * EN: Rejects model output outside the fixed vacancy result contract.
 */
final readonly class AnalysisResultValidator
{
    /**
     * RU: Проверяет каждое поле и отклоняет лишние ключи, ссылки, разметку и управляющие символы.
     * EN: Validates every field and rejects extra keys, links, markup and control characters.
     *
     * @throws AiException
     */
    public function validate(string $json): AnalysisResult
    {
        try {
            $object = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiException('invalid_output', true, $exception);
        }
        $enums = AnalysisResult::enumValues();
        $keys = [...array_keys(AnalysisResult::TEXT_LIMITS), ...AnalysisResult::LIST_FIELDS, ...array_keys($enums)];
        if (!$object instanceof stdClass) {
            throw new AiException('invalid_output', true);
        }
        $data = get_object_vars($object);
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new AiException('invalid_output', true);
        }
        foreach ($keys as $key) {
            if (in_array($key, AnalysisResult::LIST_FIELDS, true)) {
                if (!is_array($data[$key]) || !array_is_list($data[$key]) || count($data[$key]) > AnalysisResult::LIST_LIMIT) {
                    throw new AiException('invalid_output', true);
                }
                foreach ($data[$key] as $item) {
                    $this->checkText($item, AnalysisResult::LIST_ITEM_LIMIT);
                }
            } elseif (isset(AnalysisResult::TEXT_LIMITS[$key])) {
                $this->checkText($data[$key], AnalysisResult::TEXT_LIMITS[$key]);
            } elseif (!is_string($data[$key]) || !in_array($data[$key], $enums[$key], true)) {
                throw new AiException('invalid_output', true);
            }
        }

        return new AnalysisResult(
            $data['company'], $data['position'], Verdict::from($data['verdict']), Priority::from($data['priority']),
            $data['matches'], $data['risks'], $data['geography'], $data['pay'], $data['missing'], $data['rationale'],
        );
    }

    private function checkText(mixed $value, int $limit): void
    {
        if (
            !is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_trim($value, encoding: 'UTF-8') === ''
            || mb_strlen($value, 'UTF-8') > $limit
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|https?:\/\/|<[^>]*>/iu', $value) === 1
        ) {
            throw new AiException('invalid_output', true);
        }
    }
}

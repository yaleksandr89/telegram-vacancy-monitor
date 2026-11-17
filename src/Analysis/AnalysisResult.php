<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use JsonException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;

/**
 * RU: Хранит полную типизированную оценку вакансии и схему ответа.
 * EN: Holds the complete typed vacancy evaluation and output schema.
 */
final readonly class AnalysisResult
{
    public const array TEXT_FIELDS = ['company', 'position', 'geography', 'pay', 'rationale'];
    public const array LIST_FIELDS = ['matches', 'risks', 'missing'];

    /**
     * RU: Строит машиночитаемую схему из тех же полей и типов, которые использует проверка.
     * EN: Builds a machine-readable contract from the same fields and types used by validation.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $properties = [];
        foreach (self::TEXT_FIELDS as $field) {
            $properties[$field] = ['type' => 'string', 'minLength' => 1];
        }
        foreach (self::LIST_FIELDS as $field) {
            $properties[$field] = [
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 1],
            ];
        }
        foreach (self::enumValues() as $field => $values) {
            $properties[$field] = ['type' => 'string', 'enum' => $values];
        }

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => array_keys($properties), 'properties' => $properties,
        ];
    }

    /** @return array<string, list<string>> */
    public static function enumValues(): array
    {
        return [
            'verdict' => array_column(Verdict::cases(), 'value'),
            'priority' => array_column(Priority::cases(), 'value'),
            'source_status' => ['unavailable'],
        ];
    }

    /**
     * @param list<string> $matches
     * @param list<string> $risks
     * @param list<string> $missing
     */
    public function __construct(
        public string $company,
        public string $position,
        public Verdict $verdict,
        public Priority $priority,
        public array $matches,
        public array $risks,
        public string $geography,
        public string $pay,
        public array $missing,
        public string $rationale,
    ) {}

    /**
     * RU: Сохраняет результат со статусом источника, определённым приложением, а не моделью.
     * EN: Saves the result with source status determined by the application, never by the model.
     *
     * @throws AiException
     */
    public function toJson(bool $verifiedOriginIds = false): string
    {
        try {
            return json_encode([
                'company' => $this->company,
                'position' => $this->position,
                'verdict' => $this->verdict->value,
                'priority' => $this->priority->value,
                'matches' => $this->matches,
                'risks' => $this->risks,
                'geography' => $this->geography,
                'pay' => $this->pay,
                'missing' => $this->missing,
                'rationale' => $this->rationale,
                'source_status' => $verifiedOriginIds ? 'verified_origin_ids' : 'unavailable',
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiException('serialization', false, $exception);
        }
    }
}

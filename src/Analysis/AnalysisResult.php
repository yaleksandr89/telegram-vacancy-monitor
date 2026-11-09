<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use JsonException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;

/**
 * RU: Хранит типизированную оценку вакансии и общие ограничения ответа.
 * EN: Holds the typed vacancy evaluation and shared output limits.
 */
final readonly class AnalysisResult
{
    // RU: Ограничиваем длину текстовых полей, чтобы итоговый ответ помещался в сообщение Телеграмма.
    // EN: Limit text fields so the final response fits in a Telegram message.
    public const int LABEL_LIMIT = 120;
    public const int CONDITION_LIMIT = 240;
    public const int RATIONALE_LIMIT = 320;
    public const int LIST_ITEM_LIMIT = 120;
    public const int LIST_LIMIT = 4;
    public const array TEXT_LIMITS = [
        'company' => self::LABEL_LIMIT,
        'position' => self::LABEL_LIMIT,
        'geography' => self::CONDITION_LIMIT,
        'pay' => self::CONDITION_LIMIT,
        'rationale' => self::RATIONALE_LIMIT,
    ];
    public const array LIST_FIELDS = ['matches', 'risks', 'missing'];

    /**
     * RU: Строит машиночитаемую схему из тех же ограничений, которые использует проверка.
     * EN: Builds a machine-readable contract from the same limits used by validation.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $properties = [];
        foreach (self::TEXT_LIMITS as $field => $limit) {
            $properties[$field] = ['type' => 'string', 'minLength' => 1, 'maxLength' => $limit];
        }
        foreach (self::LIST_FIELDS as $field) {
            $properties[$field] = [
                'type' => 'array', 'maxItems' => self::LIST_LIMIT,
                'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::LIST_ITEM_LIMIT],
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

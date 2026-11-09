<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use JsonException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;

/**
 * RU: Разделяет инструкции оператора, схему ответа, профиль и недоверенный текст вакансии.
 * EN: Separates operator instructions, output schema, profile and untrusted vacancy data.
 */
final readonly class EvaluationPromptBuilder
{
    public function __construct(private EvaluationInstructions $instructions) {}

    /**
     * RU: Учитывает обе составляющие задания в версии оценки; сохранённые результаты не меняются.
     * EN: Versions both evaluation inputs; existing results remain unchanged.
     */
    public function fingerprint(CandidateProfile $profile): string
    {
        return hash('sha256', $profile->fingerprint . ':' . $this->instructions->fingerprint);
    }

    /**
     * RU: Кодирует отдельно инструкции, схему и недоверенные данные без метаданных чата.
     * EN: Encodes separate instructions, schema and untrusted data without chat metadata.
     *
     * @throws AiException
     */
    public function build(string $model, string $vacancy, CandidateProfile $profile): string
    {
        try {
            return json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->instructions->text . "\n\n```json\n" . json_encode([
                        'output_schema' => AnalysisResult::jsonSchema(),
                    ], JSON_THROW_ON_ERROR) . "\n```"],
                    ['role' => 'user', 'content' => json_encode([
                        'candidate_profile' => $profile->text,
                        'untrusted_vacancy_text' => $vacancy,
                    ], JSON_THROW_ON_ERROR)],
                ],
                'stream' => false,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiException('serialization', false, $exception);
        }
    }
}

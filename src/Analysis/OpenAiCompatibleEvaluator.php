<?php

declare(strict_types=1);

namespace Yaleksandr\TelegramVacancyMonitor\Analysis;

use JsonException;
use stdClass;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpTransport;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;

/**
 * RU: Проверяет настройки провайдера и оценивает вакансии через Chat Completions.
 * EN: Validates provider settings and evaluates vacancies through Chat Completions.
 */
final readonly class OpenAiCompatibleEvaluator
{
    /**
     * RU: Требует явных настроек провайдера и проверяет адрес до отправки запросов.
     * EN: Requires explicit provider settings and checks the outbound address before any request.
     *
     * @throws ConfigurationException
     */
    public function __construct(
        private AiHttpTransport $transport,
        private AnalysisResultValidator $validator,
        private EvaluationPromptBuilder $promptBuilder,
        private string $baseUrl,
        private string $model,
        private ?string $apiKey = null,
        ProviderLocation $location = ProviderLocation::Local,
    ) {
        $url = parse_url($baseUrl);
        if (
            !is_array($url) || !isset($url['scheme'], $url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['scheme'], ['http', 'https'], true)
            || preg_match('/[\s\x00-\x1F\x7F]/', $baseUrl) === 1
            || mb_trim($model) === '' || strlen($model) > 200 || strlen($baseUrl) > 2048 || strlen($apiKey ?? '') > 4096
            || !mb_check_encoding($model . ($apiKey ?? ''), 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $model . ($apiKey ?? '')) === 1
        ) {
            throw new ConfigurationException('Некорректные адрес ИИ-сервера, модель или ключ.');
        }
        $host = strtolower($url['host']);
        $local = $host === 'localhost' || $host === '[::1]';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = array_map(intval(...), explode('.', $host));
            $local = $octets[0] === 127 || $octets[0] === 10
                || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
                || ($octets[0] === 192 && $octets[1] === 168);
        }
        if ($location === ProviderLocation::Local && !$local) {
            throw new ConfigurationException('Для локального ИИ укажите петлевой или частный IPv4-адрес сервера.');
        }
        if ($location === ProviderLocation::Cloud && ($url['scheme'] !== 'https' || mb_trim($apiKey ?? '') === '')) {
            throw new ConfigurationException('Облачный ИИ требует явно выбранного режима cloud, HTTPS и API-ключа.');
        }
    }

    /**
     * RU: Возвращает общую версию профиля и инструкций этого оценщика.
     * EN: Returns the composite profile/instruction version used by this evaluator.
     */
    public function fingerprint(CandidateProfile $profile): string
    {
        return $this->promptBuilder->fingerprint($profile);
    }

    /**
     * RU: Отправляет один запрос без потоковой передачи и проверяет фиксированный формат результата.
     * EN: Sends one non-streaming request and validates the fixed result contract.
     *
     * @throws AiException
     */
    public function evaluate(string $vacancy, CandidateProfile $profile): AnalysisResult
    {
        if (strlen($vacancy) > 65536 || !mb_check_encoding($vacancy, 'UTF-8')) {
            throw new AiException('vacancy_size_or_encoding', false);
        }
        $request = $this->promptBuilder->build($this->model, $vacancy, $profile);
        $response = $this->transport->postJson(rtrim($this->baseUrl, '/') . '/chat/completions', $request, $this->apiKey);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new AiException('http_' . $response->statusCode, $response->statusCode === 429 || ($response->statusCode >= 500 && $response->statusCode <= 599));
        }
        if (strlen($response->body) > 65536) {
            throw new AiException('response_size', false);
        }
        return $this->validator->validate($this->decodeContent($response->body));
    }

    private function decodeContent(string $body): string
    {
        try {
            $data = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiException('invalid_response', true, $exception);
        }
        $choices = $data instanceof stdClass ? ($data->choices ?? null) : null;
        if (!is_array($choices) || !array_is_list($choices) || !($choices[0] ?? null) instanceof stdClass) {
            throw new AiException('invalid_response', true);
        }
        $message = $choices[0]->message ?? null;
        if (!$message instanceof stdClass) {
            throw new AiException('invalid_response', true);
        }
        $toolCalls = $message->tool_calls ?? null;
        if (
            ($toolCalls !== null && $toolCalls !== [])
            || ($message->function_call ?? null) !== null
            || !is_string($message->content ?? null)
        ) {
            throw new AiException('invalid_response', true);
        }
        return $message->content;
    }
}

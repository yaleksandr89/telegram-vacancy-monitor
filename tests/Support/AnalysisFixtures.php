<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResultValidator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\CandidateProfile;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ConservativePrefilter;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationInstructions;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationPromptBuilder;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpTransport;
use Yaleksandr\TelegramVacancyMonitor\Analysis\OpenAiCompatibleEvaluator;
use Yaleksandr\TelegramVacancyMonitor\Application\BotRunner;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyAnalysisPipeline;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyResultFormatter;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteAnalysisRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * RU: Предоставляет искусственные инструкции без чтения приватных файлов оператора.
 * EN: Supplies synthetic instructions, without reading private operator files.
 */
function testPrompt(string $text = 'Искусственное задание для теста.'): EvaluationPromptBuilder
{
    return new EvaluationPromptBuilder(new EvaluationInstructions($text));
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function syntheticResult(string $verdict = 'apply'): array
{
    return [
        'company' => 'неизвестно', 'position' => 'Разработка API', 'verdict' => $verdict,
        'priority' => 'high', 'matches' => ['API', 'Реляционные БД'], 'risks' => [],
        'geography' => 'неизвестно', 'pay' => 'неизвестно', 'missing' => ['Размер оплаты'],
        'rationale' => 'Подходящие задачи API.', 'source_status' => 'unavailable',
    ];
}

/**
 * RU: Записывает запросы ИИ и возвращает искусственные ответы без сети.
 * EN: Records AI requests and returns synthetic responses without a network.
 */
final class FakeAi implements AiHttpTransport
{
    /** @var list<array{url: string, json: string}> */
    public array $calls = [];
    /** @var list<AiHttpResponse|Throwable> */
    public array $responses = [];
    public ?Closure $beforeCall = null;

    public function postJson(string $url, string $json, ?string $apiKey): AiHttpResponse
    {
        ($this->beforeCall ?? static fn () => null)();
        $this->calls[] = ['url' => $url, 'json' => $json];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response ?? new AiHttpResponse(200, json_encode([
            'choices' => [['message' => ['content' => json_encode(syntheticResult(), JSON_THROW_ON_ERROR)]]],
        ], JSON_THROW_ON_ERROR));
    }
}

/**
 * RU: Записывает запросы Телеграмма и имитирует сбои доставки без сети.
 * EN: Records Telegram requests and simulates delivery failures without a network.
 */
final class FakeTelegram implements HttpTransport
{
    /** @var list<string> */
    public array $texts = [];
    /** @var list<array{timeout: int, offset: int}> */
    public array $polls = [];
    public bool $fail = false;
    public ?Closure $beforeSend = null;

    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        $request = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (str_ends_with($url, '/getUpdates')) {
            $this->polls[] = ['timeout' => $request['timeout'], 'offset' => $request['offset']];
            return new HttpResponse(200, '{"ok":true,"result":[]}');
        }
        verify(str_ends_with($url, '/sendMessage'), 'Неожиданный метод в тестовом транспорте.');
        ($this->beforeSend ?? static fn () => null)();
        verify(!isset($request['parse_mode']), 'Уведомление должно быть обычным текстом.');
        verify(($request['link_preview_options']['is_disabled'] ?? false) === true, 'Предпросмотр ссылок не отключён.');
        $this->texts[] = $request['text'];
        if ($this->fail) {
            return new HttpResponse(503, '{"ok":false,"error_code":503,"description":"secret-response"}');
        }

        return new HttpResponse(200, '{"ok":true,"result":{"message_id":20}}');
    }
}

/**
 * RU: Создаёт изолированное хранилище в памяти и искусственные транспорты для полного цикла обработки.
 * EN: Creates an isolated in-memory inbox and fake transports for the complete processing flow.
 *
 * @return array{PDO, FakeAi, FakeTelegram, VacancyAnalysisPipeline, BotRunner, CandidateProfile, SqliteAnalysisRepository}
 */
function fixture(?ConservativePrefilter $prefilter = null, int $maxAttempts = 3): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    new SqliteSchema($pdo)->ensure();
    $ai = new FakeAi();
    $telegram = new FakeTelegram();
    $bot = new BotApiClient('123:synthetic', $telegram);
    $profile = new CandidateProfile('Искусственный профиль: опыт API и SQL; условия оценивать по тексту вакансии.');
    $jobs = new SqliteAnalysisRepository($pdo);
    $pipeline = new VacancyAnalysisPipeline(
        $jobs, $profile, $prefilter ?? new ConservativePrefilter(),
        new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt(), 'http://127.0.0.1:1234/v1', 'synthetic-model'),
        new VacancyResultFormatter(), $bot, $maxAttempts,
    );
    $inbox = new SqliteInboxRepository($pdo);

    return [$pdo, $ai, $telegram, $pipeline, new BotRunner($bot, $inbox, 123, $pipeline), $profile, $jobs];
}

function intake(BotRunner $runner, int $update, int $message, string $text, int $sender = 123, string $chatType = 'private', ?int $origin = null): void
{
    $payload = [
        'message_id' => $message, 'chat' => ['id' => 123, 'type' => $chatType],
        'from' => ['id' => $sender], 'text' => $text,
    ];
    if ($origin !== null) {
        $payload['forward_origin'] = ['type' => 'channel', 'chat' => ['id' => -1000000000456], 'message_id' => $origin];
    }
    $runner->processUpdate(Update::fromArray(['update_id' => $update, 'message' => $payload]));
}

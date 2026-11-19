<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResultValidator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\CandidateProfile;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ConservativePrefilter;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Analysis\OpenAiCompatibleEvaluator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ProcessingStage;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Verdict;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyAnalysisPipeline;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyResultFormatter;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

require __DIR__ . '/Support/AnalysisFixtures.php';

$profile = fixture()[5];

foreach ([false, true] as $failDelivery) {
    [$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
    $data = verboseResult();
    $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ai->responses = [new AiHttpResponse(200, json_encode([
        'choices' => [['message' => ['content' => $failDelivery ? "```json\n$content\n```" : $content]]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    intake($runner, 1, 10, 'Искусственная вакансия API https://t.me/c/456/8', origin: 8);
    $telegram->beforeSend = static function () use ($telegram, $failDelivery): void {
        $telegram->fail = $failDelivery && count($telegram->texts) === 2;
    };
    $pipeline->processPending(100);
    $job = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
    $expected = array_replace($data, ['source_status' => 'verified_origin_ids']);
    verify($job['analysis_status'] === 'completed' && (int) $job['analysis_attempts'] === 1 && $job['analysis_error'] === null, 'Подробный ответ вызвал ошибку или повтор анализа.');
    verify($job['delivery_attempts'] === 1 && $job['delivery_status'] === ($failDelivery ? 'pending' : 'delivered'), 'Первая попытка доставки учтена неверно.');
    verify(json_decode($job['result_json'], true, flags: JSON_THROW_ON_ERROR) === $expected, 'Полный результат не сохранён.');
    $notification = $job['notification'];
    verify(strlen(mb_convert_encoding($notification, 'UTF-16LE', 'UTF-8')) <= 8192
        && str_contains($notification, '(сокращено для Telegram)') && str_contains($notification, 'https://t.me/c/456/8'), 'Сохранено неверное уведомление.');
    verify(count($telegram->texts) === 3 && $telegram->texts[2] === $notification, 'Первая попытка не отправила ровно один итог после подтверждения и старта.');
    $telegram->beforeSend = null;
    $telegram->fail = false;
    $pipeline->recover(110);
    $pipeline->processPending(130);
    $pipeline->processPending(10000);
    $after = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
    verify(count($ai->calls) === 1 && $after['delivery_status'] === 'delivered'
        && $after['delivery_attempts'] === ($failDelivery ? 2 : 1), 'Повтор доставки вызвал ИИ или не завершился.');
    verify($after['notification'] === $notification && $after['result_json'] === $job['result_json'], 'Повтор изменил сохранённый итог.');
    verify(array_slice($telegram->texts, 2) === array_fill(0, $failDelivery ? 2 : 1, $notification), 'Доставка разделила итог или повторила старт.');
}

foreach ([
    [429, ['retry_after' => 600], 700],
    [429, ['retry_after' => 5], 130],
    [429, null, 130],
    [429, ['retry_after' => '600'], 130],
    [503, ['retry_after' => 600], 130],
    [429, ['retry_after' => PHP_INT_MAX], PHP_INT_MAX],
] as [$status, $parameters, $dueAt]) {
    [$pdo, $ai, , , $runner, $profile, $jobs] = fixture();
    $transport = new class (new HttpResponse($status, json_encode([
        'ok' => false, 'error_code' => $status,
        ...($parameters === null ? [] : ['parameters' => $parameters]),
    ], JSON_THROW_ON_ERROR))) implements HttpTransport {
        public array $texts = [];

        public function __construct(private readonly HttpResponse $failure) {}

        public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
        {
            verify(str_ends_with($url, '/sendMessage'), 'Вместо доставки вызван неожиданный метод Bot API.');
            $this->texts[] = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['text'];
            return count($this->texts) === 2
                ? $this->failure
                : new HttpResponse(200, '{"ok":true,"result":{"message_id":20}}');
        }
    };
    $makePipeline = static fn (): VacancyAnalysisPipeline => new VacancyAnalysisPipeline(
        $jobs, $profile, new ConservativePrefilter(),
        new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt(), 'http://localhost/v1', 'synthetic'),
        new VacancyResultFormatter(), new BotApiClient('123:synthetic', $transport),
    );
    intake($runner, 1, 10, 'Искусственная вакансия API');
    $makePipeline()->processPending(100);
    $saved = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
    verify($saved['analysis_status'] === 'completed' && $saved['delivery_status'] === 'pending'
        && $saved['delivery_attempts'] === 1 && $saved['delivery_next_at'] === $dueAt
        && $transport->texts[1] === $saved['notification'], 'Отказ доставки нарушил срок повтора или сохранение оценки.');

    $restart = $makePipeline();
    $restart->recover(110);
    verify(!$restart->processPending($dueAt - 1) && count($transport->texts) === 2
        && $pdo->query('SELECT delivery_attempts FROM analysis_jobs')->fetchColumn() === 1, 'Рестарт потратил попытку до срока.');
    if ($dueAt === PHP_INT_MAX) {
        continue;
    }
    $restart->processPending($dueAt);
    $after = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
    verify($after['result_json'] === $saved['result_json'] && count($ai->calls) === 1
        && $after['delivery_status'] === 'delivered' && $after['delivery_attempts'] === 2
        && array_slice($transport->texts, 1) === array_fill(0, 2, $saved['notification']), 'Повтор не доставил исходный итог или запустил новый анализ.');
}

$malformedFenced = new AiHttpResponse(200, json_encode([
    'choices' => [['message' => ['content' => "```json\n{\"company\":\"secret-response\",}\n```"]]],
], JSON_THROW_ON_ERROR));
foreach ([
    [new AiException('timeout', true), 'timeout'],
    [new AiHttpResponse(429, 'secret-response'), 'http_429'],
    [new AiHttpResponse(503, 'secret-response'), 'http_503'],
    [$malformedFenced, 'invalid_output'],
] as [$failure, $category]) {
    [$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
    $ai->responses = [$failure, $failure, $failure];
    intake($runner, 1, 10, 'PHP API удалённо');
    $pipeline->processPending(100);
    $pending = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
    verify($pending['analysis_status'] === 'pending' && $pending['analysis_error'] === $category
        && $pending['result_json'] === null && count($telegram->texts) === 2, 'Промежуточная ошибка записана как результат.');
    $pipeline->processPending($pending['analysis_next_at'] - 1);
    verify(count($ai->calls) === 1 && $pending['analysis_next_at'] >= 130, 'Повтор без задержки.');
    $pipeline->processPending($pending['analysis_next_at']);
    $pipeline->processPending((int) $pdo->query('SELECT analysis_next_at FROM analysis_jobs')->fetchColumn());
    $pipeline->processPending(10000);
    $job = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
    verify(count($ai->calls) === 3 && (int) $job['analysis_attempts'] === 3, 'Не три общие попытки.');
    verify(count(array_filter($telegram->texts, static fn (string $text): bool => str_starts_with($text, 'Вакансия передана на анализ ИИ.'))) === 1, 'Повтор ИИ продублировал старт.');
    verify($job['analysis_status'] === 'failed' && $job['verdict'] === 'technical_failure' && $job['delivery_status'] === 'delivered', 'Технический результат потерян.');
    verify($job['analysis_error'] === $category && $job['result_json'] === null, 'Окончательная ошибка записана как результат.');
    verify(count($telegram->texts) === 3 && str_contains(end($telegram->texts), 'Не удалось обработать')
        && !str_contains(implode("\n", $telegram->texts), 'secret-response')
        && !str_contains(json_encode($job, JSON_THROW_ON_ERROR), 'secret-response'), 'Нет технического уведомления или есть утечка.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 1, 'Удалён исходник.');
}

foreach ([new AiHttpResponse(401, 'secret-response'), new AiHttpResponse(400, 'secret-response')] as $failure) {
    [$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
    $ai->responses = [$failure];
    intake($runner, 1, 10, 'PHP API');
    $pipeline->processPending(100);
    $pipeline->processPending(10000);
    verify(count($ai->calls) === 1 && $pdo->query('SELECT analysis_status FROM analysis_jobs')->fetchColumn() === 'failed', 'Постоянная ошибка повторяется.');
}

foreach ([
    new AiHttpResponse(200, 'not-json'),
    new AiHttpResponse(200, '{}'),
    new AiHttpResponse(200, str_repeat('x', 65537)),
    new AiHttpResponse(200, str_repeat('[', 17) . '0' . str_repeat(']', 17)),
    new AiHttpResponse(200, '{"choices":{"0":{"message":{"content":"{}"}}}}'),
] as $failure) {
    $ai = new FakeAi();
    $ai->responses = [$failure];
    try {
        new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt(), 'http://localhost:1234/v1', 'synthetic')->evaluate('PHP API', $profile);
        throw new RuntimeException('Некорректный ответ провайдера принят.');
    } catch (AiException $exception) {
        verify(!str_contains($exception->getMessage(), 'secret'), 'Утечка ответа.');
    }
}

$content = json_encode(syntheticResult(), JSON_THROW_ON_ERROR);
foreach ([[], ['tool_calls' => []], ['tool_calls' => null], ['function_call' => null], ['tool_calls' => [], 'function_call' => null]] as $metadata) {
    $ai = new FakeAi();
    $ai->responses = [new AiHttpResponse(200, json_encode([
        'choices' => [['message' => ['content' => $content, ...$metadata]]],
    ], JSON_THROW_ON_ERROR))];
    $result = new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt(), 'http://localhost/v1', 'synthetic')->evaluate('PHP API', $profile);
    verify($result->toJson() === $content, 'Пустые метаданные вызовов помешали проверке полного результата.');
}
foreach ([
    ['tool_calls' => [['id' => 'synthetic-call', 'type' => 'function', 'function' => ['name' => 'synthetic', 'arguments' => '{}']]]],
    ['tool_calls' => 'invalid'], ['tool_calls' => (object) []], ['tool_calls' => 1],
    ['tool_calls' => 0], ['tool_calls' => false],
    ['function_call' => ['name' => 'synthetic', 'arguments' => '{}']], ['function_call' => 'synthetic'],
] as $metadata) {
    $ai = new FakeAi();
    $ai->responses = [new AiHttpResponse(200, json_encode([
        'choices' => [['message' => ['content' => $content, ...$metadata]]],
    ], JSON_THROW_ON_ERROR))];
    try {
        new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt(), 'http://localhost/v1', 'synthetic')->evaluate('PHP API', $profile);
        throw new RuntimeException('Вызов инструмента или неверный тип метаданных принят.');
    } catch (AiException $exception) {
        verify($exception->category === 'invalid_response' && $exception->retryable, 'Изменена категория ошибки метаданных.');
        verify(!str_contains($exception->getMessage(), 'synthetic'), 'Метаданные раскрыты в ошибке.');
    }
}

[$pdo, $ai, $telegram, $pipeline, $runner, $profile, $jobs] = fixture();
intake($runner, 1, 10, 'PHP API удалённо');
$jobs->begin(ProcessingStage::Analysis, 1, 100, $profile->fingerprint);
$restart = new VacancyAnalysisPipeline(
    $jobs, $profile, new ConservativePrefilter(),
    new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt(), 'http://localhost:1234/v1', 'synthetic'),
    new VacancyResultFormatter(), new BotApiClient('123:synthetic', $telegram),
);
$restart->recover(100);
$restart->processPending(100);
verify(count($ai->calls) === 0, 'Прерванная попытка повторилась без задержки.');
$telegram->fail = true;
$restart->processPending(130);
$job = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
verify((int) $job['analysis_attempts'] === 2 && $job['analysis_status'] === 'completed' && (int) $job['delivery_attempts'] === 1, 'Счётчик рестарта неверен.');
$savedNotification = $job['notification'];
$restart->recover(131);
$restart->processPending(160);
$restart->processPending(220);
$restart->processPending(10000);
$job = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
verify(count($ai->calls) === 1 && $job['analysis_status'] === 'completed' && $job['delivery_status'] === 'failed' && (int) $job['delivery_attempts'] === 3, 'Повтор AI из-за доставки или неверный лимит доставки.');
verify($job['notification'] === $savedNotification && $job['result_json'] !== null, 'Потерян сохранённый результат.');

[$pdo, $ai, $telegram, $pipeline, $runner, $profile, $jobs] = fixture();
intake($runner, 1, 10, 'PHP API');
$telegram->fail = true;
$pipeline->processPending(100);
$jobs->begin(ProcessingStage::Delivery, 1, 130);
$pipeline->recover(130);
$telegram->fail = false;
$pipeline->processPending(189);
verify(count($telegram->texts) === 3, 'Отправка повторилась до сохранённой задержки.');
$pipeline->processPending(190);
$job = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
verify((int) $job['delivery_attempts'] === 3 && $job['delivery_status'] === 'delivered' && count($ai->calls) === 1, 'Восстановление прерванной доставки повторило AI или потеряло попытку.');

[$pdo, $ai, $telegram, $pipeline, $runner, $profile, $jobs] = fixture();
intake($runner, 1, 10, 'PHP API');
for ($attempt = 1; $attempt <= 3; ++$attempt) {
    $jobs->begin(ProcessingStage::Analysis, 1, 1000 * $attempt, $profile->fingerprint);
    $pipeline->recover(1000 * $attempt);
}
$pipeline->processPending(10000);
verify(count($ai->calls) === 0 && $pdo->query('SELECT analysis_status FROM analysis_jobs')->fetchColumn() === 'failed', 'Исчерпанные прерванные попытки потеряны.');
verify(str_contains(end($telegram->texts), 'Не удалось обработать'), 'Нет уведомления об исчерпании после рестарта.');
verify(count($telegram->texts) === 2, 'Исчерпанные попытки вызвали уведомление старта.');

// RU: Явно создаём прежнюю схему, не открывая runtime-базу приложения.
// EN: Recreate the prior schema explicitly, without opening any runtime database.
$old = new PDO('sqlite::memory:');
$old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->exec(<<<'SQL'
    CREATE TABLE messages (
        id INTEGER PRIMARY KEY, recipient_chat_id INTEGER NOT NULL, recipient_message_id INTEGER NOT NULL,
        origin_type TEXT NOT NULL, origin_chat_id INTEGER, origin_message_id INTEGER, body TEXT NOT NULL,
        saved_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (recipient_chat_id, recipient_message_id)
    );
    CREATE UNIQUE INDEX messages_origin_unique ON messages (origin_chat_id, origin_message_id)
        WHERE origin_chat_id IS NOT NULL AND origin_message_id IS NOT NULL;
    CREATE TABLE cursor (name TEXT PRIMARY KEY, next_offset INTEGER NOT NULL);
    INSERT INTO messages (recipient_chat_id, recipient_message_id, origin_type, body) VALUES (123, 10, 'direct', 'Историческая искусственная запись');
    INSERT INTO cursor VALUES ('main', 42);
    SQL);
$before = $old->query('SELECT * FROM messages')->fetchAll(PDO::FETCH_ASSOC);
new SqliteSchema($old)->ensure();
new SqliteSchema($old)->ensure();
verify($old->query('SELECT * FROM messages')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Инициализация изменила исторические данные.');
verify((int) $old->query('SELECT next_offset FROM cursor')->fetchColumn() === 42 && (int) $old->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() === 0, 'Инициализация изменила курсор или запланировала историю.');

[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
$pdo->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON analysis_jobs BEGIN SELECT RAISE(ABORT, 'Искусственная ошибка очереди'); END");
try {
    intake($runner, 1, 10, 'PHP API');
    throw new RuntimeException('Ошибка очереди подавлена.');
} catch (PDOException) {
}
verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM cursor')->fetchColumn() === 0, 'Ошибка очереди не откатила приём/курсор.');

[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
$pdo->exec('CREATE TRIGGER ignore_queue BEFORE INSERT ON analysis_jobs BEGIN SELECT RAISE(IGNORE); END');
try {
    intake($runner, 1, 10, 'Разработка API');
    throw new LogicException('Пропуск создания задания не обнаружен.');
} catch (RuntimeException $exception) {
    verify(str_contains($exception->getMessage(), 'создать задание'), 'Ожидалась ошибка создания задания.');
}
verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM cursor')->fetchColumn() === 0, 'Молча пропущенное задание продвинуло курсор.');

[$pdo, $ai, $telegram, $pipeline, $runner, $profile, $jobs] = fixture();
intake($runner, 1, 10, 'Разработка API');
foreach ([
    static fn () => $jobs->complete(1, Verdict::Apply, '{}', 'Текст'),
    static fn () => $jobs->delivered(1),
    static fn () => $jobs->fail(ProcessingStage::Analysis, 1, 'timeout', false, 100),
    static fn () => $jobs->begin(ProcessingStage::Delivery, 1, 100),
] as $invalidTransition) {
    try {
        $invalidTransition();
        throw new LogicException('Неверный переход состояния принят.');
    } catch (RuntimeException $exception) {
        verify(str_contains($exception->getMessage(), 'переход'), 'Ожидалась явная ошибка перехода состояния.');
    }
}

// RU: Ожидающая работа сокращает polling, но каждая итерация возвращается к приёму обновлений.
// EN: Pending work shortens polling, but each iteration must return to receiving updates.
[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
for ($i = 1; $i <= 8; ++$i) {
    intake($runner, $i, $i + 10, 'Синтетическая вакансия API');
}
$runner->runIteration();
verify(count($ai->calls) === 3 && count($telegram->polls) === 1 && $telegram->polls[0]['timeout'] === 1, 'Пакет очереди не ограничен или длинный polling задержал очередь.');
$runner->runIteration();
verify(count($ai->calls) === 6 && count($telegram->polls) === 2, 'Следующая итерация не продолжила очередь.');
$runner->runIteration();
verify(count($ai->calls) === 8 && $telegram->polls[2]['timeout'] === 25, 'Пустая очередь не вернула long polling.');

[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
intake($runner, 1, 10, 'Синтетическая вакансия API');
$ai->responses = [new AiException('timeout', true)];
$runner->runIteration();
verify(count($ai->calls) === 1 && $telegram->polls[0]['timeout'] === 25, 'Отложенный повтор вызвал активное ожидание.');
$next = (int) $pdo->query('SELECT analysis_next_at FROM analysis_jobs')->fetchColumn();
$pipeline->processPending($next - 1);
verify(count($ai->calls) === 1, 'Сохранённая задержка проигнорирована.');

foreach ([1, 5] as $limit) {
    [$pdo, $ai, $telegram, $pipeline, $runner] = fixture(maxAttempts: $limit);
    $ai->responses = array_fill(0, $limit + 1, new AiException('timeout', true));
    intake($runner, 1, 10, 'Синтетическая вакансия API');
    for ($i = 0; $i <= $limit; ++$i) {
        $pipeline->processPending(1000 * ($i + 1));
    }
    verify(count($ai->calls) === $limit && (int) $pdo->query('SELECT analysis_attempts FROM analysis_jobs')->fetchColumn() === $limit, 'Лимит попыток конфигурации нарушен.');
}

[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
intake($runner, 1, 10, "Неверный UTF-8: \xFF");
$pipeline->processPending(100);
verify(count($ai->calls) === 0 && $pdo->query('SELECT analysis_status FROM analysis_jobs')->fetchColumn() === 'failed', 'Непригодный вход оставил задание незавершённым.');
verify(count($telegram->texts) === 2 && (int) $pdo->query('SELECT analysis_start_claimed FROM analysis_jobs')->fetchColumn() === 0, 'Непригодный вход вызвал уведомление старта.');
verify(str_contains(end($telegram->texts), 'Не удалось обработать'), 'Нет уведомления о непригодном входе.');

[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
$ai->responses = [new AiException('http_401', false)];
intake($runner, 1, 10, 'Искусственная вакансия https://example.org/vacancy');
$pipeline->processPending(100);
verify(str_contains(end($telegram->texts), 'Не удалось обработать') && str_contains(end($telegram->texts), 'https://example.org/vacancy') && str_contains(end($telegram->texts), 'не проверена'), 'Техническое уведомление потеряло доступную ссылку.');

[$pdo, $ai, $telegram, $pipeline, $runner, $profile, $jobs] = fixture();
intake($runner, 1, 10, 'Искусственная вакансия API');
$telegram->fail = true;
$pipeline->processPending(100);
$savedResult = $pdo->query('SELECT result_json, profile_hash FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
$editedProfile = new CandidateProfile($profile->text . ' Дополнение.');
$restart = new VacancyAnalysisPipeline(
    $jobs, $editedProfile, new ConservativePrefilter(),
    new OpenAiCompatibleEvaluator($ai, new AnalysisResultValidator(), testPrompt('Другая редакция задания.'), 'http://localhost/v1', 'synthetic'),
    new VacancyResultFormatter(), new BotApiClient('123:synthetic', $telegram),
);
$telegram->fail = false;
$restart->recover(110);
$restart->processPending(130);
verify(count($ai->calls) === 1, 'Изменение профиля или инструкций повторило сохранённую оценку.');
verify($pdo->query('SELECT result_json, profile_hash FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC) === $savedResult, 'Изменена версия или содержимое сохранённого результата.');
verify($pdo->query('SELECT delivery_status FROM analysis_jobs')->fetchColumn() === 'delivered', 'Готовый результат не доставлен после изменения задания.');

// RU: Сбой после claim, но до отправки не должен разрешать вторую попытку уведомления.
// EN: A crash after claim but before sending must not allow another notification attempt.
[$pdo, $ai, $telegram, $pipeline, $runner, $profile, $jobs] = fixture();
intake($runner, 1, 10, 'Искусственная вакансия API');
$jobs->begin(ProcessingStage::Analysis, 1, 100, $profile->fingerprint);
verify($jobs->claimAnalysisStart(1) && !$jobs->claimAnalysisStart(1), 'Claim не идемпотентен.');
$pipeline->recover(100);
$pipeline->processPending(130);
verify(count($ai->calls) === 1 && count($telegram->texts) === 2, 'Рестарт повторил потерянное уведомление старта.');

// RU: Сбой после уведомления, но до ИИ сохраняет claim и допускает только повтор анализа.
// EN: A crash after notification but before AI preserves the claim and only retries analysis.
[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
intake($runner, 1, 10, 'Искусственная вакансия API');
$ai->beforeCall = static function (): void {
    throw new RuntimeException('Искусственное прерывание до ИИ.');
};
try {
    $pipeline->processPending(100);
    throw new LogicException('Прерывание не воспроизведено.');
} catch (RuntimeException $exception) {
    verify($exception->getMessage() === 'Искусственное прерывание до ИИ.', 'Неожиданная причина прерывания.');
}
$ai->beforeCall = null;
$pipeline->recover(100);
$pipeline->processPending(130);
$pipeline->recover(140);
$pipeline->processPending(10000);
verify(count($ai->calls) === 1 && count($telegram->texts) === 3, 'Восстановление повторило старт или готовую оценку.');

// RU: Воспроизводим предыдущую схему analysis_jobs только в памяти, сохраняя terminal записи.
// EN: Reproduce the previous analysis_jobs schema in memory only, retaining terminal records.
[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
intake($runner, 1, 10, 'Искусственная вакансия API');
$pipeline->processPending(100);
intake($runner, 2, 11, 'Другая искусственная вакансия');
$ai->responses = [new AiException('http_401', false)];
$pipeline->processPending(100);
$pdo->exec('ALTER TABLE analysis_jobs DROP COLUMN analysis_start_claimed');
$beforeJobs = $pdo->query('SELECT * FROM analysis_jobs ORDER BY message_id')->fetchAll(PDO::FETCH_ASSOC);
$beforeMessages = $pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
new SqliteSchema($pdo)->ensure();
new SqliteSchema($pdo)->ensure();
$afterJobs = $pdo->query('SELECT * FROM analysis_jobs ORDER BY message_id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($afterJobs as &$migratedJob) {
    verify((int) $migratedJob['analysis_start_claimed'] === 0, 'Неверное значение добавленного поля.');
    unset($migratedJob['analysis_start_claimed']);
}
unset($migratedJob);
verify($afterJobs === $beforeJobs && $pdo->query('SELECT * FROM messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $beforeMessages, 'Миграция изменила прежние записи.');
$pipeline->recover(1000);
$pipeline->processPending(1000);
verify(count($ai->calls) === 2 && count($telegram->texts) === 6, 'Миграция повторно запланировала terminal записи.');

fwrite(STDOUT, "Проверка восстановления анализа и доставки: OK\n");

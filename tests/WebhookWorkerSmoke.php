<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Analysis\ProcessingStage;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;
use Yaleksandr\TelegramVacancyMonitor\Application\IncomingUpdateProcessor;
use Yaleksandr\TelegramVacancyMonitor\Application\WebhookReceiver;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteAnalysisRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;

require __DIR__ . '/Support/AnalysisFixtures.php';

function webhookBody(int $update, int $message, int $sender = 123, ?int $origin = null, string $chatType = 'private'): string
{
    $payload = [
        'message_id' => $message, 'chat' => ['id' => 123, 'type' => $chatType],
        'from' => ['id' => $sender], 'text' => 'Искусственная вакансия API',
    ];
    if ($origin !== null) {
        $payload['forward_origin'] = ['type' => 'channel', 'chat' => ['id' => -1000000000456], 'message_id' => $origin];
    }
    return json_encode(['update_id' => $update, 'message' => $payload], JSON_THROW_ON_ERROR);
}

function receive(WebhookReceiver $receiver, string $body): int
{
    return $receiver->receive('POST', 'application/json; charset=utf-8', 'synthetic-secret', static fn (): string => $body);
}

[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
$opens = 0;
$receiver = new WebhookReceiver('synthetic-secret', static function () use ($pdo, $telegram, &$opens): IncomingUpdateProcessor {
    ++$opens;
    return new IncomingUpdateProcessor(new BotApiClient('123:synthetic', $telegram), new SqliteInboxRepository($pdo), 123);
});
$unread = static function (): string {
    throw new LogicException('Тело не должно читаться до проверки заголовков.');
};
verify($receiver->receive('GET', 'application/json', 'synthetic-secret', $unread) === 405, 'Разрешён не-POST.');
foreach ([null, '', 'incorrect-secret'] as $secret) {
    verify($receiver->receive('POST', 'application/json', $secret, $unread) === 403, 'Принят неверный секрет.');
}
verify($receiver->receive('POST', 'text/plain', 'synthetic-secret', $unread) === 415, 'Принят неверный Content-Type.');
foreach (['{', '[]', 'null', '{}', '{"update_id":-1}', '{"update_id":"1"}', '{"update_id":1,"message":[]}', '{"update_id":1,"message":"invalid"}'] as $malformed) {
    verify(receive($receiver, $malformed) === 400, 'Принято некорректное тело.');
}
verify(receive($receiver, str_repeat(' ', WebhookReceiver::MAX_BODY_BYTES + 1)) === 413, 'Принято слишком большое тело.');
verify($opens === 0 && $telegram->texts === [] && $ai->calls === [], 'Отклонённый запрос открыл БД или вызвал API.');
foreach (['', 'has spaces', str_repeat('x', 257), 'секрет'] as $invalidSecret) {
    try {
        new WebhookReceiver($invalidSecret, static fn (): IncomingUpdateProcessor => throw new LogicException('Не открывать БД.'));
        throw new LogicException('Принят неверный формат секрета.');
    } catch (ConfigurationException) {
    }
}

$telegram->beforeSend = static function () use ($pdo): void {
    verify(!$pdo->inTransaction(), 'Подтверждение отправлено внутри транзакции.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM received_updates')->fetchColumn() >= 1, 'Подтверждение до фиксации update.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === (int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn(), 'Подтверждение до создания задания.');
};
verify(receive($receiver, webhookBody(101, 11, origin: 51)) === 204, 'Не принят корректный update.');
verify(receive($receiver, webhookBody(101, 99, origin: 99)) === 204 && count($telegram->texts) === 1, 'Повтор update вызвал приём или ответ.');
verify(receive($receiver, webhookBody(100, 10, origin: 50)) === 204, 'Потерян неупорядоченный update.');
verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 2 && count($telegram->texts) === 2, 'Update 101 заставил пропустить 100.');
verify(new SqliteInboxRepository($pdo)->nextOffset() === 0, 'Webhook изменил polling-курсор.');
verify(receive($receiver, webhookBody(102, 12, origin: 50)) === 204, 'Не принят дубль origin.');
verify(receive($receiver, webhookBody(103, 10)) === 204, 'Не принят дубль recipient.');
verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 2 && end($telegram->texts) === 'Эта публикация уже сохранена.', 'Утрачена исходная дедупликация.');
foreach ([webhookBody(104, 13, sender: 999), webhookBody(105, 14, chatType: 'group')] as $unauthorized) {
    $before = count($telegram->texts);
    verify(receive($receiver, $unauthorized) === 204 && count($telegram->texts) === $before, 'Постороннему отправлен ответ.');
}
verify((int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() === 2, 'Постороннее сообщение создало задание.');
verify(receive($receiver, webhookBody(106, 15)) === 204 && receive($receiver, webhookBody(107, 16)) === 204, 'Неподтверждённые источники объединены.');
verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 4 && $ai->calls === [] && $telegram->polls === [], 'Webhook вызвал ИИ/polling или потерял независимые сообщения.');
$telegram->beforeSend = null;
$telegram->fail = true;
verify(receive($receiver, webhookBody(108, 17)) === 204, 'Сбой подтверждения отменил приём.');
verify((int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() === 5, 'Сбой подтверждения потерял задание.');
$telegram->fail = false;

// RU: Ошибка очереди откатывает receipt, сообщение и job; повтор затем может сохраниться.
// EN: Queue failure rolls back receipt, message and job; redelivery can then persist.
$pdo->exec("CREATE TRIGGER fail_webhook_job BEFORE INSERT ON analysis_jobs BEGIN SELECT RAISE(ABORT, 'Искусственный отказ'); END");
$beforeReplies = count($telegram->texts);
verify(receive($receiver, webhookBody(109, 18)) === 503, 'Ошибка БД получила 2xx.');
verify((int) $pdo->query('SELECT COUNT(*) FROM received_updates WHERE update_id = 109')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 5 && count($telegram->texts) === $beforeReplies, 'Ошибка БД не откатила приём или вызвала подтверждение.');
$pdo->exec('DROP TRIGGER fail_webhook_job');
verify(receive($receiver, webhookBody(109, 18)) === 204, 'Повтор после ошибки не сохранился.');

// RU: Отложенный внешний ключ воспроизводит сбой именно COMMIT, после всех INSERT.
// EN: A deferred foreign key reproduces COMMIT failure, after all INSERTs succeed.
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE commit_guard (id INTEGER REFERENCES messages(id) DEFERRABLE INITIALLY DEFERRED)');
$pdo->exec('CREATE TRIGGER fail_commit AFTER INSERT ON analysis_jobs BEGIN INSERT INTO commit_guard VALUES (-1); END');
$beforeReplies = count($telegram->texts);
verify(receive($receiver, webhookBody(110, 19)) === 503 && count($telegram->texts) === $beforeReplies, 'Сбой COMMIT получил 2xx или подтверждение.');
verify(!$pdo->inTransaction() && (int) $pdo->query('SELECT COUNT(*) FROM received_updates WHERE update_id = 110')->fetchColumn() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 6, 'Сбой COMMIT не откатил весь приём.');
$pdo->exec('DROP TRIGGER fail_commit');
verify(receive($receiver, webhookBody(110, 19)) === 204, 'Повтор после сбоя COMMIT не принят.');
$unavailable = new WebhookReceiver('synthetic-secret', static fn (): IncomingUpdateProcessor => throw new PDOException('Искусственный отказ открытия БД.'));
verify(receive($unavailable, webhookBody(111, 20)) === 503, 'Ошибка открытия БД получила 2xx.');
$pdo->exec('CREATE TRIGGER ignore_receipt BEFORE INSERT ON received_updates BEGIN SELECT RAISE(IGNORE); END');
verify(receive($receiver, webhookBody(111, 20)) === 503, 'Пропущенный receipt принят за повтор update.');
verify((int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() === 7, 'Пропущенный receipt сохранил сообщение.');
$pdo->exec('DROP TRIGGER ignore_receipt');

// RU: Два файловых дескриптора имитируют конкурентные cron tick без runtime-файлов приложения.
// EN: Two file descriptors simulate concurrent cron ticks without application runtime files.
[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
$receiver = new WebhookReceiver('synthetic-secret', static fn (): IncomingUpdateProcessor => new IncomingUpdateProcessor(
    new BotApiClient('123:synthetic', $telegram), new SqliteInboxRepository($pdo), 123,
));
for ($i = 1; $i <= 12; ++$i) {
    verify(receive($receiver, webhookBody($i, $i + 10)) === 204, 'Webhook не наполнил очередь воркера.');
}
$lock = tmpfile();
verify(is_resource($lock), 'Не создана временная блокировка.');
$secondLock = fopen(stream_get_meta_data($lock)['uri'], 'r+');
verify(is_resource($secondLock), 'Не открыт второй дескриптор блокировки.');
try {
    $ai->beforeCall = static function () use ($pdo, $ai, $runner, $secondLock): void {
        $before = $pdo->query('SELECT * FROM analysis_jobs ORDER BY message_id')->fetchAll(PDO::FETCH_ASSOC);
        verify(!$runner->workOnce($secondLock), 'Конкурирующий tick получил блокировку.');
        verify($pdo->query('SELECT * FROM analysis_jobs ORDER BY message_id')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Конкурирующий tick выполнил recover или изменил задания.');
        verify(!$pdo->inTransaction(), 'Воркер вызвал ИИ внутри транзакции.');
        $ai->beforeCall = null;
    };
    verify($runner->workOnce($lock), 'Воркер не запустился.');
    verify(count($ai->calls) === 10 && $telegram->polls === [], 'Batch не конечен или вызвал getUpdates.');
    verify((int) $pdo->query("SELECT COUNT(*) FROM analysis_jobs WHERE analysis_status = 'pending'")->fetchColumn() === 2, 'Остаток очереди потерян.');
    verify($runner->workOnce($secondLock) && count($ai->calls) === 12, 'Следующий tick не продолжил очередь или lock остался занят.');
    verify($runner->workOnce($lock) && count($ai->calls) === 12 && $telegram->polls === [], 'Пустой tick повторил оценку или вызвал polling.');

    intake($runner, 13, 23, 'Искусственная вакансия после рестарта');
    new SqliteAnalysisRepository($pdo)->begin(ProcessingStage::Analysis, 13, time());
    verify($runner->workOnce($lock), 'Воркер не восстановился.');
    verify($pdo->query('SELECT analysis_status FROM analysis_jobs WHERE message_id = 13')->fetchColumn() === 'pending'
        && count($ai->calls) === 12, 'Recover потерял задание или проигнорировал задержку.');
    $pdo->exec('UPDATE analysis_jobs SET analysis_next_at = 0 WHERE message_id = 13');
    verify($runner->workOnce($secondLock) && $runner->workOnce($lock) && count($ai->calls) === 13, 'Recover повторил готовую оценку.');
    verify((int) $pdo->query('SELECT analysis_attempts FROM analysis_jobs WHERE message_id = 13')->fetchColumn() === 2, 'Recover потерял учёт прерванной попытки.');
} finally {
    fclose($secondLock);
    fclose($lock);
}

fwrite(STDOUT, "Проверка webhook и cron-воркера: OK\n");

<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Application\BotRunner;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

require __DIR__ . '/Support/AnalysisFixtures.php';

$transport = new class implements HttpTransport {
    /** @var list<string> */
    public array $texts = [];
    /** @var list<int> */
    public array $offsets = [];
    /** @var list<int> */
    public array $dropped = [];
    /** @var array<int, array<string, mixed>> */
    public array $queue = [];
    /** @var array<array-key, mixed>|null */
    public ?array $forcedBatch = null;

    /** @return list<string> */
    public function repliesSince(int $offset): array
    {
        return array_slice($this->texts, $offset);
    }

    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        $request = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (str_ends_with($url, '/getUpdates')) {
            $offset = $request['offset'];
            $this->offsets[] = $offset;
            foreach ($this->queue as $key => $update) {
                if ($update['update_id'] < $offset) {
                    $this->dropped[] = $update['update_id'];
                    unset($this->queue[$key]);
                }
            }
            return new HttpResponse(200, json_encode([
                'ok' => true, 'result' => $this->forcedBatch ?? array_values($this->queue),
            ], JSON_THROW_ON_ERROR));
        }
        if (!str_ends_with($url, '/sendMessage')) {
            throw new RuntimeException('Неожиданный HTTP-метод.');
        }

        $this->texts[] = $request['text'];

        return new HttpResponse(200, '{"ok":true,"result":{"message_id":20}}');
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
new SqliteSchema($pdo)->ensure();
$repository = new SqliteInboxRepository($pdo);
$runner = new BotRunner(new BotApiClient('123:test-secret', $transport), $repository, 123);

$makeUpdate = static fn(int $id, int $sender, int $recipientMessageId, int $originMessageId): Update
    => Update::fromArray([
        'update_id' => $id,
        'message' => [
            'message_id' => $recipientMessageId,
            'chat' => ['id' => $sender, 'type' => 'private'],
            'from' => ['id' => $sender],
            'text' => 'Вакансия PHP',
            'forward_origin' => [
                'type' => 'channel',
                'chat' => ['id' => -100456],
                'message_id' => $originMessageId,
            ],
        ],
    ]);

$runner->processUpdate($makeUpdate(10, 999, 1, 20));
$runner->processUpdate($makeUpdate(11, 123, 2, 20));
$runner->processUpdate($makeUpdate(12, 123, 3, 20));
$runner->processUpdate($makeUpdate(13, 123, 4, 21));
$runner->processUpdate($makeUpdate(13, 123, 4, 21));

if (
    $repository->nextOffset() !== 14
    || (int) testQuery($pdo, 'SELECT COUNT(*) FROM messages')->fetchColumn() !== 2
    || count($transport->texts) !== 3
) {
    throw new RuntimeException('Ошибка разрешений, дедупликации, ответов или курсора.');
}

$clockTime = 1_800_000_000;
$clock = static function () use (&$clockTime): int {
    return $clockTime;
};
$path = tempnam(sys_get_temp_dir(), 'tvm-cursor-');
verify($path !== false, 'Не создан временный файл SQLite.');
register_shutdown_function(static function () use ($path, &$pdo, &$repository, &$runner): void {
    $runner = $repository = $pdo = null;
    unlink($path);
});
$dsn = 'sqlite:' . $path;
$bot = new BotApiClient('123:synthetic', $transport);
$pdo = new PDO($dsn);
$pdo->exec("CREATE TABLE cursor (name TEXT PRIMARY KEY, next_offset INTEGER NOT NULL);
    INSERT INTO cursor VALUES ('main', 900000)");
new SqliteSchema($pdo)->ensure();
new SqliteSchema($pdo)->ensure();
$repository = new SqliteInboxRepository($pdo);
$runner = new BotRunner($bot, $repository, 123, clock: $clock);
$payload = static fn(int $id, int $messageId, array $content, int $sender = 123): array => [
    'update_id' => $id,
    'message' => [
        'message_id' => $messageId,
        'chat' => ['id' => $sender, 'type' => 'private'],
        'from' => ['id' => $sender],
    ] + $content,
];
$cursor = static fn(PDO $pdo): array => testQuery(
    $pdo,
    "SELECT next_offset, last_handled_at FROM cursor WHERE name = 'main'",
)->fetch(PDO::FETCH_NUM) ?: throw new RuntimeException('Нет строки SQLite.');
$counts = static fn(PDO $pdo): array => testQuery(
    $pdo,
    'SELECT (SELECT COUNT(*) FROM messages), (SELECT COUNT(*) FROM analysis_jobs)',
)->fetch(PDO::FETCH_NUM) ?: throw new RuntimeException('Нет строки SQLite.');
verify($cursor($pdo) === [900000, null], 'Миграция потеряла старый курсор или выдумала время обработки.');
$transport->queue = [
    $payload(21, 21, ['text' => 'Первая вакансия новой последовательности']),
    $payload(22, 22, ['text' => 'Вторая вакансия новой последовательности']),
];
$pdo->exec("CREATE TRIGGER fail_second_job BEFORE INSERT ON analysis_jobs
    WHEN NEW.message_id = 2 BEGIN SELECT RAISE(ABORT, 'Искусственный отказ'); END");
try {
    $runner->runIteration();
    throw new LogicException('Ошибка SQLite в середине пакета подавлена.');
} catch (PDOException) {
}
verify($transport->offsets === [0] && $transport->dropped === [], 'Старый offset подтвердил новые низкие ID.');
verify($cursor($pdo) === [22, $clockTime] && $counts($pdo) === [1, 1]
    && !$pdo->inTransaction(), 'Первая запись не пережила сбой второй или вторая продвинула курсор.');
$runner = $repository = $pdo = null;
$pdo = new PDO($dsn);
$pdo->exec('DROP TRIGGER fail_second_job');
$repository = new SqliteInboxRepository($pdo);
$runner = new BotRunner($bot, $repository, 123, clock: $clock);
$transport->queue[] = $payload(23, 21, ['text' => 'Повтор первой вакансии']);
$transport->queue[] = $payload(24, 24, ['text' => 'Посторонний отправитель'], sender: 999);
++$clockTime;
$runner->runIteration();
verify($transport->offsets === [0, 22] && $transport->dropped === [21], 'После рестарта подтверждены необработанные обновления.');
verify($cursor($pdo) === [25, $clockTime] && $counts($pdo) === [2, 2]
    && end($transport->texts) === 'Эта публикация уже сохранена.', 'Пакет потерял сообщение, создал дубль или пропустил ID постороннего отправителя.');
$transport->queue[] = ['update_id' => 25];
++$clockTime;
$runner->runIteration();
verify(
    end($transport->offsets) === 25 && $cursor($pdo) === [26, $clockTime] && $counts($pdo) === [2, 2],
    'Обновление без сообщения не зафиксировано или создало задание.',
);

$lastHandledAt = $clockTime;
$clockTime += 5 * 86400;
$runner->runIteration();
verify(end($transport->offsets) === 26 && $cursor($pdo) === [26, $lastHandledAt], 'Пустой ответ изменил свежий курсор.');
++$clockTime;
$runner->runIteration();
verify(end($transport->offsets) === 0 && $cursor($pdo) === [26, $lastHandledAt], 'Работающий без перезапуска цикл подтвердил устаревший offset.');
$clockTime += 2 * 86400;
$transport->queue = [$payload(4, 4, ['text' => 'Новая вакансия с низким update_id'])];
$pdo->exec("CREATE TRIGGER fail_rebase BEFORE INSERT ON analysis_jobs BEGIN SELECT RAISE(ABORT, 'Искусственный отказ'); END");
try {
    $runner->runIteration();
    throw new LogicException('Ошибка первой записи новой последовательности подавлена.');
} catch (PDOException) {
}
verify(
    end($transport->offsets) === 0 && $cursor($pdo) === [26, $lastHandledAt] && $counts($pdo) === [2, 2],
    'Ошибка сохранения новой последовательности не откатила сообщение, задание или курсор вместе с отметкой времени.',
);
$pdo->exec('DROP TRIGGER fail_rebase');
$transport->queue = [
    $payload(3, 21, ['text' => 'Дубликат открывает новую последовательность']),
    $payload(4, 4, ['text' => 'Новая вакансия с низким update_id']),
];
$runner->runIteration();
verify(
    end($transport->offsets) === 0 && $cursor($pdo) === [5, $clockTime] && $counts($pdo) === [3, 3],
    'Дубликат в начале новой последовательности помешал восстановлению или создал второе задание.',
);
$runner->runIteration();
verify(
    end($transport->offsets) === 5 && $transport->dropped === [21, 22, 23, 24, 25, 3, 4],
    'В очереди подтверждены данные вне сохранённого курсора.',
);

foreach ([
    [['update_id' => 4]],
    [['update_id' => 7], ['update_id' => 6]],
] as $invalidBatch) {
    $transport->forcedBatch = $invalidBatch;
    try {
        $runner->runIteration();
        throw new LogicException('Низкий или неупорядоченный update_id молча пропущен.');
    } catch (UnexpectedResponseException $exception) {
        verify(str_contains($exception->getMessage(), 'Курсор не продвинут'), 'Нет диагностики порядка ID.');
    }
    verify(end($transport->offsets) === 5 && $cursor($pdo) === [5, $clockTime], 'Сбой порядка ID продвинул курсор.');
}
$transport->forcedBatch = null;
foreach ([$clockTime + 1, 0, 'invalid'] as $badTimestamp) {
    $pdo->prepare("UPDATE cursor SET last_handled_at = :timestamp WHERE name = 'main'")
        ->execute(['timestamp' => $badTimestamp]);
    $runner->runIteration();
    verify(end($transport->offsets) === 0, 'Некорректная отметка времени разрешила подтверждение старым offset.');
}

$command = ['type' => 'bot_command', 'offset' => 0, 'length' => 6];
$vacancies = [
    ['text' => '/starter'],
    ['text' => '/startfoo — вакансия PHP'],
    ['text' => '/start payload', 'entities' => [$command]],
    ['text' => "/start\njob", 'entities' => [$command]],
    ['text' => 'Вакансия /start'],
    ['text' => '/start@botname'],
    ['text' => '/start', 'forward_origin' => ['type' => 'hidden_user'], 'entities' => [$command]],
    ['caption' => '/start', 'caption_entities' => [$command]],
    ['text' => '/start', 'entities' => [['type' => 'bot_command', 'offset' => 1, 'length' => 6]]],
    ['text' => '/start', 'entities' => [$command, ['type' => 'code', 'offset' => 0, 'length' => 6]]],
    ['text' => '/start', 'entities' => 'invalid'],
];
$transport->queue = [
    $payload(30, 30, ['text' => '/start']),
    $payload(31, 31, ['text' => '/start', 'entities' => [$command]]),
];
$repliesBefore = count($transport->texts);
++$clockTime;
$runner->runIteration();
verify($cursor($pdo) === [32, $clockTime] && $counts($pdo) === [3, 3], 'Команда /start не обновила курсор или создала задание.');
foreach ($vacancies as $index => $content) {
    $transport->queue[] = $payload(32 + $index, 32 + $index, $content);
}
$runner->runIteration();
$storedBodies = testQuery($pdo, 'SELECT body FROM messages WHERE recipient_message_id >= 30 ORDER BY recipient_message_id')
    ->fetchAll(PDO::FETCH_COLUMN);
verify(
    $storedBodies === array_map(static fn(array $content): string => $content['text'] ?? $content['caption'], $vacancies),
    'Polling потерял вакансии с префиксом или сохранил команду /start как вакансию.',
);
verify($counts($pdo) === [3 + count($vacancies), 3 + count($vacancies)]
    && $cursor($pdo) === [32 + count($vacancies), $clockTime], 'Команда или вакансия нарушила очередь либо курсор.');
verify($transport->repliesSince($repliesBefore) === [
    'Перешли сюда вакансию. Я сохраню текст и отвечу подтверждением.',
    'Перешли сюда вакансию. Я сохраню текст и отвечу подтверждением.',
    ...array_fill(0, count($vacancies), 'Вакансия сохранена.'),
], 'Для команд и вакансий отправлены неверные подтверждения.');

fwrite(STDOUT, "Проверка обработки обновлений: OK\n");

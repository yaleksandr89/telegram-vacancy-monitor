<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Application\BotRunner;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\Update;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

$transport = new class implements HttpTransport {
    public int $replies = 0;

    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        if (!str_ends_with($url, '/sendMessage')) {
            throw new RuntimeException('Неожиданный HTTP-метод.');
        }

        ++$this->replies;

        return new HttpResponse(200, '{"ok":true,"result":{"message_id":20}}');
    }
};

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
new SqliteSchema($pdo)->ensure();
$repository = new SqliteInboxRepository($pdo);
$runner = new BotRunner(new BotApiClient('123:test-secret', $transport), $repository, 123);

$makeUpdate = static fn (int $id, int $sender, int $recipientMessageId, int $originMessageId): Update =>
    Update::fromArray([
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
    || (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() !== 2
    || $transport->replies !== 3
) {
    throw new RuntimeException('Ошибка разрешений, дедупликации, ответов или курсора.');
}

fwrite(STDOUT, "Проверка обработки обновлений: OK\n");

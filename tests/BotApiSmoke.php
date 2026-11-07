<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\SendMessageRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

$transport = new class implements HttpTransport {
    /** @var list<HttpResponse> */
    public array $responses = [];

    /** @var list<array{url: string, json: string, timeout: int}> */
    public array $calls = [];

    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'json' => $json, 'timeout' => $timeoutSeconds];

        return array_shift($this->responses) ?? throw new RuntimeException('Неожиданный сетевой вызов.');
    }
};

$bot = new BotApiClient('123:test-secret', $transport);
$transport->responses[] = new HttpResponse(200, json_encode([
    'ok' => true,
    'result' => [[
        'update_id' => 40,
        'message' => [
            'message_id' => 8,
            'chat' => ['id' => 123, 'type' => 'private'],
            'from' => ['id' => 123],
            'text' => 'PHP-разработчик',
            'forward_origin' => [
                'type' => 'channel',
                'chat' => ['id' => -100456],
                'message_id' => 77,
            ],
        ],
    ]],
], JSON_THROW_ON_ERROR));

$updates = $bot->getUpdates(new GetUpdatesRequest(40, 25));

if (
    count($updates) !== 1
    || $updates[0]->id !== 40
    || $updates[0]->message?->originChatId !== -100456
    || $updates[0]->message->originMessageId !== 77
    || $transport->calls[0]['timeout'] !== 35
) {
    throw new RuntimeException('Типизированное чтение обновлений работает неправильно.');
}

$transport->responses[] = new HttpResponse(200, '{"ok":true,"result":{"message_id":9}}');
$bot->sendMessage(new SendMessageRequest(123, 'Сохранено.'));

$transport->responses[] = new HttpResponse(401, '{"ok":false,"error_code":401,"description":"Unauthorized"}');

try {
    $bot->getUpdates(new GetUpdatesRequest(41, 0));
    throw new RuntimeException('Ошибка авторизации не обнаружена.');
} catch (BotApiException $exception) {
    if ($exception->apiCode !== 401 || str_contains($exception->getMessage(), 'test-secret')) {
        throw new RuntimeException('Неинформативная ошибка API или утечка токена.');
    }
}

$transport->responses[] = new HttpResponse(200, '{bad json');

try {
    $bot->getUpdates(new GetUpdatesRequest(41, 0));
    throw new RuntimeException('Некорректный JSON не обнаружен.');
} catch (UnexpectedResponseException) {
    // EN: Malformed responses are distinct from API rejections.
    // RU: Некорректные ответы отличаются от отказов API.
}

fwrite(STDOUT, "Проверка Bot API DTO: OK\n");

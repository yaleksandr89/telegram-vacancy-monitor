<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Application\Exception\StartupException;
use Yaleksandr\TelegramVacancyMonitor\Application\WebhookCommand;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\UnexpectedResponseException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @phpstan-assert true $condition */
function verifyWebhook(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$transport = new class implements HttpTransport {
    /** @var list<HttpResponse|BotTransportException> */
    public array $responses = [];

    /** @var list<array{url: string, json: string}> */
    public array $calls = [];

    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'json' => $json];
        $response = array_shift($this->responses) ?? throw new RuntimeException('Неожиданный вызов Bot API.');
        if ($response instanceof BotTransportException) {
            throw $response;
        }

        return $response;
    }
};

$token = '123:fake-token';
$secret = 'fake_webhook-secret';
$url = 'https://bot.example.org:8443/webhook.php';
$bot = new BotApiClient($token, $transport);
$success = new HttpResponse(200, '{"ok":true,"result":true}');
$transport->responses = [
    $success,
    new HttpResponse(200, '{"ok":true,"result":{"url":"","pending_update_count":4}}'),
    $success,
];
$bot->setWebhook($url, $secret);
$info = $bot->getWebhookInfo();
$bot->deleteWebhook();

verifyWebhook(
    !$info->configured && $info->pendingUpdateCount === 4 && !$info->hasDeliveryError && $info->lastErrorDate === null,
    'Неверные сведения о незарегистрированном webhook.',
);
verifyWebhook(
    array_column($transport->calls, 'url') === [
        'https://api.telegram.org/bot123:fake-token/setWebhook',
        'https://api.telegram.org/bot123:fake-token/getWebhookInfo',
        'https://api.telegram.org/bot123:fake-token/deleteWebhook',
    ],
    'Неверные пути запросов управления webhook.',
);
verifyWebhook(
    json_decode($transport->calls[0]['json'], true, flags: JSON_THROW_ON_ERROR) === [
        'url' => $url,
        'secret_token' => $secret,
        'allowed_updates' => ['message'],
        'drop_pending_updates' => false,
        'max_connections' => 1,
    ]
    && $transport->calls[1]['json'] === '{}'
    && json_decode($transport->calls[2]['json'], true, flags: JSON_THROW_ON_ERROR) === ['drop_pending_updates' => false],
    'Неверные JSON-параметры запросов управления webhook.',
);

$registered = ['url' => $url, 'pending_update_count' => 7];
foreach ([
    [[], null, false],
    [['last_error_date' => 1700000000], 1700000000, true],
    [['last_error_message' => 'Ошибка доставки.'], null, true],
] as [$fields, $date, $hasError]) {
    $transport->responses[] = new HttpResponse(200, json_encode(
        ['ok' => true, 'result' => $registered + $fields],
        JSON_THROW_ON_ERROR,
    ));
    $info = $bot->getWebhookInfo();
    verifyWebhook(
        $info->configured && $info->pendingUpdateCount === 7
        && $info->hasDeliveryError === $hasError && $info->lastErrorDate === $date,
        'Неверные сведения о зарегистрированном webhook.',
    );
}

foreach ([
    null,
    [],
    ['url' => false, 'pending_update_count' => 0],
    ['url' => '', 'pending_update_count' => '0'],
    ['url' => '', 'pending_update_count' => -1],
    $registered + ['last_error_date' => '1700000000'],
    $registered + ['last_error_date' => null],
    $registered + ['last_error_date' => -1],
    $registered + ['last_error_message' => ['текст ошибки']],
] as $malformed) {
    $transport->responses[] = new HttpResponse(200, json_encode(
        ['ok' => true, 'result' => $malformed],
        JSON_THROW_ON_ERROR,
    ));
    try {
        $bot->getWebhookInfo();
        throw new RuntimeException('Принят некорректный статус webhook.');
    } catch (UnexpectedResponseException) {
    }
}

foreach (['false', '1', '"true"'] as $result) {
    foreach ([$bot->deleteWebhook(...), static fn() => $bot->setWebhook($url, $secret)] as $changeWebhook) {
        $transport->responses[] = new HttpResponse(200, '{"ok":true,"result":' . $result . '}');
        try {
            $changeWebhook();
            throw new RuntimeException('Операция принята без подтверждения true.');
        } catch (UnexpectedResponseException) {
        }
    }
}

$sensitive = "$token $secret $url";
foreach ([
    [new HttpResponse(502, '{"ok":true,"result":true}'), 'HTTP 502'],
    [new HttpResponse(200, json_encode([
        'ok' => false,
        'error_code' => 400,
        'description' => $sensitive,
    ], JSON_THROW_ON_ERROR)), 'сертификат TLS'],
    [new HttpResponse(200, '{bad JSON ' . $sensitive), 'некорректный ответ'],
    [new BotTransportException('Тайм-аут ' . $sensitive), 'Проверьте соединение'],
] as [$response, $hint]) {
    $transport->responses[] = $response;
    try {
        WebhookCommand::fromArguments(['set', $url])->execute($bot, [
            'TELEGRAM_RECEIVE_MODE' => 'webhook',
            'TELEGRAM_WEBHOOK_SECRET' => $secret,
        ]);
        throw new RuntimeException('Сбой Bot API не обнаружен.');
    } catch (StartupException $exception) {
        $message = $exception->getMessage();
        verifyWebhook(
            str_contains($message, $hint)
            && !str_contains($message, $token) && !str_contains($message, $secret) && !str_contains($message, $url)
            && !str_contains($message, 'bad JSON'),
            'Ошибка команды не содержит нужной подсказки или раскрывает данные запроса.',
        );
    }
}

/** @param list<string> $arguments */
function verifyWebhookCli(string $root, array $arguments, int $code, string $out, string $error = ''): void
{
    $process = proc_open(
        [PHP_BINARY, '-d', 'disable_functions=curl_init', $root . '/bin/bot.php', 'webhook', ...$arguments],
        [STDIN, ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $root,
    );
    verifyWebhook(is_resource($process), 'Не удалось запустить CLI webhook.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $expectedOut = $out === '' ? '' : $out . PHP_EOL;
    $expectedError = $error === '' ? '' : $error . PHP_EOL;
    verifyWebhook(
        $exitCode === $code && $stdout === $expectedOut && $stderr === $expectedError,
        'Неожиданный код завершения или вывод CLI webhook (' . ($arguments[0] ?? 'без подкоманды') . ').',
    );
}

$project = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/vacancy-webhook-' . bin2hex(random_bytes(8));
verifyWebhook(mkdir($fixture, 0700), 'Не удалось создать временный каталог CLI.');
try {
    foreach (['bin', 'config', 'vendor'] as $directory) {
        verifyWebhook(mkdir($fixture . '/' . $directory, 0700), 'Не удалось подготовить временный каталог CLI.');
    }
    verifyWebhook(copy($project . '/bin/bot.php', $fixture . '/bin/bot.php'), 'Не удалось скопировать точку входа CLI.');
    $autoload = "<?php\nrequire " . var_export($project . '/vendor/autoload.php', true) . ";\n" . <<<'PHP'
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotTransportException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\CurlTransport;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\HttpTransport;

final class CliWebhookTransport implements HttpTransport
{
    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse
    {
        $method = basename($url);
        fwrite(STDERR, "Тестовый вызов Bot API ($method)." . PHP_EOL);
        if (str_contains($url, 'fake-timeout')) {
            throw new BotTransportException('Тайм-аут ' . $url . ' fake_webhook-secret https://bot.example.org/private');
        }

        return new HttpResponse(200, match ($method) {
            'setWebhook', 'deleteWebhook' => '{"ok":true,"result":true}',
            'getWebhookInfo' => str_contains($url, 'fake-unregistered')
                ? '{"ok":true,"result":{"url":"","pending_update_count":4}}'
                : '{"ok":true,"result":{"url":"https://bot.example.org/123:fake-token/fake_webhook-secret",'
                    . '"pending_update_count":7,"last_error_date":1700000000,"last_error_message":"Не выводить этот текст"}}',
            default => throw new RuntimeException('Неожиданный метод Bot API.'),
        });
    }
}

class_alias(CliWebhookTransport::class, CurlTransport::class);
PHP;
    verifyWebhook(file_put_contents($fixture . '/vendor/autoload.php', $autoload) !== false, 'Не удалось подключить тестовый транспорт.');

    $usage = 'Использование: php bin/bot.php webhook set <https-url> | info | delete';
    foreach ([[], ['list'], ['set'], ['set', $url, 'extra'], ['info', 'extra'], ['delete', 'extra']] as $arguments) {
        verifyWebhookCli($fixture, $arguments, 1, '', 'Ошибка: ' . $usage);
    }

    $configPath = $fixture . '/config/bot.ini';
    $writeConfig = static function (string $contents) use ($configPath): void {
        verifyWebhook(file_put_contents($configPath, $contents) !== false, 'Не удалось записать тестовый INI.');
    };
    $tokenLine = "TELEGRAM_BOT_TOKEN = \"$token\"\n";
    $writeConfig($tokenLine . 'TELEGRAM_RECEIVE_MODE = "polling"');
    $urlError = 'Ошибка: Укажите HTTPS-адрес с публичным DNS-именем и портом 443, 80, 88 или 8443, без учётных данных, запроса и фрагмента.'
        . PHP_EOL . $usage;
    foreach ([
        '',
        'http://bot.example.org/webhook.php',
        'https:///webhook.php',
        'https://localhost/webhook.php',
        'https://bot.localhost./webhook.php',
        'https://bot.internal/webhook.php',
        'https://bot.home.arpa/webhook.php',
        'https://127.0.0.1/webhook.php',
        'https://10.0.0.1/webhook.php',
        'https://0x7f.0x1/webhook.php',
        'https://[::1]/webhook.php',
        'https://8.8.8.8/webhook.php',
        'https://name:password@bot.example.org/webhook.php',
        $url . '?token=private',
        $url . '?',
        $url . '#fragment',
        $url . '#',
        'https://bot.example.org:8080/webhook.php',
        'https://bot.example.org:/webhook.php',
        $url . '/%zz',
        $url . '/\path',
    ] as $invalidUrl) {
        verifyWebhookCli($fixture, ['set', $invalidUrl], 1, '', $urlError);
    }

    verifyWebhookCli($fixture, ['set', $url], 1, '', 'Ошибка: Для регистрации укажите TELEGRAM_RECEIVE_MODE=webhook в config/bot.ini.');
    $status = 'Webhook зарегистрирован. Ожидающих обновлений: 7. Последняя ошибка доставки: 2023-11-14 22:13:20 UTC.';
    verifyWebhookCli($fixture, ['info'], 0, $status, 'Тестовый вызов Bot API (getWebhookInfo).');
    verifyWebhookCli($fixture, ['delete'], 0, 'Webhook удалён. Ожидающие обновления сохранены.', 'Тестовый вызов Bot API (deleteWebhook).');

    $webhookConfig = $tokenLine . "TELEGRAM_RECEIVE_MODE = \"webhook\"\n";
    $writeConfig($webhookConfig);
    $secretError = 'Ошибка: Укажите TELEGRAM_WEBHOOK_SECRET: 1–256 символов A–Z, a–z, 0–9, _ или -.';
    verifyWebhookCli($fixture, ['set', $url], 1, '', $secretError);
    verifyWebhookCli($fixture, ['delete'], 0, 'Webhook удалён. Ожидающие обновления сохранены.', 'Тестовый вызов Bot API (deleteWebhook).');
    foreach (['invalid secret', str_repeat('s', 257)] as $invalidSecret) {
        $writeConfig($webhookConfig . "TELEGRAM_WEBHOOK_SECRET = \"$invalidSecret\"");
        verifyWebhookCli($fixture, ['set', $url], 1, '', $secretError);
    }

    $config = $webhookConfig . "TELEGRAM_WEBHOOK_SECRET = \"$secret\"\n";
    $writeConfig($config);
    foreach ([
        $url,
        'https://bot.example.org/webhook.php',
        'https://bot.example.org:443/webhook.php',
        'https://bot.example.org:80/webhook.php',
        'https://bot.example.org:88/webhook%20delivery',
    ] as $validUrl) {
        verifyWebhookCli($fixture, ['set', $validUrl], 0, 'Webhook зарегистрирован.', 'Тестовый вызов Bot API (setWebhook).');
    }
    verifyWebhook(file_get_contents($configPath) === $config, 'CLI изменил конфигурацию.');
    $writeConfig("TELEGRAM_BOT_TOKEN = \"123:fake-unregistered\"\nTELEGRAM_RECEIVE_MODE = \"invalid\"\n");
    verifyWebhookCli(
        $fixture,
        ['info'],
        0,
        'Webhook не зарегистрирован. Ожидающих обновлений: 4. Сведений об ошибках доставки нет.',
        'Тестовый вызов Bot API (getWebhookInfo).',
    );
    $writeConfig('TELEGRAM_BOT_TOKEN = "invalid-token"');
    verifyWebhookCli($fixture, ['delete'], 1, '', 'Ошибка: Укажите корректный TELEGRAM_BOT_TOKEN в config/bot.ini.');
    $writeConfig('TELEGRAM_BOT_TOKEN = "123:fake-timeout"');
    verifyWebhookCli(
        $fixture,
        ['info'],
        1,
        '',
        'Тестовый вызов Bot API (getWebhookInfo).' . PHP_EOL
        . 'Ошибка: Сетевая ошибка Bot API. Проверьте соединение и сертификаты, затем запросите статус webhook.',
    );
    verifyWebhook(!file_exists($fixture . '/var'), 'CLI webhook попытался создать каталог состояния приложения.');
} finally {
    foreach (['bin/bot.php', 'config/bot.ini', 'vendor/autoload.php'] as $file) {
        if (is_file($fixture . '/' . $file)) {
            unlink($fixture . '/' . $file);
        }
    }
    foreach (['bin', 'config', 'vendor'] as $directory) {
        if (is_dir($fixture . '/' . $directory)) {
            rmdir($fixture . '/' . $directory);
        }
    }
    rmdir($fixture);
}

fwrite(STDOUT, "Проверка управления webhook: OK\n");

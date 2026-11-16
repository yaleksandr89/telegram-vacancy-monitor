<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Application\IncomingUpdateProcessor;
use Yaleksandr\TelegramVacancyMonitor\Application\WebhookReceiver;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\CurlTransport;

ini_set('display_errors', '0');
$root = dirname(__DIR__);
$status = 503;

try {
    require $root . '/vendor/autoload.php';
    $config = @parse_ini_file($root . '/config/bot.ini', false, INI_SCANNER_RAW);
    if (!is_array($config) || ($config['TELEGRAM_RECEIVE_MODE'] ?? 'polling') !== 'webhook') {
        throw new RuntimeException('Режим webhook не настроен.');
    }
    $secret = $config['TELEGRAM_WEBHOOK_SECRET'] ?? null;
    if (!is_string($secret)) {
        throw new RuntimeException('Секрет webhook не настроен.');
    }

    $receiver = new WebhookReceiver($secret, static function () use ($root, $config): IncomingUpdateProcessor {
        $token = $config['TELEGRAM_BOT_TOKEN'] ?? null;
        $allowedUserId = filter_var($config['TELEGRAM_ALLOWED_USER_ID'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!is_string($token) || preg_match('/\A\d+:[A-Za-z0-9_-]+\z/D', $token) !== 1 || !is_int($allowedUserId)) {
            throw new RuntimeException('Параметры бота не настроены.');
        }

        // RU: SQLite создаёт журнал рядом с БД; доступ к каталогу выдаётся оператором заранее.
        // EN: SQLite creates its journal beside the database; the operator provisions directory access first.
        $database = $root . '/var/vacancies.sqlite';
        if (!is_file($database)) {
            throw new RuntimeException('Хранилище не инициализировано.');
        }
        umask(0077);
        $pdo = new PDO('sqlite:' . $database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = FULL');
        new SqliteSchema($pdo)->ensure();

        return new IncomingUpdateProcessor(
            new BotApiClient($token, new CurlTransport()),
            new SqliteInboxRepository($pdo),
            $allowedUserId,
        );
    });
    $status = $receiver->receive(
        $_SERVER['REQUEST_METHOD'] ?? '',
        $_SERVER['CONTENT_TYPE'] ?? '',
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null,
        static function (): string {
            $body = file_get_contents('php://input', length: WebhookReceiver::MAX_BODY_BYTES + 1);
            if ($body === false) {
                throw new RuntimeException('Не удалось прочитать запрос.');
            }
            return $body;
        },
    );
} catch (Throwable) {
    error_log('Webhook не готов к приёму обновлений.');
}

http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if ($status === 405) {
    header('Allow: POST');
}
if ($status !== 204) {
    echo 'Запрос не принят.';
}

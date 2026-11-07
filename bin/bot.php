<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Application\BotRunner;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\StartupException;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\CurlTransport;

if (PHP_SAPI !== 'cli') {
    exit('Команда предназначена только для CLI.' . PHP_EOL);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $mode = $argv[1] ?? 'run';

    if (!in_array($mode, ['identify', 'run'], true)) {
        throw new ConfigurationException('Использование: php bin/bot.php identify|run');
    }

    $path = $root . '/config/bot.ini';

    if (!is_file($path)) {
        throw new ConfigurationException(
            'Создайте config/bot.ini по образцу config/bot.example.ini.',
        );
    }

    $config = parse_ini_file($path, false, INI_SCANNER_RAW);

    if ($config === false) {
        throw new ConfigurationException(
            'Не удалось прочитать конфигурацию config/bot.ini.',
        );
    }

    $token = is_array($config) ? ($config['TELEGRAM_BOT_TOKEN'] ?? null) : null;

    if (!is_string($token) || preg_match('/\A\d+:[A-Za-z0-9_-]+\z/D', $token) !== 1) {
        throw new ConfigurationException('Укажите корректный TELEGRAM_BOT_TOKEN в config/bot.ini.');
    }

    $bot = new BotApiClient($token, new CurlTransport());

    if ($mode === 'identify') {
        // EN: This setup command does not acknowledge pending /start updates.
        // RU: Команда настройки не подтверждает ожидающие обновления /start.
        $found = false;

        foreach ($bot->getUpdates(new GetUpdatesRequest(0, 0)) as $update) {
            $message = $update->message;

            if (
                $message === null
                || $message->chatType !== 'private'
                || $message->senderId === null
                || $message->body === null
                || !str_starts_with($message->body, '/start')
            ) {
                continue;
            }

            printf("ID отправителя /start: %d\n", $message->senderId);
            $found = true;
        }

        if (!$found) {
            fwrite(STDOUT, "Сообщений /start пока нет. Отправьте /start боту и повторите команду.\n");
        }

        exit(0);
    }

    $allowedUserId = filter_var($config['TELEGRAM_ALLOWED_USER_ID'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (!is_int($allowedUserId)) {
        throw new ConfigurationException('Укажите TELEGRAM_ALLOWED_USER_ID в config/bot.ini.');
    }

    // EN: Both the SQLite database and process lock belong to this local runtime directory.
    // RU: База SQLite и блокировка процесса располагаются в локальном каталоге данных.
    umask(0077);
    $runtime = $root . '/var';

    if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) {
        throw new StartupException('Не удалось создать приватный каталог var/.');
    }

    $lock = fopen($runtime . '/bot.lock', 'c');

    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new StartupException('Бот уже запущен либо недоступна блокировка var/bot.lock.');
    }

    $pdo = new PDO('sqlite:' . $runtime . '/vacancies.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    new SqliteSchema($pdo)->ensure();

    fwrite(STDOUT, "Бот запущен. Перешлите ему сообщение. Для остановки нажмите Ctrl+C.\n");
    new BotRunner($bot, new SqliteInboxRepository($pdo), $allowedUserId)->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Ошибка: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

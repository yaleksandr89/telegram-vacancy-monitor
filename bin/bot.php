<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResultValidator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\CandidateProfileLoader;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ConservativePrefilter;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationInstructionsLoader;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationPromptBuilder;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\CurlAiTransport;
use Yaleksandr\TelegramVacancyMonitor\Analysis\OpenAiCompatibleEvaluator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ProviderLocation;
use Yaleksandr\TelegramVacancyMonitor\Application\BotRunner;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\StartupException;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyAnalysisPipeline;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyResultFormatter;
use Yaleksandr\TelegramVacancyMonitor\Application\WebhookCommand;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteAnalysisRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteInboxRepository;
use Yaleksandr\TelegramVacancyMonitor\Storage\SqliteSchema;
use Yaleksandr\TelegramVacancyMonitor\Telegram\BotApiClient;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Dto\GetUpdatesRequest;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Exception\BotApiException;
use Yaleksandr\TelegramVacancyMonitor\Telegram\Http\CurlTransport;

if (PHP_SAPI !== 'cli') {
    exit('Команда предназначена только для CLI.' . PHP_EOL);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$identifyJson = ($argv[1] ?? null) === 'identify' && ($argv[2] ?? null) === '--json';
$mode = $argv[1] ?? 'run';

try {
    if (!in_array($mode, ['identify', 'run', 'work-once', 'webhook'], true)) {
        throw new ConfigurationException('Использование: php bin/bot.php identify|run|work-once|webhook');
    }
    $webhookCommand = $mode === 'webhook' ? WebhookCommand::fromArguments(array_slice($argv, 2)) : null;

    $path = $root . '/config/bot.ini';

    if (!is_file($path)) {
        throw new ConfigurationException(
            'Создайте config/bot.ini по образцу config/bot.example.ini.',
        );
    }

    $config = ($identifyJson || $webhookCommand !== null)
        ? @parse_ini_file($path, false, INI_SCANNER_RAW)
        : parse_ini_file($path, false, INI_SCANNER_RAW);

    if ($config === false) {
        throw new ConfigurationException(
            'Не удалось прочитать конфигурацию config/bot.ini.',
        );
    }

    if ($webhookCommand === null) {
        $receiveMode = $config['TELEGRAM_RECEIVE_MODE'] ?? 'polling';
        if (!in_array($receiveMode, ['polling', 'webhook'], true)) {
            throw new ConfigurationException('Укажите TELEGRAM_RECEIVE_MODE: polling или webhook.');
        }
        if ($receiveMode === 'webhook') {
            if ($mode !== 'work-once') {
                throw new ConfigurationException('В режиме webhook используйте work-once. Для run/identify сначала снимите webhook методом deleteWebhook (drop_pending_updates=false), затем выберите polling.');
            }
            $secret = $config['TELEGRAM_WEBHOOK_SECRET'] ?? null;
            if (!is_string($secret) || preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $secret) !== 1) {
                throw new ConfigurationException('Укажите TELEGRAM_WEBHOOK_SECRET: 1–256 символов A–Z, a–z, 0–9, _ или -.');
            }
        } elseif ($mode === 'work-once') {
            throw new ConfigurationException('Команда work-once предназначена для TELEGRAM_RECEIVE_MODE=webhook.');
        }
    }

    $token = is_array($config) ? ($config['TELEGRAM_BOT_TOKEN'] ?? null) : null;

    if (!is_string($token) || preg_match('/\A\d+:[A-Za-z0-9_-]+\z/D', $token) !== 1) {
        throw new ConfigurationException('Укажите корректный TELEGRAM_BOT_TOKEN в config/bot.ini.');
    }

    $bot = new BotApiClient($token, new CurlTransport());

    if ($webhookCommand !== null) {
        fwrite(STDOUT, $webhookCommand->execute($bot, $config) . PHP_EOL);
        exit(0);
    }

    if ($mode === 'identify') {
        // RU: Команда настройки не подтверждает ожидающие обновления /start.
        // EN: This setup command does not acknowledge pending /start updates.
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

            if ($identifyJson) {
                $ids[] = $message->senderId;
            } else {
                printf("ID отправителя /start: %d\n", $message->senderId);
            }
            $found = true;
        }

        if ($identifyJson) {
            fwrite(STDOUT, json_encode(['status' => $found ? 'found' : 'empty', 'ids' => array_values(array_unique($ids ?? []))], JSON_THROW_ON_ERROR) . PHP_EOL);
        } elseif (!$found) {
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

    $requestTimeout = array_key_exists('AI_REQUEST_TIMEOUT_SECONDS', $config)
        ? $config['AI_REQUEST_TIMEOUT_SECONDS']
        : (string) CurlAiTransport::DEFAULT_REQUEST_TIMEOUT_SECONDS;
    if (
        !is_string($requestTimeout)
        || preg_match('/\A[0-9]+\z/D', $requestTimeout) !== 1
        || (float) $requestTimeout < 30
        || (float) $requestTimeout > 1800
    ) {
        throw new ConfigurationException('Укажите AI_REQUEST_TIMEOUT_SECONDS целым числом от 30 до 1800 секунд.');
    }
    $requestTimeoutSeconds = (int) $requestTimeout;

    $profile = new CandidateProfileLoader()->load(
        $root . '/config/profile/local/candidate.md',
    );

    $instructions = new EvaluationInstructionsLoader()->load(
        $root . '/config/profile/local/instructions.md',
    );

    $maxAttempts = filter_var($config['AI_MAX_ATTEMPTS'] ?? '3', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 5],
    ]);

    if (!is_int($maxAttempts)) {
        throw new ConfigurationException('Проверьте AI_MAX_ATTEMPTS (1–5).');
    }

    foreach (['AI_BASE_URL', 'AI_MODEL', 'AI_API_KEY', 'AI_PROVIDER_LOCATION'] as $key) {
        if (isset($config[$key]) && !is_string($config[$key])) {
            throw new ConfigurationException('Параметры подключения ИИ должны быть строками INI.');
        }
    }
    $rejectLines = $config['AI_PREFILTER_EXACT_REJECT_LINES'] ?? [];
    $ambiguityTerms = $config['AI_PREFILTER_AMBIGUITY_TERMS'] ?? [];
    if (!is_array($rejectLines) || !is_array($ambiguityTerms)) {
        throw new ConfigurationException('Для списков предфильтра используйте повторяемые ключи с [].');
    }
    $prefilter = new ConservativePrefilter($rejectLines, $ambiguityTerms);
    $evaluator = new OpenAiCompatibleEvaluator(
        new CurlAiTransport($requestTimeoutSeconds),
        new AnalysisResultValidator(),
        new EvaluationPromptBuilder($instructions),
        $config['AI_BASE_URL'] ?? '',
        $config['AI_MODEL'] ?? '',
        $config['AI_API_KEY'] ?? null,
        ProviderLocation::fromConfig($config['AI_PROVIDER_LOCATION'] ?? 'local'),
    );

    // RU: База SQLite и блокировка процесса располагаются в локальном каталоге данных.
    // EN: Both the SQLite database and process lock belong to this local runtime directory.
    umask(0077);
    $runtime = $root . '/var';

    if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) {
        throw new StartupException('Не удалось создать приватный каталог var/.');
    }

    $lock = fopen($runtime . '/bot.lock', 'c');

    if ($lock === false || ($mode === 'run' && !flock($lock, LOCK_EX | LOCK_NB))) {
        throw new StartupException('Бот уже запущен либо недоступна блокировка var/bot.lock.');
    }

    $pdo = new PDO('sqlite:' . $runtime . '/vacancies.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = FULL');
    new SqliteSchema($pdo)->ensure();

    $pipeline = new VacancyAnalysisPipeline(
        new SqliteAnalysisRepository($pdo),
        $profile,
        $prefilter,
        $evaluator,
        new VacancyResultFormatter(),
        $bot,
        $maxAttempts,
    );
    $runner = new BotRunner($bot, new SqliteInboxRepository($pdo), $allowedUserId, $pipeline);
    if ($mode === 'work-once') {
        $worked = $runner->workOnce($lock);
        fwrite(STDOUT, $worked ? "Обработка batch завершена.\n" : "Воркер занят; этот запуск пропущен.\n");
        exit(0);
    }
    $pipeline->recover(time());

    fwrite(STDOUT, "Бот запущен. Перешлите ему сообщение. Для остановки нажмите Ctrl+C.\n");
    $runner->run();
} catch (Throwable $exception) {
    if ($mode === 'webhook') {
        $message = $exception instanceof ConfigurationException || $exception instanceof StartupException
            ? $exception->getMessage()
            : 'Не удалось выполнить команду webhook. Проверьте конфигурацию и доступность Bot API.';
        fwrite(STDERR, 'Ошибка: ' . $message . PHP_EOL);
        exit(1);
    }
    if ($identifyJson) {
        fwrite(STDOUT, "{\"status\":\"error\",\"ids\":[]}\n");
        exit(1);
    }
    if ($exception instanceof BotApiException && $exception->apiCode === 409) {
        fwrite(STDERR, "Проверьте отсутствие другого polling-процесса. Если зарегистрирован webhook, сначала снимите его методом deleteWebhook (drop_pending_updates=false).\n");
    }
    fwrite(STDERR, 'Ошибка: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

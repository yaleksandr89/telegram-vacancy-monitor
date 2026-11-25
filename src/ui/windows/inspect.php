<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResultValidator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ConservativePrefilter;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationInstructions;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationPromptBuilder;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpTransport;
use Yaleksandr\TelegramVacancyMonitor\Analysis\OpenAiCompatibleEvaluator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ProviderLocation;

error_reporting(0);
$root = dirname(__DIR__, 3);
$argv ??= [];
$action = $argv[1] ?? 'check';

function phpVersionSupported(int $versionId, bool $bundle): bool
{
    return $bundle ? $versionId >= 80513 && $versionId < 80600 : $versionId >= 80500 && $versionId < 90000;
}

function diagnosticPath(string $path, string $root): bool
{
    $directory = realpath(sys_get_temp_dir());
    return $directory !== false && realpath(dirname($path)) === $directory
        && (PHP_OS_FAMILY === 'Windows'
            ? preg_match('/\Atvm[0-9A-Za-z]+\.tmp\z/iD', basename($path)) === 1
            : str_starts_with(basename($path), 'tvm-diag-'))
        && is_file($path) && !is_link($path)
        && !str_starts_with(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $path)), strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $root)) . '/');
}

function diagnosticCategory(string $text, string $previous): string
{
    // RU: Распознаём только категории; ни фрагмент ошибки, ни значение настройки не возвращаются.
    // EN: Recognize categories only; no error fragment or setting value is returned.
    if (preg_match('/Bot API \\(cURL ([0-9]{1,3})\\)/', $text, $match) === 1) {
        return in_array((int) $match[1], [35, 51, 58, 60, 77, 83, 90, 91], true) ? 'tls' : 'network';
    }
    if (str_contains($text, 'другого polling-процесса') || str_contains($text, 'Бот уже запущен')
        || preg_match('/Ошибка Bot API \\(HTTP [0-9]+, API 409\\)/', $text) === 1) {
        return 'conflict';
    }
    if (preg_match('/Ошибка Bot API \\(HTTP [0-9]+, API (401|403|429)\\)/', $text, $match) === 1) {
        return match ($match[1]) {
            '401' => 'auth', '403' => 'access', '429' => 'rate',
        };
    }
    if (str_contains($text, 'Ошибка: Укажите') || str_contains($text, 'config/bot.ini')
        || str_contains($text, 'файл профиля') || str_contains($text, 'профиль кандидата')
        || str_contains($text, 'инструкций') || str_contains($text, 'предфильтра')) {
        return 'config';
    }
    if (str_contains($text, 'SQLSTATE') || str_contains($text, 'SQLite') || str_contains($text, 'каталог var/')) {
        return 'storage';
    }
    return $previous;
}

if ($action === 'version-policy') {
    $id = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($id)) {
        exit(1);
    }
    echo json_encode(['developer' => phpVersionSupported($id, false), 'bundle' => phpVersionSupported($id, true)], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($action === 'diagnostic-create') {
    $temporary = realpath(sys_get_temp_dir());
    $normalized = $temporary === false ? '' : strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $temporary));
    $normalizedRoot = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $root));
    if ($temporary === false || $normalized === $normalizedRoot || str_starts_with($normalized, $normalizedRoot . '/')) {
        exit(1);
    }
    $file = @tempnam($temporary, 'tvm-diag-');
    if (!is_string($file) || !diagnosticPath($file, $root)) {
        if (is_string($file)) {
            @unlink($file);
        }
        exit(1);
    }
    echo $file;
    exit(0);
}
if (in_array($action, ['diagnostic-read', 'diagnostic-delete', 'execute'], true)) {
    $file = $argv[$action === 'execute' ? 3 : 2] ?? '';
    if (!diagnosticPath($file, $root)) {
        exit(1);
    }
    if ($action === 'diagnostic-delete') {
        exit(@unlink($file) ? 0 : 1);
    }
    if ($action === 'diagnostic-read') {
        $result = json_decode((string) @file_get_contents($file, length: 256), true);
        $categories = ['unknown', 'runtime', 'config', 'conflict', 'auth', 'access', 'rate', 'tls', 'network', 'storage'];
        if (!is_array($result) || !in_array($result['category'] ?? null, $categories, true)
            || !is_int($result['code'] ?? null)) {
            exit(1);
        }
        echo $result['category'] . "\t" . $result['code'];
        exit(0);
    }
    $command = $argv[2] ?? '';
    $prefix = array_slice($argv, 4);
    if (!in_array($command, ['run', 'identify'], true) || $prefix === []) {
        exit(1);
    }
    $category = 'runtime';
    $code = 70;
    $child = null;
    $pipes = [];
    try {
        $arguments = [...$prefix, '-d', 'display_errors=0', '-d', 'display_startup_errors=0', '-d', 'log_errors=0', $root . '/bin/bot.php', $command];
        if ($command === 'identify') {
            $arguments[] = '--json';
        }
        // RU: stdout наследуется напрямую; stderr читается порциями без очереди или журнала.
        // EN: stdout is inherited directly; stderr is drained in chunks without a queue or log.
        $child = @proc_open($arguments, [STDIN, STDOUT, ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
        if (is_resource($child)) {
            $category = 'unknown';
            $tail = '';
            while (!feof($pipes[2])) {
                $chunk = fread($pipes[2], 4096);
                if ($chunk === false) {
                    break;
                }
                $text = $tail . $chunk;
                $category = diagnosticCategory($text, $category);
                $tail = substr($text, -512);
            }
            fclose($pipes[2]);
            $code = proc_close($child);
            $child = null;
        }
    } catch (Throwable) {
        $category = 'runtime';
    } finally {
        if (is_resource($child)) {
            @proc_terminate($child);
            @proc_close($child);
        }
        @file_put_contents($file, json_encode(['category' => $category, 'code' => $code], JSON_THROW_ON_ERROR), LOCK_EX);
    }
    exit($code);
}
$bundle = ($argv[2] ?? '') === 'bundle' || getenv('VM_PORTABLE') === '1'
    || is_file($root . '/runtime/PROVENANCE.json');
$phpSupported = PHP_SAPI === 'cli' && phpVersionSupported(PHP_VERSION_ID, $bundle);

if ($action === 'texts') {
    $messages = file_get_contents(__DIR__ . '/messages.json');
    if ($messages === false) {
        exit(1);
    }
    foreach (json_decode($messages, true, flags: JSON_THROW_ON_ERROR) as $key => $texts) {
        echo $key . "\0" . $texts[0] . "\0" . $texts[1] . "\0";
    }
    exit(0);
}
if ($action === 'ids') {
    try {
        $result = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($result) || !in_array($result['status'] ?? null, ['found', 'empty'], true)
            || !is_array($result['ids'] ?? null) || count($result['ids']) > 100) {
            exit(1);
        }
        foreach ($result['ids'] as $id) {
            if (!is_int($id) || $id < 1) {
                exit(1);
            }
        }
        if (($result['status'] === 'found') !== ($result['ids'] !== [])) {
            exit(1);
        }
        echo implode("\n", $result['ids']);
        exit(0);
    } catch (Throwable) {
        exit(1);
    }
}
if ($action === 'runtime') {
    exit($phpSupported
        && extension_loaded('curl') && extension_loaded('json') && extension_loaded('mbstring')
        && extension_loaded('PDO') && extension_loaded('pdo_sqlite') ? 0 : 1);
}
$path = $root . '/config/bot.ini';
$config = is_file($path) && is_readable($path) ? @parse_ini_file($path, false, INI_SCANNER_RAW) : false;
$mode = is_array($config) ? ($config['TELEGRAM_RECEIVE_MODE'] ?? 'polling') : null;
if ($action === 'mode') {
    if (!in_array($mode, ['polling', 'webhook'], true)) {
        exit(1);
    }
    $token = $config['TELEGRAM_BOT_TOKEN'] ?? null;
    if (!is_string($token) || preg_match('/\\A\\d+:[A-Za-z0-9_-]+\\z/D', $token) !== 1) {
        exit(1);
    }
    echo $mode;
    exit(0);
}

function report(string $key, bool $ok): void
{
    $group = in_array($key, ['php', 'php_bundle', 'extensions', 'autoload'], true) ? 'runtime' : 'config';
    if (in_array($key, ['candidate', 'instructions'], true)) {
        $group = 'profile';
    } elseif (in_array($key, ['storage'], true)) {
        $group = 'storage';
    }
    echo ($ok ? 'ok' : 'fail') . "\t" . $key . "\t" . $group . PHP_EOL;
}

report($bundle ? 'php_bundle' : 'php', $phpSupported);
$extensions = true;
foreach (['curl', 'json', 'mbstring', 'PDO', 'pdo_sqlite'] as $extension) {
    $loaded = extension_loaded($extension);

    $extensions = $extensions && $loaded;
}
$autoload = is_file($root . '/vendor/autoload.php') && is_readable($root . '/vendor/autoload.php');
report('extensions', $extensions);
report('autoload', $autoload);
report('ini', is_array($config));
if (is_array($config)) {
    $secret = $config['TELEGRAM_WEBHOOK_SECRET'] ?? null;
    report('mode', in_array($mode, ['polling', 'webhook'], true) && ($mode !== 'webhook'
        || (is_string($secret) && preg_match('/\A[A-Za-z0-9_-]{1,256}\z/D', $secret) === 1)));
    if ($mode === 'webhook') {
        report('polling', false);
    }
    $token = $config['TELEGRAM_BOT_TOKEN'] ?? null;
    report('token', is_string($token) && preg_match('/\A\d+:[A-Za-z0-9_-]+\z/D', $token) === 1);
    report('user', is_int(filter_var($config['TELEGRAM_ALLOWED_USER_ID'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ])));
    $timeout = $config['AI_REQUEST_TIMEOUT_SECONDS'] ?? '180';
    $attempts = filter_var($config['AI_MAX_ATTEMPTS'] ?? '3', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 5],
    ]);
    report('limits', is_int($attempts) && is_string($timeout) && preg_match('/\A[0-9]+\z/D', $timeout) === 1
        && (float) $timeout >= 30 && (float) $timeout <= 1800);
}
if (is_array($config) && $autoload && $extensions && $phpSupported) {
    try {
        require $root . '/vendor/autoload.php';
        // RU: Для проверки конструктора используем транспорт, который никогда не отправляет запросы.
        // EN: Constructor validation uses a transport that can never send requests.
        $transport = new class implements AiHttpTransport {
            public function postJson(string $url, string $json, ?string $apiKey): AiHttpResponse
            {
                throw new LogicException('Offline readiness transport.');
            }
        };
        new OpenAiCompatibleEvaluator(
            $transport,
            new AnalysisResultValidator(),
            new EvaluationPromptBuilder(new EvaluationInstructions('Offline readiness.')),
            $config['AI_BASE_URL'] ?? '',
            $config['AI_MODEL'] ?? '',
            $config['AI_API_KEY'] ?? null,
            ProviderLocation::fromConfig($config['AI_PROVIDER_LOCATION'] ?? 'local'),
        );
        report('ai', true);
    } catch (Throwable) {
        report('ai', false);
    }
    try {
        new ConservativePrefilter($config['AI_PREFILTER_EXACT_REJECT_LINES'] ?? [], $config['AI_PREFILTER_AMBIGUITY_TERMS'] ?? []);
        report('prefilter', true);
    } catch (Throwable) {
        report('prefilter', false);
    }
} else {
    report('ai', false);
    report('prefilter', false);
}
foreach (['candidate', 'instructions'] as $key) {
    $file = $root . '/config/profile/local/' . $key . '.md';
    report($key, is_file($file) && !is_link($file) && is_readable($file)
        && filesize($file) > 0 && filesize($file) <= 32768);
}
$directory = $root . '/var';
$storage = !file_exists($directory) && !is_link($directory)
    ? is_writable($root) && (PHP_OS_FAMILY === 'Windows' || is_executable($root))
    : is_dir($directory) && !is_link($directory) && is_readable($directory) && is_writable($directory)
        && (PHP_OS_FAMILY === 'Windows' || is_executable($directory));
foreach (['vacancies.sqlite', 'bot.lock', 'vacancies.sqlite-journal', 'vacancies.sqlite-wal', 'vacancies.sqlite-shm'] as $name) {
    $file = $directory . '/' . $name;
    if (file_exists($file) || is_link($file)) {
        $storage = $storage && is_file($file) && !is_link($file) && is_readable($file) && is_writable($file);
    }
}
report('storage', $storage);

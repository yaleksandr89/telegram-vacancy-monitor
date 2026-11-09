<?php

declare(strict_types=1);

use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResult;
use Yaleksandr\TelegramVacancyMonitor\Analysis\AnalysisResultValidator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\CandidateProfile;
use Yaleksandr\TelegramVacancyMonitor\Analysis\CandidateProfileLoader;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ConservativePrefilter;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationInstructions;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationInstructionsLoader;
use Yaleksandr\TelegramVacancyMonitor\Analysis\EvaluationPromptBuilder;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Exception\AiException;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\AiHttpResponse;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Http\CurlAiTransport;
use Yaleksandr\TelegramVacancyMonitor\Analysis\OpenAiCompatibleEvaluator;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Priority;
use Yaleksandr\TelegramVacancyMonitor\Analysis\ProviderLocation;
use Yaleksandr\TelegramVacancyMonitor\Analysis\Verdict;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ConfigurationException;
use Yaleksandr\TelegramVacancyMonitor\Application\Exception\ProfileException;
use Yaleksandr\TelegramVacancyMonitor\Application\VacancyResultFormatter;

require __DIR__ . '/Support/AnalysisFixtures.php';

[$pdo, $ai, $telegram, $pipeline, $runner, $profile] = fixture();
intake($runner, 1, 10, 'Разработка API. https://example.org/vacancy', origin: 8);
verify((int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() === 1, 'Приём не создал задание.');
$ai->beforeCall = static function () use ($pdo): void {
    verify(!$pdo->inTransaction(), 'Запрос ИИ внутри транзакции SQLite.');
};
$telegram->beforeSend = static function () use ($pdo): void {
    verify(!$pdo->inTransaction() && $pdo->query('SELECT analysis_status FROM analysis_jobs LIMIT 1')->fetchColumn() === 'completed', 'Отправка до сохранения результата или внутри транзакции.');
};
$pipeline->processPending(100);
$telegram->beforeSend = null;
$job = $pdo->query('SELECT * FROM analysis_jobs')->fetch(PDO::FETCH_ASSOC);
verify($job['analysis_status'] === 'completed' && $job['delivery_status'] === 'delivered', 'Сквозная обработка не завершена.');
verify((int) $job['analysis_attempts'] === 1 && $job['profile_hash'] === testPrompt()->fingerprint($profile), 'Попытка или версия профиля неверны.');
verify($job['verdict'] === 'apply' && str_contains($job['result_json'], 'API'), 'Результат не сохранён.');
$final = end($telegram->texts);
verify(str_contains($final, 'Откликаться') && str_contains($final, 'Подходящие задачи') && str_contains($final, 'ссылка из объявления (не проверена)') && str_contains($final, 'https://example.org/vacancy'), 'Некорректное уведомление или ссылка.');
$request = json_decode($ai->calls[0]['json'], true, flags: JSON_THROW_ON_ERROR);
verify($request['stream'] === false && array_column($request['messages'], 'role') === ['system', 'user'] && !isset($request['tools']), 'Неверный протокол ИИ.');
verify($ai->calls[0]['url'] === 'http://127.0.0.1:1234/v1/chat/completions', 'Неверный адрес метода.');
foreach (['recipient_chat', 'origin_chat', '123:synthetic', 'TELEGRAM_BOT_TOKEN'] as $privateValue) {
    verify(!str_contains($ai->calls[0]['json'], $privateValue), 'В ИИ переданы учётные данные или метаданные чата.');
}
verify(json_decode($request['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR) === [
    'candidate_profile' => $profile->text,
    'untrusted_vacancy_text' => 'Разработка API. https://example.org/vacancy',
], 'Профиль или вакансия не сохранены отдельно в пользовательских данных.');
intake($runner, 2, 11, 'Повтор', origin: 8);
intake($runner, 3, 10, 'Повтор полученного сообщения');
intake($runner, 4, 12, 'Посторонний', sender: 999);
intake($runner, 5, 13, 'Группа', chatType: 'group');
$pipeline->processPending(1000);
verify(count($ai->calls) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM analysis_jobs')->fetchColumn() === 1, 'Дубликаты или запрещённые сообщения попали в ИИ.');
verify((int) $pdo->query("SELECT next_offset FROM cursor WHERE name='main'")->fetchColumn() === 6, 'Курсор не продвинут.');

$ini = parse_ini_string(<<<'INI'
    AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
    AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
    INI, false, INI_SCANNER_RAW);
verify(is_array($ini), 'Не прочитан искусственный INI.');
$prefilter = new ConservativePrefilter($ini['AI_PREFILTER_EXACT_REJECT_LINES'], $ini['AI_PREFILTER_AMBIGUITY_TERMS']);
[$pdo, $ai, $telegram, $pipeline, $runner] = fixture($prefilter);
intake($runner, 1, 10, 'Только ночная смена.');
$pipeline->processPending(100);
verify(count($ai->calls) === 0 && str_contains(end($telegram->texts), 'Пропустить'), 'Точный отказ вызвал ИИ или не доставлен.');
verify((int) $pdo->query('SELECT analysis_attempts FROM analysis_jobs')->fetchColumn() === 0, 'Предфильтр учтён как попытка ИИ.');
verify($pdo->query('SELECT body FROM messages')->fetchColumn() === 'Только ночная смена.', 'Исходник изменён предфильтром.');
foreach ([
    'Разработка API, офис, страна, технология', 'Условия неизвестны', 'Не только ночная смена.',
    "Только ночная смена.\nДневная смена тоже возможна.", 'Только ночная смена, обсуждается.',
    'Ранее было: Только ночная смена.',
] as $i => $text) {
    intake($runner, $i + 2, $i + 11, $text);
    $pipeline->processPending(101 + $i);
}
verify(count($ai->calls) === 6, 'Неоднозначный текст ошибочно отклонён.');
verify(new ConservativePrefilter(['Условие обсуждается.'], ['обсуждается'])->reject('Условие обсуждается.') === null, 'Термин неоднозначности не отменил совпадение.');
[$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
intake($runner, 1, 10, 'Только ночная смена.');
$pipeline->processPending(100);
verify(count($ai->calls) === 1, 'Пустые правила должны пропускать к ИИ.');
foreach ([[['']], [['a' => 'строка']], [["строка\0"]], [[123]]] as $arguments) {
    try {
        new ConservativePrefilter(...$arguments);
        throw new RuntimeException('Неверное правило принято.');
    } catch (ConfigurationException) {
    }
}

$formatter = new VacancyResultFormatter();
$sourceJob = ['body' => 'Источник: https://t.me/c/456/8', 'origin_type' => 'channel', 'origin_chat_id' => -1000000000456, 'origin_message_id' => 8];
verify($formatter->verifiedSource($sourceJob) === 'https://t.me/c/456/8', 'Ссылка с подтверждёнными ID потеряна.');
$largeSource = ['body' => 'https://t.me/c/12345678901/8', 'origin_type' => 'channel', 'origin_chat_id' => -1012345678901, 'origin_message_id' => 8];
verify($formatter->verifiedSource($largeSource) === 'https://t.me/c/12345678901/8', 'Преобразование большого ID канала неверно.');
$incomplete = $sourceJob;
$incomplete['origin_chat_id'] = -100456;
verify($formatter->verifiedSource($incomplete) === null, 'Неполный ID принят как подтверждение ссылки.');
foreach (['https://t.me/c/456/9', 'https://t.me/c/456/8?x=1', 'https://t.me/c/456/8.evil', 'https://example.org', 'https://t.me/company/8'] as $url) {
    $sourceJob['body'] = $url;
    verify($formatter->verifiedSource($sourceJob) === null, 'Неподтверждённый источник принят.');
    verify(str_contains($formatter->sourceDescription($sourceJob) ?? '', 'не проверена'), 'Не дана непроверенная ссылка.');
}
foreach (['https://user:secret@example.org/x', 'https://t.me@evil.example/x', 'https://exаmple.org/x', 'https://example.org/%00x', 'https://example.org/abc\\evil', "https://example.org/\u{202E}evil", "https://example.org/abc\0evil"] as $url) {
    verify($formatter->sourceDescription(['body' => $url]) === null, 'Опасная или подменённая ссылка принята.');
}
intake($runner, 2, 11, 'API. Источник: https://t.me/c/456/80', origin: 80);
$pipeline->processPending(1200);
verify(str_contains(end($telegram->texts), 'Источник: https://t.me/c/456/80'), 'Проверенная ссылка не дошла до уведомления.');
$saved = json_decode($pdo->query('SELECT result_json FROM analysis_jobs ORDER BY message_id DESC LIMIT 1')->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
verify($saved['source_status'] === 'verified_origin_ids', 'Серверный статус источника не сохранён.');

$validator = new AnalysisResultValidator();
foreach (Verdict::cases() as $verdict) {
    $result = $validator->validate(json_encode(syntheticResult($verdict->value), JSON_THROW_ON_ERROR));
    verify($result->verdict === $verdict && $result->priority === Priority::High, 'Доменное значение потеряно.');
    verify(str_contains($formatter->format($result, 10), 'Оплата: неизвестно'), 'Неизвестная оплата потеряна.');
    verify(json_decode($result->toJson(), true, flags: JSON_THROW_ON_ERROR) === syntheticResult($verdict->value), 'Сериализация изменила контракт.');
}
foreach (Priority::cases() as $priority) {
    $data = syntheticResult();
    $data['priority'] = $priority->value;
    verify($validator->validate(json_encode($data, JSON_THROW_ON_ERROR))->priority === $priority, 'Приоритет не поддержан валидатором.');
}
foreach (Verdict::cases() as $verdict) {
    [$pdo, $ai, $telegram, $pipeline, $runner] = fixture();
    $data = syntheticResult($verdict->value);
    $ai->responses = [new AiHttpResponse(200, json_encode([
        'choices' => [['message' => ['content' => json_encode($data, JSON_THROW_ON_ERROR)]]],
    ], JSON_THROW_ON_ERROR))];
    intake($runner, 1, 10, 'Искусственная вакансия API');
    $pipeline->processPending(100);
    verify($pdo->query('SELECT verdict FROM analysis_jobs')->fetchColumn() === $verdict->value, 'Доменное значение не согласовано со схемой SQLite.');
}
try {
    testPrompt()->build("\xFF", 'Искусственная вакансия', $profile);
    throw new RuntimeException('Ошибка сериализации запроса не перехвачена.');
} catch (AiException $exception) {
    verify($exception->category === 'serialization' && !$exception->retryable && $exception->getPrevious() instanceof JsonException, 'Потеряна семантика ошибки сериализации.');
}
try {
    new AnalysisResult("\xFF", 'роль', Verdict::Apply, Priority::High, [], [], 'неизвестно', 'неизвестно', [], 'причина')->toJson();
    throw new RuntimeException('Ошибка сериализации результата не перехвачена.');
} catch (AiException $exception) {
    verify($exception->category === 'serialization' && $exception->getPrevious() instanceof JsonException, 'Потеряна причина ошибки сериализации результата.');
}
$bad = ['{}', 'not-json', str_repeat('[', 17) . '0' . str_repeat(']', 17), "\xFF"];
$bad[] = str_replace('"risks":[]', '"risks":{}', json_encode(syntheticResult(), JSON_THROW_ON_ERROR));
foreach ([
    'verdict' => 'execute', 'priority' => 'urgent', 'rationale' => str_repeat('я', AnalysisResult::RATIONALE_LIMIT + 1),
    'source_status' => 'verified', 'company' => "\u{2003}", 'pay' => "оплата\0", 'position' => "роль\u{0085}",
    'matches' => array_fill(0, 5, 'лишнее'), 'missing' => ['ключ' => 'не список'], 'risks' => [null],
] as $field => $value) {
    $data = syntheticResult();
    $data[$field] = $value;
    $bad[] = json_encode($data, JSON_THROW_ON_ERROR);
}
foreach (['tool_calls' => [], 'rationale' => '<script>execute()</script>', 'geography' => 'https://evil.example'] as $key => $value) {
    $data = syntheticResult();
    $data[$key] = $value;
    $bad[] = json_encode($data, JSON_THROW_ON_ERROR);
}
foreach ($bad as $json) {
    try {
        $validator->validate($json);
        throw new RuntimeException('Невалидный ответ принят.');
    } catch (AiException $exception) {
        verify($exception->category === 'invalid_output' && str_contains($exception->getMessage(), 'Не удалось'), 'Небезопасная диагностика.');
    }
}
foreach (['я', '界', '🧪'] as $character) {
    $max = syntheticResult();
    foreach (AnalysisResult::LIST_FIELDS as $key) {
        $max[$key] = array_fill(0, AnalysisResult::LIST_LIMIT, str_repeat($character, AnalysisResult::LIST_ITEM_LIMIT));
    }
    foreach (AnalysisResult::TEXT_LIMITS as $key => $limit) {
        $max[$key] = str_repeat($character, $limit);
    }
    $longUrl = 'https://example.org/' . str_repeat('x', VacancyResultFormatter::SOURCE_LIMIT - 20);
    $source = $formatter->sourceDescription(['body' => $longUrl]);
    $bounded = $formatter->format($validator->validate(json_encode($max, JSON_THROW_ON_ERROR)), PHP_INT_MAX, source: $source);
    verify(mb_strlen($bounded, 'UTF-8') <= 4096 && str_contains($bounded, $longUrl) && str_contains($bounded, 'Откликаться') && str_contains($bounded, $max['pay']) && str_contains($bounded, (string) PHP_INT_MAX), 'Максимальный результат потерял вердикт, условия или источник.');
    verify(strlen(mb_convert_encoding($bounded, 'UTF-16LE', 'UTF-8')) <= 8192, 'Сообщение превысило консервативный бюджет Unicode.');
    if ($character === '🧪') {
        verify(str_contains($bounded, '(сокращено)'), 'Нет явного указания на сокращённые списки.');
    }
}

foreach ([
    ['http://8.8.8.8/v1', 'local', null], ['https://example.org/v1', 'local', 'secret'],
    ['http://example.org/v1', 'cloud', 'secret'], ['https://example.org/v1', 'cloud', null],
    ['http://user:secret@127.0.0.1/v1', 'local', null], ['http://127.0.0.1/v1?secret=1', 'local', null],
    ['http://127.0.0.1/v1#fragment', 'local', null], ['https://example.org/v1', 'other', 'secret'],
] as [$url, $location, $key]) {
    try {
        new OpenAiCompatibleEvaluator(new FakeAi(), $validator, testPrompt(), $url, 'synthetic', $key, ProviderLocation::fromConfig($location));
        throw new RuntimeException('Небезопасная конфигурация принята.');
    } catch (ConfigurationException $exception) {
        verify(!str_contains($exception->getMessage(), 'secret'), 'Секрет в конфигурационной ошибке.');
    }
}
new OpenAiCompatibleEvaluator(new FakeAi(), $validator, testPrompt(), 'https://example.org/v1', 'synthetic', 'synthetic-key', ProviderLocation::Cloud);
new OpenAiCompatibleEvaluator(new FakeAi(), $validator, testPrompt(), 'http://172.20.0.1:1234/v1', 'synthetic');

$loader = new CandidateProfileLoader();
/**
 * RU: Имитирует недоступный для чтения обычный файл независимо от прав в системе.
 * EN: Simulates an unreadable regular file independently of host permissions.
 */
final class UnreadableProfileStream
{
    public mixed $context;

    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100000, 'size' => 100, 'uid' => 0, 'gid' => 0];
    }
}
verify(stream_wrapper_register('triageunreadable', UnreadableProfileStream::class), 'Не создан искусственный поток.');
try {
    verify(is_file('triageunreadable://profile') && !is_readable('triageunreadable://profile'), 'Искусственный файл должен быть недоступен для чтения.');
    foreach ([$loader, new EvaluationInstructionsLoader()] as $fileLoader) {
        try {
            $fileLoader->load('triageunreadable://profile');
            throw new RuntimeException('Недоступный обязательный файл принят.');
        } catch (ProfileException) {
        }
    }
} finally {
    stream_wrapper_unregister('triageunreadable');
}
try {
    $loader->load(__DIR__ . '/nonexistent-profile.md');
    throw new RuntimeException('Нет ошибки отсутствующего профиля.');
} catch (ProfileException) {
}
$temp = tempnam(sys_get_temp_dir(), 'triage-profile-');
verify(is_string($temp), 'Нет временного файла.');
try {
    foreach (['', str_repeat(' ', CandidateProfile::MAX_CHARACTERS) . 'x', "\u{2003}", "\xFF", str_repeat('x', CandidateProfile::MAX_BYTES + 1), str_repeat('я', CandidateProfile::MAX_CHARACTERS + 1), str_repeat('🧪', 8193), "текст\0", "\0текст", "текст\u{0085}"] as $badProfile) {
        file_put_contents($temp, $badProfile);
        try {
            $loader->load($temp);
            throw new RuntimeException('Невалидный профиль принят загрузчиком.');
        } catch (ProfileException) {
        }
        try {
            new CandidateProfile($badProfile);
            throw new RuntimeException('Невалидный профиль принят на строковой границе.');
        } catch (ProfileException) {
        }
    }
    file_put_contents($temp, $profile->text);
    verify($loader->load($temp)->fingerprint === $profile->fingerprint, 'Отпечаток профиля нестабилен.');
    $markdown = "\u{2003}Обычный Markdown\r\n\r\n1. API и SQL 🧪\u{2003}";
    verify(new CandidateProfile($markdown)->text === "Обычный Markdown\n\n1. API и SQL 🧪", 'Нормализация Unicode и переводов строк неверна.');
    verify(new CandidateProfile('Справочная ссылка: https://example.org')->text !== '', 'Легитимный Markdown отклонён эвристикой.');
} finally {
    unlink($temp);
}

$instructionsLoader = new EvaluationInstructionsLoader();
try {
    $instructionsLoader->load(__DIR__ . '/nonexistent-instructions.md');
    throw new RuntimeException('Отсутствующие инструкции приняты.');
} catch (ProfileException $exception) {
    verify(str_contains($exception->getMessage(), 'инструкций'), 'Нет понятного сообщения об инструкциях.');
}
$temp = tempnam(sys_get_temp_dir(), 'triage-instructions-');
verify(is_string($temp), 'Нет временного файла инструкций.');
try {
    foreach ([
        '', " \t\n", "\xFF", "текст\0", "\0текст", "текст\u{0085}",
        str_repeat('x', EvaluationInstructions::MAX_BYTES + 1),
        str_repeat('я', EvaluationInstructions::MAX_CHARACTERS + 1),
        str_repeat('🧪', 8193),
        str_repeat(' ', EvaluationInstructions::MAX_CHARACTERS) . 'x',
    ] as $invalid) {
        file_put_contents($temp, $invalid);
        foreach ([
            static fn () => $instructionsLoader->load($temp),
            static fn () => new EvaluationInstructions($invalid),
        ] as $loadInvalid) {
            try {
                $loadInvalid();
                throw new RuntimeException('Некорректные инструкции приняты.');
            } catch (ProfileException $exception) {
                verify(str_contains($exception->getMessage(), 'Инструкции'), 'Нет понятной ошибки инструкций.');
                verify(!str_contains($exception->getMessage(), $temp), 'В ошибке раскрыт путь.');
            }
        }
    }
    $instructionText = "  Обычный Markdown\r\n\tУточняй неизвестные условия 🧪.\n";
    file_put_contents($temp, $instructionText);
    $instructions = $instructionsLoader->load($temp);
    verify($instructions->text === $instructionText, 'Точный текст инструкций изменён.');
    $firstPrompt = new EvaluationPromptBuilder($instructions);
    $secondPrompt = testPrompt($instructionText . ' Другая редакция.');
    $firstRequest = $firstPrompt->build('synthetic', 'Искусственная вакансия', $profile);
    $firstData = json_decode($firstRequest, true, flags: JSON_THROW_ON_ERROR);
    verify(array_column($firstData['messages'], 'role') === ['system', 'user'], 'Нужны ровно один начальный system и один user.');
    $systemPrefix = $instructionText . "\n\n```json\n";
    $systemContent = $firstData['messages'][0]['content'];
    verify(str_starts_with($systemContent, $systemPrefix) && str_ends_with($systemContent, "\n```"), 'Точный текст инструкций или границы схемы изменены.');
    $schemaJson = substr($systemContent, strlen($systemPrefix), -strlen("\n```"));
    verify(json_decode($schemaJson, true, flags: JSON_THROW_ON_ERROR) === ['output_schema' => AnalysisResult::jsonSchema()], 'Потеряна машиночитаемая схема.');
    verify(json_decode($firstData['messages'][1]['content'], true, flags: JSON_THROW_ON_ERROR) === [
        'candidate_profile' => $profile->text,
        'untrusted_vacancy_text' => 'Искусственная вакансия',
    ], 'Профиль и вакансия должны оставаться отдельными пользовательскими данными.');
    verify($firstRequest !== $secondPrompt->build('synthetic', 'Искусственная вакансия', $profile), 'Правка инструкций не изменила запрос.');
    verify($firstPrompt->fingerprint($profile) === new EvaluationPromptBuilder($instructionsLoader->load($temp))->fingerprint($profile), 'Общая версия нестабильна.');
    verify($firstPrompt->fingerprint($profile) !== $secondPrompt->fingerprint($profile), 'Инструкции не учтены в версии.');
    verify($firstPrompt->fingerprint($profile) !== $firstPrompt->fingerprint(new CandidateProfile($profile->text . ' Новый опыт.')), 'Профиль не учтён в версии.');
    verify($firstPrompt->fingerprint($profile) !== $firstPrompt->fingerprint(new CandidateProfile($profile->text . "\n")), 'Редактирование исходного файла профиля не изменило версию.');
    $fakeAi = new FakeAi();
    new OpenAiCompatibleEvaluator($fakeAi, $validator, $firstPrompt, 'http://localhost/v1', 'synthetic')->evaluate('Искусственная вакансия', $profile);
    verify($fakeAi->calls[0]['json'] === $firstRequest, 'Оценщик изменил внедрённый запрос.');
    new OpenAiCompatibleEvaluator($fakeAi, $validator, $secondPrompt, 'http://localhost/v1', 'synthetic')->evaluate('Искусственная вакансия', $profile);
    verify($fakeAi->calls[0]['json'] !== $fakeAi->calls[1]['json'], 'Оценщик не использовал изменённые инструкции.');
} finally {
    unlink($temp);
}

$timeoutProperty = new ReflectionProperty(CurlAiTransport::class, 'requestTimeoutSeconds');
verify($timeoutProperty->getValue(new CurlAiTransport()) === 180, 'Неверный тайм-аут транспорта по умолчанию.');
foreach ([30, 300, 600, 1800] as $seconds) {
    verify($timeoutProperty->getValue(new CurlAiTransport($seconds)) === $seconds, 'Тайм-аут не дошёл до транспорта.');
}

// RU: Проверяем только порядок исходного кода; не запускаем бота и не читаем приватные файлы.
// EN: Inspect source order only; never execute the bot or read its private files.
$entrypoint = file_get_contents(dirname(__DIR__) . '/bin/bot.php');
verify(is_string($entrypoint), 'Не прочитана точка входа.');
$identifyExit = strpos($entrypoint, 'exit(0);');
$profileLoad = strpos($entrypoint, '$profile = new CandidateProfileLoader()');
$instructionsLoad = strpos($entrypoint, '$instructions = new EvaluationInstructionsLoader()');
$runtimeStart = strpos($entrypoint, 'umask(0077)');
verify(is_int($identifyExit) && is_int($profileLoad) && is_int($instructionsLoad) && is_int($runtimeStart)
    && $identifyExit < $profileLoad && $profileLoad < $instructionsLoad && $instructionsLoad < $runtimeStart,
    'identify зависит от файлов либо run открывает хранилище до их проверки.');
$builderSource = file_get_contents(dirname(__DIR__) . '/src/Analysis/EvaluationPromptBuilder.php');
verify(is_string($builderSource) && !str_contains($builderSource, 'Оцени вакансию'), 'Старое задание осталось в PHP.');

fwrite(STDOUT, "Проверка AI и сквозного отбора: OK\n");

# Сервер: webhook и cron

Сервер принимает сообщения через HTTPS, сохраняет их в SQLite и обрабатывает отдельной CLI-командой из cron. Открытый терминал не нужен. Пользователь отправляет вакансии своему боту в личном чате, как и при локальном запуске.

Публичный обработчик [`public/webhook.php`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/public/webhook.php) выполняет только приём. Оценку ИИ и отправку результатов запускает `php bin/bot.php work-once`. Для одного токена выбирайте либо этот режим, либо локальный polling.

## Подготовьте среду

Установите проект из исходников в постоянный каталог вне публичного корня сайта. Локальный архив с меню не содержит `public/webhook.php` и не заменяет серверную установку.

Нужны PHP `^8.5` для CLI и PHP-FPM, Composer, HTTPS с действительным сертификатом и расширения `curl`, `json`, `mbstring`, `PDO`, `pdo_sqlite`. Проверьте расширения отдельно для CLI и FPM: их настройки могут различаться. Требования заданы в [`composer.json`](../../composer.json).

Из корня серверной установки выполните:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Используйте `composer.lock` проекта. Composer нужен для подготовки установки, а не для обработки запросов.

Настройте HTTP-сервер и выделенный пул PHP-FPM средствами вашей системы:

- DocumentRoot указывает строго на `public/`; единственная публичная PHP-точка — `/webhook.php`.
- Запретите листинг каталогов, выполнение других PHP-путей и переходы через псевдонимы каталогов или резервный маршрут в корень проекта.
- `config/`, `vendor/`, `var/`, `.git/` и исходники недоступны по HTTP.
- Ограничьте тело запроса 256 КиБ; приложение также проверяет этот предел.
- У FPM отключён `display_errors`. Тела запросов, токены, секретный заголовок и тексты вакансий не записываются в HTTP-журналы.

Конкретные директивы зависят от вашего HTTP-сервера. До регистрации webhook проверьте HTTPS и недоступность приватных путей извне.

## Создайте конфигурацию и профиль

Если `config/bot.ini` ещё не существует, скопируйте [образец](../../config/bot.example.ini). Редактируйте файл в редакторе, не вводя токены в команды.

1. Создайте бота через [BotFather](https://t.me/BotFather), сохраните его токен в `TELEGRAM_BOT_TOKEN`.
2. Пока выбран `polling` и webhook не зарегистрирован, отправьте боту `/start` и выполните `php bin/bot.php identify` без другого polling-процесса.
3. Укажите свой ID в `TELEGRAM_ALLOWED_USER_ID`.
4. Подготовьте `config/profile/local/candidate.md` и `config/profile/local/instructions.md` по [руководству профиля](../../config/profile/README.md).
5. Настройте `AI_PROVIDER_LOCATION`, `AI_BASE_URL`, `AI_MODEL` и при необходимости `AI_API_KEY`. Подключение и передачу данных описывает [руководство моделей](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models.md).

Для сервера модели на том же VDS допустим адрес `http://127.0.0.1:1234/v1` с режимом `local`. Если модель работает на другом компьютере, укажите доступный с VDS частный IPv4. Облачный режим требует HTTPS и ключа.

После определения ID переключите настройки:

```ini
TELEGRAM_RECEIVE_MODE = "webhook"
TELEGRAM_WEBHOOK_SECRET = ""
```

Пустой секрет выше нужно заменить отдельным случайным значением длиной 1–256 символов из `A–Z`, `a–z`, `0–9`, `_`, `-`. Сохраните его только в приватном INI. При регистрации передавайте то же значение параметром `secret_token`, без добавления в адрес сайта.

Полный перечень параметров — в [справочнике конфигурации](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/configuration.md).

## Настройте права и подготовьте SQLite

CLI и FPM должны использовать один абсолютный путь установки и одну БД `var/vacancies.sqlite`. Удобнее выделить отдельного непривилегированного пользователя приложения для cron и пула PHP-FPM.

Ему нужны чтение кода, `vendor/`, INI и профиля, запись в БД и каталог `var/`: SQLite создаёт рядом журнал, а CLI — `bot.lock`. Остальным пользователям доступ к конфигурации и данным не выдавайте. Если пользователи CLI и FPM различаются, настройте точечные ACL, в том числе наследуемые для новых файлов. CLI/FPM используют `umask(0077)`, поэтому одной общей группы недостаточно.

Проверьте права от обеих учётных записей. Не применяйте широкие рекурсивные `chown` или `chmod` ко всему проекту.

До регистрации webhook запустите от пользователя cron:

```bash
php bin/bot.php work-once
```

Используйте PHP CLI нужной версии. Команда проверяет настройки и профиль, создаёт `var/` и схему БД при необходимости, а также обрабатывает уже ожидающие задания. Webhook сам отсутствующую БД не создаёт.

Перед обновлением сделайте закрытую резервную копию INI, профиля и SQLite при остановленных обработчиках. Не заменяйте существующую БД пустой и не удаляйте состояние очереди ради проверки.

## Зарегистрируйте webhook

Остановите локальный мониторинг и другие получатели сообщений этого бота. До регистрации проверьте: GET к `/webhook.php` получает 405, POST без секретного заголовка — 403. Ответ 503 означает, что сначала нужно исправить готовность обработчика.

Регистрацию и удаление выполняет владелец через Telegram Bot API. Параметры методов:

| Метод | Что передать и проверить |
| --- | --- |
| `setWebhook` | `url`: ваш HTTPS-адрес `/webhook.php`; `secret_token`: секрет из INI; `allowed_updates`: `["message"]`; `drop_pending_updates`: `false`; для личного бота можно `max_connections = 1` |
| `getWebhookInfo` | Проверить `url`, `pending_update_count`, наличие `last_error_date` и `last_error_message` |
| `deleteWebhook` | `drop_pending_updates = false`; затем через `getWebhookInfo` убедиться, что `url` пуст |

Параметры описаны в официальной документации [setWebhook](https://core.telegram.org/bots/api#setwebhook), [getWebhookInfo](https://core.telegram.org/bots/api#getwebhookinfo) и [deleteWebhook](https://core.telegram.org/bots/api#deletewebhook).

Следующий пример для Bash запускается из корня исходников. Он читает токен и секрет из INI, формирует адрес Bot API в памяти и выводит только краткий итог. Сначала оставьте `$method = 'getWebhookInfo'`; для регистрации замените его на `setWebhook` и задайте свой публичный адрес. Для снятия выберите `deleteWebhook`. Не включайте отладочный вывод HTTP и не публикуйте конфигурацию.

```bash
php <<'PHP'
<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $method = 'getWebhookInfo';
    $webhookUrl = 'https://bot.example.org/webhook.php';
    $config = @parse_ini_file('config/bot.ini', false, INI_SCANNER_RAW);
    if (!is_array($config) || !is_string($config['TELEGRAM_BOT_TOKEN'] ?? null)) {
        throw new RuntimeException();
    }
    $parameters = match ($method) {
        'setWebhook' => [
            'url' => $webhookUrl,
            'secret_token' => $config['TELEGRAM_WEBHOOK_SECRET'] ?? '',
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
            'max_connections' => 1,
        ],
        'deleteWebhook' => ['drop_pending_updates' => false],
        'getWebhookInfo' => new stdClass(),
        default => throw new RuntimeException(),
    };
    $curl = curl_init('https://api.telegram.org/bot' . $config['TELEGRAM_BOT_TOKEN'] . '/' . $method);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($parameters, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $response = is_string($body) ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : null;
    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        throw new RuntimeException();
    }
    echo "Запрос выполнен.\n";
    if ($method === 'getWebhookInfo') {
        $info = $response['result'];
        echo 'Адрес совпадает: ' . (($info['url'] ?? '') === $webhookUrl ? 'да' : 'нет') . "\n";
        echo 'Webhook снят: ' . (($info['url'] ?? '') === '' ? 'да' : 'нет') . "\n";
        echo 'Ожидают доставки: ' . (int) ($info['pending_update_count'] ?? 0) . "\n";
        echo 'Есть сведения об ошибке: ' . (isset($info['last_error_date']) ? 'да' : 'нет') . "\n";
    }
} catch (Throwable) {
    fwrite(STDERR, "Запрос не выполнен. Проверьте настройки, сеть и состояние Bot API.\n");
    exit(1);
}
PHP
```

Сведения о прошлой ошибке сопоставляйте с её датой и текущей доставкой. При необходимости смотрите детали в защищённом API-клиенте, который берёт токен из приватного хранилища. Не вставляйте токен в браузерную адресную строку или историю терминала.

## Запустите обработку через cron

Добавьте запуск раз в минуту от назначенного пользователя. Замените оба абсолютных пути на свои:

```cron
* * * * * /absolute/path/to/php8.5 /absolute/path/to/project/bin/bot.php work-once
```

Рабочий каталог cron несущественен для приложения: [CLI-точка входа](../../bin/bot.php) определяет пути по своему расположению. Вывод cron направляйте только в закрытый журнал или почту администратора, не в публичный каталог.

Команда берёт общую блокировку `var/bot.lock`, восстанавливает прерванные попытки и выполняет до 10 порций обработки. Через 20 секунд новые порции не начинаются, но уже начатый запрос может выполняться до `AI_REQUEST_TIMEOUT_SECONDS` (30–1800 секунд). Занятый воркер приводит к пропуску следующего запуска cron, а не к параллельной оценке. Поведение задаёт [`BotRunner::workOnce()`](../../src/Application/BotRunner.php).

Отправьте боту небольшой вымышленный текст. Сначала ожидайте «Вакансия сохранена.», затем после запуска cron — уведомление начала анализа и итог. Подтверждение и уведомление начала могут не прийти при сетевом сбое; сохранённое сообщение от этого не исчезает.

## Приём и ошибки доставки

[`WebhookReceiver`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/src/Application/WebhookReceiver.php) проверяет `X-Telegram-Bot-Api-Secret-Token` до разбора JSON и открытия БД. Обновление, сообщение и задание сохраняются одной транзакцией. HTTP 204 возвращается после коммита; повторная доставка того же `update_id` не создаёт ещё одно задание. Обновления могут приходить не по порядку.

| HTTP-код | Действие |
| --- | --- |
| 400 | Проверить формат JSON и обязательные поля запроса |
| 403 | Сверить секрет в INI и регистрации webhook, передачу заголовка до FPM |
| 405 | Использовать POST; GET годится только для проверки отказа |
| 413 | Проверить размер тела: предел 256 КиБ |
| 415 | Нужен `Content-Type: application/json` |
| 503 | Проверить режим, конфигурацию, автозагрузчик Composer, существование БД и права SQLite |

SQLite ожидает блокировку до 5 секунд. Ошибка сохранения приводит к 503, чтобы Телеграмм мог повторить доставку; такие повторы ограничены, поэтому устраняйте сбой своевременно. Подтверждение в чат отправляется после коммита и не повторяется при повторе уже сохранённого обновления.

Для уведомления начала анализа предусмотрена одна попытка на вакансию. Готовая оценка сохраняется до отправки и повторно не рассчитывается при ошибке доставки. Лимит `AI_MAX_ATTEMPTS` применяется отдельно к анализу и доставке. Окончательно неудачные задания автоматически заново не создаются. Подробнее — [форматы и состояния результата](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result.md).

## Переключение режимов

**С локального приложения на сервер:** остановите polling, перенесите приватные настройки, профиль и данные при остановленных процессах, подготовьте FPM и БД, выберите `webhook`, зарегистрируйте webhook, затем включите cron. Используйте одну актуальную копию данных.

**С сервера на локальный запуск:**

1. Отключите cron и дождитесь завершения активного воркера.
2. Выполните `deleteWebhook` с `drop_pending_updates = false`; проверьте пустой `url` через `getWebhookInfo`.
3. Дождитесь завершения входящих FPM-запросов и выключите публичный обработчик.
4. Сохраните ту же БД и приватные файлы; при переносе скопируйте весь `var/`.
5. Задайте `TELEGRAM_RECEIVE_MODE = "polling"` и запустите локальное приложение или `php bin/bot.php run` из исходников.

Не очищайте курсор, таблицу полученных обновлений или сообщения вручную. `run` и `identify` недоступны при выбранном `webhook`; само приложение не регистрирует и не удаляет webhook.

При неполадках воспользуйтесь [локальными подсказками](local-gum.md#если-что-то-не-работает) или [расширенным руководством](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/troubleshooting.md).

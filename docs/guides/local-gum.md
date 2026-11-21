# Локальное приложение

## Выберите язык

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| **Выбран** | [English](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-gum_en.md) | [Español](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-gum_es.md) | [中文](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-gum_zh.md) | [Français](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-gum_fr.md) | [Deutsch](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-gum_de.md) |

Отправьте боту текст вакансии или перешлите объявление из чата либо канала. Приложение сохранит вакансию, оценит её по профилю и пришлёт результат. Пока работает мониторинг, окно приложения должно оставаться открытым.

## Выберите архив

Проверенные архивы публикуются на странице [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases) после нативных проверок. До первого релиза готовых архивов на странице нет. Приведённые ниже имена относятся к будущим опубликованным сборкам.

| Платформа | Имя архива | Что нужно на компьютере |
| --- | --- | --- |
| Linux x86_64 | `telegram-vacancy-monitor-linux-x86_64.tar.gz` | Интерактивный терминал, Bash и `dirname` |
| Windows x64 | `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 и штатный Windows PowerShell 5.1 |
| macOS ARM64 | `telegram-vacancy-monitor-macos-arm64.tar.gz` | macOS 14+ на Apple Silicon, интерактивный терминал |

Внутри архива — папка `telegram-vacancy-monitor/` с исполняемым файлом, PHP, Gum, образцами настроек, лицензией `LICENSE` и двумя руководствами: `docs/guides/local-gum.md` и `docs/guides/vds-webhook.md`. Остальная документация доступна в репозитории. В Windows меню Gum запускается через PowerShell. Отдельная установка PHP, Composer и Gum не требуется. Сервер модели запускается отдельно.

Сохраняйте структуру папки целиком. Linux использует включённые библиотеки и загрузчик; macOS использует также системные библиотеки. Архив ARM64 предназначен для Apple Silicon, Windows x64 — для Windows на x64. На macOS системная проверка загруженных приложений может блокировать запуск: проверьте источник архива и сообщение в настройках безопасности, не отключая защиту системы целиком.

Состав архива задаёт [упаковщик](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/build/common/package.py). Перед запуском исполняемый файл проверяет целостность файлов по `SHA256SUMS.json`. Настройки храните в файлах, указанных ниже.

## Распаковка и запуск

Распакуйте архив в доступную для записи личную папку. Команды выполняются из каталога с загруженным архивом.

Linux:

```bash
# Распакуйте архив и запустите приложение из созданного каталога.
tar -xzf telegram-vacancy-monitor-linux-x86_64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Windows: распакуйте ZIP-архив и откройте `telegram-vacancy-monitor/vacancy-monitor.exe` **двойным щелчком**. Приложение также можно запустить из PowerShell:

```powershell
# Распакуйте архив в отдельную папку и запустите приложение.
Expand-Archive -LiteralPath .\telegram-vacancy-monitor-windows-x64.zip -DestinationPath .\vacancy-monitor-app
Set-Location -LiteralPath .\vacancy-monitor-app\telegram-vacancy-monitor
.\vacancy-monitor.exe
```

macOS на Apple Silicon:

```bash
# Распакуйте сборку ARM64 и запустите приложение.
tar -xzf telegram-vacancy-monitor-macos-arm64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Для повторного запуска в Windows можно снова открыть `vacancy-monitor.exe` двойным щелчком. В Linux и macOS выполните `./vacancy-monitor` из папки приложения. Пути с пробелами допустимы.

## Как выглядит приложение

Главное меню, справка и сообщения о настройках показаны в [обзоре интерфейса](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-interface.md).

## Создайте приватную конфигурацию

Все дальнейшие пути отсчитываются от папки с исполняемым файлом. Скопируйте [образец](../../config/bot.example.ini) в `config/bot.ini`, если такого файла ещё нет.

Linux/macOS:

```bash
# Создайте конфигурацию из образца, не заменяя существующий файл.
cp -n config/bot.example.ini config/bot.ini

# Подготовьте каталог профиля и скопируйте образец инструкций.
mkdir -p config/profile/local
cp -n config/profile/instructions.example.md config/profile/local/instructions.md
```

Windows PowerShell:

```powershell
# Не перезаписывайте свою конфигурацию при повторной настройке.
if (-not (Test-Path -LiteralPath .\config\bot.ini)) {
    Copy-Item -LiteralPath .\config\bot.example.ini -Destination .\config\bot.ini
}
# Подготовьте каталог для своих файлов профиля.
New-Item -ItemType Directory -Path .\config\profile\local -Force | Out-Null
# Скопируйте образец инструкций, если ещё нет своего файла.
if (-not (Test-Path -LiteralPath .\config\profile\local\instructions.md)) {
    Copy-Item -LiteralPath .\config\profile\instructions.example.md -Destination .\config\profile\local\instructions.md
}
```

Редактируйте `config/bot.ini` текстовым редактором. Не вводите секреты в команды терминала.

1. Откройте [BotFather](https://t.me/BotFather), создайте бота командой `/newbot` и сохраните выданный токен в `TELEGRAM_BOT_TOKEN`.
2. Отправьте боту `/start` отдельным сообщением, без пересылки и дополнительного текста.
3. В приложении выберите «Узнать Telegram User ID», затем «Проверить сейчас». Достаточно токена бота и режима `polling`; профиль и настройка ИИ ещё не нужны.
4. Запишите свой числовой Telegram ID в `TELEGRAM_ALLOWED_USER_ID`. Если найдено несколько ID и непонятно, какой ваш, не выбирайте наугад: см. [способ проверки в руководстве по боту](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot.md#узнайте-свой-id).
5. Оставьте `TELEGRAM_RECEIVE_MODE = "polling"`.

Поиск ID выполняется только по нажатию и не подтверждает ожидающие обновления. Команда `identify` использует общую неблокирующую блокировку `var/bot.lock`: при работающем мониторинге сначала остановите его, не удаляя файл блокировки. Если зарегистрирован webhook, сначала выполните [безопасное переключение на локальный режим](vds-webhook.md#переключение-режимов). Подробности есть в [онлайн-руководстве по боту](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot.md).

## Подготовьте профиль и модель

Создайте в редакторе `config/profile/local/candidate.md`: опишите опыт, навыки, желаемую работу и ограничения. В `config/profile/local/instructions.md` задайте правила оценки. Оба файла обязательны, непусты, в UTF-8, каждый не больше 32 КиБ и 16 000 символов. Подробности — в [руководстве профиля](../../config/profile/README.md).

Запустите сервер модели с интерфейсом Chat Completions. Если сервер работает на этом же компьютере, настройки могут выглядеть так:

```ini
; Пример для локального API. Укажите свой идентификатор модели.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

Замените `model-id` на идентификатор загруженной модели, адрес — на адрес своего API. К `AI_BASE_URL` приложение добавляет `/chat/completions`. Сервер должен принимать непотоковые запросы и возвращать JSON в `message.content` по переданной схеме. Совместимость сервера и модели проверьте на тестовом тексте.

В режиме `local` допустимы `localhost`, `127.0.0.0/8`, `[::1]` или частный IPv4 из диапазонов `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`. Для модели на другом компьютере укажите его частный IPv4 и разрешите соединение только из нужной сети. Имя компьютера в локальной сети вместо IP проверку не пройдёт. `127.0.0.1` означает компьютер, где запущено приложение.

Для облачной модели укажите `AI_PROVIDER_LOCATION = "cloud"`, HTTPS-адрес сервера, идентификатор модели и ключ `AI_API_KEY`.

Подробнее: [подключение моделей](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models.md) и [справочник INI](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/configuration.md).

## Проверьте готовность и начните работу

Выберите «Проверить готовность». В разделе «Подробнее» показаны результаты по среде исполнения, настройкам, профилю и хранилищу. После проверки можно [отправить тестовую вакансию](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/vacancy-analysis.md) по онлайн-примеру для проверки полного сценария: страница примеров не входит в standalone-архив.

После настройки выберите «Запустить мониторинг» и отправьте боту небольшой текст вакансии. Обычная последовательность сообщений: «Вакансия сохранена.», уведомление начала анализа, затем оценка. В ответе будут вердикт, приоритет, обоснование, условия, совпадения, риски и вопросы. Источник помечается отдельно; ссылка из текста не считается проверенной автоматически.

Во время запроса к модели обработка идёт последовательно. Для медленной модели увеличьте `AI_REQUEST_TIMEOUT_SECONDS` до `300` или `600` и перезапустите мониторинг. Допустимый диапазон — 30–1800 секунд; это предел одного запроса. `AI_MAX_ATTEMPTS` задаёт 1–5 попыток отдельно для анализа и отправки результата, включая первую. Постоянные ошибки могут завершить этап сразу.

Оценка сохраняется до отправки, поэтому повторная доставка сообщения не требует нового анализа. Подробности о [повторных сообщениях и восстановлении обработки](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/message-processing.md).

## Меню и остановка

| Действие | Назначение |
| --- | --- |
| «Запустить мониторинг» | Получение сообщений и обработка очереди, пока открыт процесс |
| «Проверить готовность» | Локальная диагностика без обращения к API |
| «Узнать Telegram User ID» | Поиск отправителей `/start` по ручному запросу |
| «Справка» | Быстрый старт, обработка, приватность, модель и сервер |
| «Язык» | Русский или английский язык меню до закрытия приложения |
| «Выход» | Завершение приложения |

Выбирайте пункты стрелками и Enter. Esc возвращает назад, а в главном меню оставляет его открытым. Для остановки мониторинга и приложения нажмите Ctrl+C. После обычного завершения команды или ошибки меню предлагает повторный запуск, возврат или выход; автоматического перезапуска нет. Язык ответа модели задаётся в `instructions.md`, отдельно от языка меню.

## Данные и обновление

Личные файлы создаются при настройке и не входят в архив. Образцы: [`config/bot.example.ini`](../../config/bot.example.ini), [профиль Go-разработчика](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer.md#профиль-кандидата), [профиль аналитика](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst.md#профиль-кандидата) и [инструкции модели](../../config/profile/instructions.example.md).

| Путь | Что сохранить |
| --- | --- |
| `config/bot.ini` | Токен бота, Telegram ID, подключение ИИ и правила |
| `config/profile/local/` | Профиль, инструкции и свои резервные копии |
| `var/` | SQLite, очередь и состояние обработки |

Перед обновлением остановите приложение и убедитесь, что процесс завершён. Сделайте закрытую резервную копию этих трёх путей. Новый архив распакуйте отдельно, перенесите приватные файлы и весь каталог `var/` в соответствующие места новой папки. Сверьте свой INI с новым образцом и запустите только новую копию. Не распаковывайте обновление поверх работающей установки и не заменяйте свою БД пустой.

После изменения конфигурации, профиля или инструкций перезапустите мониторинг. Уже сохранённые результаты автоматически не пересчитываются.

## Если что-то не работает

- **Нет ID:** отправьте боту `/start` и нажмите «Проверить сейчас» ещё раз; проверьте токен, отсутствие активного webhook и занятой блокировки `var/bot.lock`.
- **Ошибка настроек:** проверьте `config/bot.ini`, оба файла профиля и раздел «Подробнее» в проверке готовности.
- **Ошибка авторизации:** проверьте токен BotFather; после замены сохраните INI и перезапустите приложение.
- **Ошибка сети или TLS:** проверьте доступ к Телеграмму, DNS, системную дату и сертификаты. Не отключайте проверку TLS.
- **Хранилище недоступно:** проверьте свободное место и права на папку приложения, `var/` и SQLite. Не удаляйте БД или блокировку для обхода ошибки.
- **Сохранение есть, результата нет:** убедитесь, что мониторинг работает, сервер модели доступен и модель возвращает требуемый JSON.
- **Повреждены публичные файлы:** распакуйте доверенный архив заново отдельно и перенесите только свои данные.

Расширенная помощь: [устранение неполадок](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/troubleshooting.md) и [формат результата](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result.md).

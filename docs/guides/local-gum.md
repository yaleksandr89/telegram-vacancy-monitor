# Локальное приложение с Gum

## Overview

Приложение принимает вакансии, отправленные или пересланные вашему боту
в личном чате Телеграмма, сохраняет их в SQLite и выполняет настроенный
AI-анализ. Автоматического чтения произвольных каналов нет.

Целевой формат поставки — распаковываемая папка с нативным launcher,
PHP CLI 8.5.12, обязательными расширениями, Gum 2.0.2 и production autoload.
Для запуска такой папки не нужны установленные PHP, Composer и Gum.
Сборочные инструменты нужны только разработчику.

Контракт задачи 04R ещё **не принят полностью**: ниже отдельно указаны
фактически проверенные свойства и оставшиеся платформенные проверки.

## Supported bundles

| Поставка | Среда | Фактическая проверка |
| --- | --- | --- |
| `telegram-vacancy-monitor-linux-x86_64.tar.gz` | Linux x86_64; runtime из Ubuntu 24.04, glibc 2.39 с приватным загрузчиком | PASS: PHP/расширения, autoload, SQLite, настоящий Gum Help/Back/Exit в пустом mount namespace на WSL2 Linux; host PHP отсутствует внутри namespace |
| `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 x64, встроенный Windows PowerShell 5.1 | PASS на прежнем архиве: native Windows 11, PHP/расширения, autoload, SQLite, RU/EN, Help, readiness, identify и живая вакансия до результата в Телеграмме; NOT VERIFIED: чистая VM без host CRT и Windows Ctrl+C |
| `telegram-vacancy-monitor-macos-arm64.tar.gz` | Цель: macOS 14+ Apple Silicon arm64 | NOT VERIFIED: нет macOS runner; исходники сборщика подготовлены, архив не собран |

Intel macOS, Rosetta, Windows ARM и Windows через WSL не являются целевыми
поставками. WSL использовался как Linux build/test host.

Linux-пакет содержит собственные `libc`, `libm`, `libgcc_s`, OpenSSL и loader.
Расширения curl, json, mbstring, PDO, pdo_sqlite встроены в PHP.
Это relocatable build, а не полностью статический PHP. Из ОС используются
ядро, `/bin/bash`, `dirname` и, для очистки экрана при наличии, `tput`.
Минимальная версия ядра на отдельной чистой системе ещё не измерена.
Проверка на одном WSL2 kernel не доказывает совместимость со всеми Linux.

Владелец также проверил прежний Linux-архив на native Ubuntu 26.04:
RU/EN, Help, readiness, identify, живые вакансии, SQLite, локальный AI по LAN,
результат в Телеграмме и Ctrl+C без оставшихся процессов — PASS.
После изменений задачи 05 прежние архивы не являются текущими артефактами.

Цель Windows standalone — распаковка без отдельной установки Visual C++
Redistributable. Прежний архив не содержит CRT и мог использовать CRT хоста.
Задача 06 добавляет обязательный app-local `runtime/php/vcruntime140.dll`
для fresh и `--runtime-bundle` сборок. Владелец включение DLL уже одобрил.
Техническая упаковка CRT не доказывает запуск на чистой Windows VM:
эта часть F2 остаётся NOT VERIFIED до отдельного ручного теста.

Статически проверен официальный `php-8.5.12-1-nts-Win32-vs17-x64.zip`, SHA256
`2a36a2edcb7a5b9252f638fd5e4cf84d7f6e066092654220dc0b63e5cf4e9723`.
Обойдены обычные и delay-import PE-таблицы из `php.exe`, `php_curl.dll`,
`php_mbstring.dll`, `php_pdo_sqlite.dll` и транзитивных библиотек ZIP:
`php8.dll`, `libsqlite3.dll`, `nghttp2.dll`, `libssl-3-x64.dll`,
`libcrypto-3-x64.dll`, `libssh2.dll`, `brotlidec.dll`, `brotlicommon.dll`.

Минимальный выявленный внешний MSVC CRT для этой активной цепочки —
**`vcruntime140.dll` x64**. `vcruntime140_1.dll`, `msvcp140.dll` и MFC
в этой цепочке импортов не обнаружены; это не утверждение обо всех остальных
неактивных расширениях полного PHP ZIP. API-set `api-ms-win-crt-*` относится
к UCRT, который на Windows 10/11 является компонентом ОС и обслуживается Windows.
Другие внешние импорты — системные Windows DLL, включая kernel32, advapi32,
crypt32, bcrypt, ws2_32, iphlpapi, dnsapi, secur32, wldap32, normaliz,
ole32, shell32, user32 и api-ms-win-core-path.
Статический анализ не заменяет проверку динамической загрузки на чистой VM.

Источник задачи 06 — предоставленный владельцем официальный Microsoft installer
`https://aka.ms/vc14/vc_redist.x64.exe`, версия `14.52.37029.0`, 18 863 624 байта,
SHA256 `9c02c9ed120f671b900e6b9471d890e3b2414117a7e53f4ae2e855466fa2b7c9`.
URL не используется для автоматической загрузки: другие байты отклоняются.
Из закреплённого встроенного CAB извлекается только x64 `vcruntime140.dll`,
версия `14.52.37029.0`, SHA256
`eb1ee979bf02324712346e5bdbfc59c785388bc50d18cc2688aae4b0734915a0`.
Native Authenticode installer, внутреннего MSI/CAB и DLL фактически проверен:
`Valid`, Microsoft Corporation; signer DLL — Microsoft Windows Software
Compatibility Publisher, thumbprint `FD696F80D310D74E24DCE1F5120B8C00C67DE42A`.
Точные pins, CAB offset/member, подписи и лицензия — в
`build/common/windows-crt.json`; при каждой сборке installer/CAB/DLL заново
проверяются через native Windows PowerShell. EXE/MSI никогда не исполняются:
существующий 7-Zip 23.01 читает CAB через stdout, без установки CRT в систему.
DLL размещается рядом с `php.exe` по
[правилам загрузчика Windows](https://learn.microsoft.com/en-us/windows/win32/dlls/dynamic-link-library-search-order).
В пакет входят DLL, проверяемый record `runtime/WINDOWS-CRT.json`, оригинальный
документ лицензии и notice; их хеши входят в manifest, CRT record — в provenance.

**Release/publication gate:** подпись подтверждает происхождение, а не право
владельца распространять Microsoft DLL. [REDIST VS 2026](https://learn.microsoft.com/en-us/visualstudio/releases/2026/redistribution#visual-c-runtime-files)
обусловливает распространение наличием надлежащей лицензии Visual Studio/Build
Tools и соблюдением её условий. Такое право владельца здесь NOT VERIFIED.
Отдельная [runtime EULA](https://visualstudio.microsoft.com/license-terms/vs2026-ga-visualcpp-v14-redist-runtime/)
сама по себе права распространения не даёт. До подтверждения применимого REDIST
права локальная техническая сборка не разрешает публикацию архива.
System32 источником не используется; Visual Studio устанавливать для извлечения не нужно.
См. [условия redistribution](https://learn.microsoft.com/en-us/cpp/windows/redistributing-visual-cpp-files?view=msvc-170),
[app-local deployment](https://learn.microsoft.com/en-us/cpp/windows/choosing-a-deployment-method?view=msvc-170)
и [UCRT на Windows 10/11](https://learn.microsoft.com/en-us/cpp/windows/universal-crt-deployment?view=msvc-170).

## Start on each OS

Распакуйте архив своей платформы в папку, где у вас есть право записи.
Сохраняйте структуру папки; переносите её целиком.

Linux, из распакованной папки:

```bash
./vacancy-monitor
```

Windows, в обычном PowerShell из распакованной папки:

```powershell
.\vacancy-monitor.exe
```

macOS arm64, после нативной сборки и проверки:

```bash
./vacancy-monitor
```

Запуск из пути с пробелами и не-ASCII именем поддерживается.
Без интерактивного терминала приложение сообщает об ошибке и возвращает
ненулевой код. Русский — язык меню при каждом новом запуске.

PHP выбирается относительно расположения launcher. Unix запускает его с
`-n` и приватным каталогом расширений; Linux также использует свой loader
и `--library-path`. Windows загружает только явно указанные app-local
расширения при `-n`. Системные ini не участвуют. Ключи не передаются
аргументами процесса. В комплект входит закреплённый CA bundle.
Перед запуском launcher проверяет SHA256 публичных файлов по манифесту;
удалённый или повреждённый runtime останавливает приложение, без fallback
на системные библиотеки. Приватные файлы в манифест не входят.

## Private setup

Публичный архив не содержит `config/bot.ini`,
`config/profile/local/`, `var/`, журналов и пользовательской базы.
Файлы в `runtime/` — публичные компоненты исполнения, а не ваши настройки.

1. Создайте `config/bot.ini` по `config/bot.example.ini`.
2. Укажите токен бота, полученный у BotFather.
3. Отправьте боту `/start`; через меню узнайте свой ID и укажите
   `TELEGRAM_ALLOWED_USER_ID`.
4. Подготовьте `config/profile/local/candidate.md` и
   `config/profile/local/instructions.md` по указаниям
   [подготовки профиля](../../config/profile/README.md).
5. Укажите параметры AI-подключения и выполните проверку готовности.
6. Запустите мониторинг и пересылайте вакансии в личный чат с ботом.

Состояние хранится в `var/` внутри распакованной папки. При обновлении
сохраняйте приватные настройки и данные отдельно; сборщик не переносит их
из checkout и не выполняет миграцию пользовательской установки.

При `AI_PROVIDER_LOCATION="cloud"` текст вакансии, профиль и инструкции
передаются выбранному AI-сервису. Выбирайте этот режим осознанно.

## Screens and navigation

Шесть основных действий: мониторинг, готовность, поиск ID, справка,
язык и выход. Каждый результат предлагает следующее действие.
Стрелки/Enter выбирают пункт; Esc возвращает назад, в главном меню оставляет
главное меню открытым. Ошибка Gum не считается успешным выбором.
Язык сохраняется только до закрытия приложения.

- **Справка:** пять отдельных тем с Markdown-оформлением и навигацией.
- **Готовность:** компактная сводка среды, настроек, профиля и хранилища.
  «Подробнее» открывает категории; есть повтор и возврат.
- **Поиск ID:** инструкция → «Проверить сейчас» → результат → повтор/меню/выход.
  Нет автоматического опроса. Список ID разбит на страницы по пять строк.
- **Мониторинг:** foreground PHP без очистки живого вывода. После завершения
  или ошибки можно запустить снова, вернуться в меню или выйти.
  Автоматического перезапуска и отдельного AI-retry в оболочке нет.

На Linux Ctrl+C останавливает дочерний процесс и завершает приложение с
кодом 130; это проверено синтетическим foreground-процессом без бота.
Windows launcher помещает дочернее дерево в Job Object до начала исполнения;
закрытие job завершает оставшиеся дочерние процессы. Реальное интерактивное
поведение Ctrl+C на Windows ещё требует проверки.

Готовность работает без сети и записи. Она проверяет текущий PHP приложения,
расширения, наличие autoload, формат параметров INI, доступность/размер файлов
профиля и права хранилища. Содержимое профиля, сетевое соединение,
действительность ключей на сервере, активная блокировка и запись реальной
SQLite не проверяются. Значения INI и исключения валидаторов не выводятся.

Readiness checkout следует объявленному Composer `^8.5`: PHP >=8.5.0, <9.0.
Это не рекомендация применять старый security patch. Portable-режим отдельно
требует PHP >=8.5.12, <8.6; закреплённые runtime сборки не понижены.
Обе границы проверяются синтетическими version-ID без запуска устаревшего PHP.

После сбоя run/identify экран показывает фиксированную безопасную категорию,
короткую подсказку и код завершения. Категории включают настройки/профиль,
polling-конфликт/lock, авторизацию, TLS, сеть и хранилище. Неизвестная ошибка
остаётся неизвестной: её текст не подставляется в экран. В частности,
`identify --json` намеренно скрывает детали исключения; его контракт не изменён.
Сырые stderr, HTTP-ответы, ключи, профиль и stack trace не сохраняются.
Общий PHP helper читает stderr порциями до 4096 байт с перекрытием 512 байт,
а stdout мониторинга наследуется напрямую и продолжает поступать в терминал.
Только категория и код временно записываются в системный TEMP вне приложения;
файл удаляется после результата и при обычном выходе оболочки. При принудительном
завершении ОС может остаться только этот короткий безопасный record, не raw log.

Публичные CLI-команды `identify`, `run`, `work-once` сохранены.
Узкий режим `identify --json` возвращает `found`/`empty`/`error` и список
ID для двух оболочек. Он сохраняет запрос `getUpdates(0, 0)`, не подтверждает
обновления и не снимает webhook. ID показываются только локально.

При режиме webhook меню объясняет ограничение и не запускает getUpdates.
Возврат к polling выполняет владелец отдельно, сохраняя ожидающие обновления.
Локальная поставка не содержит `public/webhook.php` и HTTP receiver.
Серверный сценарий описан в [VDS guide](vds-webhook.md).

## Local model

Модель и OpenAI-совместимый сервер запускаются отдельно.
В INI укажите `AI_PROVIDER_LOCATION="local"`, `AI_BASE_URL`,
`AI_MODEL` и ключ, если он нужен серверу. Пример базового URL:
`http://127.0.0.1:1234/v1`. Совместимость конкретного API проверяется
отдельно; меню не устанавливает модели и не меняет сеть.

Если сервер модели находится на другой машине или в другой сетевой среде,
`127.0.0.1` может быть неверным адресом. Проверка готовности этого не определяет.

## Сборка и выпуск — только для владельца

**Пользователь скачивает один готовый архив своей ОС из опубликованного
GitHub Release владельца. Ему не нужны Git, clone/fork, Python, Composer,
сборка или доступ к GitHub Actions.** Все процедуры этого раздела предназначены
только владельцу и сопровождающим проекта.

Единственный канонический интерфейс сборки — `build/release.py --targets ...`.
Выбор обязателен: `linux`, `windows`, `macos`, любой непустой набор через
запятую без повторов либо `all`. Порядок имён значения не имеет.

| Сборочный хост | Доступные локальные цели |
| --- | --- |
| Linux x86_64 | `linux`; fresh runtime требует закреплённых библиотек Ubuntu 24.04 |
| WSL2 Ubuntu x86_64 с проверенным Windows x64 interop | `linux`, `windows`, `linux,windows` |
| Native Windows x64 | `windows` |
| Native macOS arm64 / Apple Silicon | `macos` |

Все выбранные цели проверяются до начала сборки. `all` на локальном хосте
завершается ошибкой с указанием единого workflow: ни один хост этой таблицы
не может собрать все три платформы. Rosetta, Windows ARM и другие cross-targets
не заявлены. Отсутствующие prerequisites не устанавливаются локальным CLI.

Все промежуточные файлы, загрузки и архивы создаются **вне checkout**.
Индекс Git, рабочий vendor и приватные данные сборщик не изменяет.

Сборочные требования: Python 3.9+, Git,
выбранный PHP 8.5 CLI и Composer 2. Для Unix source build нужны C compiler,
make, pkg-config, GnuPG и системный SDK/dev headers; для Linux — Ubuntu 24.04
amd64 с OpenSSL dev headers. Глобальные пакеты автоматически не устанавливаются.

Linux runtime собран GCC 13 на Ubuntu 24.04. Закреплённые бинарные библиотеки:
glibc `2.39-0ubuntu8.12` (`libc6:amd64`), OpenSSL `3.0.13-0ubuntu3.16`,
libgcc `14.2.0-4ubuntu2~24.04.1`.
Их точные SHA256 находятся в `build/common/linux-libraries.json`;
другой комплект останавливает сборку. Это ограничение воспроизводимости,
а не разрешение навсегда оставлять старые security packages.

Для snapshot glibc `2.39-0ubuntu8.12` проверены подписи Ubuntu
`noble-updates` и `noble-security` InRelease ключом Ubuntu Archive (2018)
`F6ECB3762474EDA9D21B7022871920D1991BC93C`, SHA256 индексов Packages
из этих подписанных metadata и запись точной версии amd64. Архив
`pool/main/g/glibc/libc6_2.39-0ubuntu8.12_amd64.deb` из
`https://archive.ubuntu.com/ubuntu/` совпал с указанным в индексах SHA256
`d821b41fc59cb4978ecd53bc83e5c964ac15d305c8d2ac4c8a35aebe55010fb9`.
Байты `libc.so.6`, `libm.so.6` и `ld-linux-x86-64.so.2` внутри пакета
совпали с системными оригиналами и отдельными копиями неудавшейся сборки;
`dpkg -V libc6:amd64` завершился с кодом 0 без замечаний,
MD5 всех трёх оригиналов совпали с installed-package md5sums.
Одного `apt-cache policy` для подтверждения происхождения недостаточно.

Это фиксированный проверенный snapshot, не автоматическое разрешение будущих
обновлений. При отличии состава или SHA256 сборщик останавливается и перечисляет
каждый несовпавший файл с ожидаемым и фактическим SHA256; отсутствие файла
помечается `<отсутствует>`. Перед изменением pins нужно проверить пакет,
подписанные metadata и байты оригиналов/копий, а не переносить хеш из ошибки.
Эта проверка не подтверждает готовность новых архивов или совместимость ОС.

Из checkout, пример для владельца на Linux:

```bash
python3 build/release.py --targets linux --build-php /usr/bin/php8.5 --composer /path/to/composer.phar --cache /tmp/tvm-inputs --output-dir /tmp/tvm-output
```

На Windows x64, при наличии сборочных Python/PHP/Composer:

```powershell
python build/release.py --targets windows --build-php D:\tools\php\php.exe --composer D:\tools\composer.phar --cache D:\tvm-inputs --output-dir D:\tvm-output --windows-crt-installer D:\tvm-crt\vc_redist.x64.exe --windows-crt-license D:\tvm-crt\Microsoft-CRT-LICENSE.docx
```

На macOS 14+ arm64, с Xcode Command Line Tools и сборочными Python/PHP/Composer:

```bash
python3 build/release.py --targets macos --build-php /path/to/build/php --composer /path/to/composer.phar --cache /private/tmp/tvm-inputs --output-dir /private/tmp/tvm-output
```

Пути PHP/Composer здесь относятся только к **сборке**, не к команде пользователя.
Для разработки checkout можно явно задать `PHP_BINARY`; production launcher
такой override игнорирует. Native macOS recipe использует системные
libcurl/SQLite из SDK и проверяет отсутствие внешних dylib, затем ставит
ad-hoc подпись. Recipe ещё не был выполнен на Mac.

На проверенном WSL2 Ubuntu x64 с Windows interop владелец может выбрать обе цели:

```bash
python3 build/release.py --targets linux,windows --build-php /usr/bin/php8.5 --composer /path/to/composer.phar --cache /path/outside-checkout/cache --output-dir /path/outside-checkout/output --windows-crt-installer /path/windows-accessible/vc_redist.x64.exe --windows-crt-license /path/windows-accessible/Microsoft-CRT-LICENSE.docx
```

CLI сам добавляет существующий `--cross-windows` только для этого сочетания.
Это сборка Windows-кандидата, а не native Windows smoke. Опция `--preflight`
проверяет условия без записи, загрузок и сборки; она запускает только проверки
уже установленных инструментов. `--help` ничего не собирает.
Без `--cache` создаётся отдельный cache в новой сессии; без `--output-dir`
родителем сессии служит системный временный каталог. Явные пути должны быть
вне checkout, без symlink в пути. Существующие архивы не перезаписываются.

Для Windows обязательны `--windows-crt-installer` и `--windows-crt-license`:
локальные обычные файлы вне checkout, без symlink в пути. Документ лицензии —
официальный DOCX из URL в CRT manifest, SHA256
`08651651a7602fc7c0e2763de0fde1ff9f868df2780597cd1775ee9d6441c783`.
Нужны уже установленный 7-Zip **23.01** и native `powershell.exe`; на WSL2 —
доступный Windows interop и `wslpath`. Installer должен лежать в доступном
Windows каталоге с правом создать временный соседний каталог для CRT-проверки.
Подписи должны получить статус Valid от Windows trust policy; `--offline`
не отключает Authenticode и не гарантирует отсутствие обращений Windows за
сертификатами/проверкой отзыва. Сборщик CRT ничего не скачивает и не устанавливает.
7-Zip — только build-time инструмент ([лицензия upstream](https://www.7-zip.org/license.txt)),
в приложение не включается. Он получает только закреплённые CAB после SHA256
проверки и выводит выбранный member в stdout, без распаковки произвольных путей.

Версионируемая логика принадлежит репозиторию: `release.py` вызывает только
`build/common/package.py`. Старые платформенные wrappers остаются низкоуровневыми
адаптерами. Личная внешняя Linux+Windows обёртка устарела как процедура выпуска,
сохраняется без изменений как историческое свидетельство и больше не требуется.
Архивы, cache, рабочие копии и журналы остаются вне репозитория.

### Один manual workflow для всех платформ

Владелец запускает `standalone-bundles.yml` в Actions с одним из семи вариантов:
`linux`, `windows`, `macos`, `linux,windows`, `linux,macos`, `windows,macos`, `all`.
Для обычного запуска через `workflow_dispatch` файл должен присутствовать
в default branch; определение YAML само по себе ничего не запускает.
Все выбранные jobs получают один точный `github.sha`. Каждый job использует
тот же `build/release.py --targets <одна-платформа>`, а затем native smoke.
Checkout сохраняет LF на всех ОС через process-only `core.autocrlf=false`,
чтобы Windows не меняла байты, сверяемые с Git commit.
Проверяются имя, sidecar, provenance target/inputs и каждый записанный хеш
исходников/сборщика против выбранного Git commit. Сам упаковщик сохраняет
хеши файлов, а не отдельное поле Git SHA; связь с SHA устанавливает CI-проверка.
Локальная опция `--source-sha <полный-SHA>` также требует чистый checkout.

Runners: `ubuntu-24.04` x64, `windows-2025` x64 и `macos-15` arm64.
Метка ОС не фиксирует все байты образа runner, поэтому версии и архитектуры
проверяются явно. Linux собирается в одноразовом Ubuntu 24.04 amd64 контейнере
с digest из `build/common/ci-tools.json` и официальным snapshot
`20261115T000000Z`. APT проверяет подписи и package hashes; дополнительно
проверяются точные InRelease, подписант и SHA256 ключевых DEB, headers и все
шесть ELF/loader pins до компиляции и после установки build PHP.
Пакеты на GitHub host не устанавливаются и не заменяются.

Linux использует Linux entrypoint официального `setup-php` из точного commit
`f3e473d116dcccaddc5834248c87452386958240` внутри контейнера; Windows/macOS —
action того же commit. Владелец **осознанно доверяет обновляемым штатным
bootstrap-компонентам этого provider на всех трёх CI-платформах**, включая
Linux php-builder, Windows PhpManager и macOS Homebrew. Это исключение только
для build-time PHP 8.5 на одноразовом CI. SHA action не закрепляет весь
исполняемый транзитивный код; последующие проверки версии PHP не предотвращают
исполнение скомпрометированного bootstrap. Этот остаточный supply-chain риск
принят владельцем. Компоненты provider не попадают в standalone runtime.
Composer 2.11.2, полный 7-Zip 23.01, CRT-входы и распространяемые runtime
остаются под отдельными точными hash/signature gates.

Windows job извлекает полный 7-Zip 23.01 из проверенного официального installer
с помощью runner 7-Zip; installer не запускается. CRT installer и DOCX
скачиваются по существующим manifest URL в runner TEMP, проходят SHA256/size,
затем неизменённые Authenticode/CAB/DLL-проверки упаковщика.
Изменение байтов по URL останавливает job. На Mac проверяются Xcode SDK,
compiler, GnuPG, pkg-config, otool и codesign; runtime использует системный SDK.

Linux smoke запускается на native Ubuntu runner вне Docker, с `bwrap` из
проверенного snapshot DEB, распакованного только в runner TEMP. Для создания
namespaces тест запускается через `sudo -n`; доступность namespaces и библиотек
проверяется до компиляции. Host packages, AppArmor, seccomp и sysctl не меняются;
контейнер не получает privileged/SYS_ADMIN и Docker socket. Smoke использует
изолированную временную копию и не вызывает бота/AI. Обычным локальным сборкам
Docker и этот CI bootstrap не нужны.

Workflow имеет только `contents: read`, не получает секретов и не публикует
Release. После успешного native smoke выбранного job доступны отдельные
workflow artifacts: только готовый архив и его `.sha256`. Транспортный ZIP
GitHub не является standalone-поставкой. Ошибка выбранного job означает ошибку
запуска workflow; оставшиеся успешные файлы не доказывают готовность выпуска.
Реальное выполнение нового workflow на всех трёх runners пока **NOT VERIFIED**;
macOS-архив по-прежнему не собран.

Для публичного выпуска обязательны три независимо проверенных архива одного
source SHA с валидными sidecars, ручные проверки на целевых ОС и закрытые
юридические/security gates ниже. Владелец вручную скачивает результаты,
создаёт GitHub Release и прикладывает три исходных standalone-архива и три
sidecar. Скрипты сборки остаются в репозитории. Автопубликации нет.

Входные URL, версии и SHA256 закреплены в `build/common/inputs.json`.
Официальные источники:
[PHP release metadata](https://www.php.net/releases/index.php?json&version=8.5.12),
[Windows PHP](https://www.php.net/downloads.php?os=windows),
[Gum 2.0.2](https://github.com/charmbracelet/gum/releases/tag/v2.0.2),
[curl](https://curl.se/download.html),
[SQLite](https://www.sqlite.org/download.html),
[CA bundle](https://curl.se/docs/caextract.html).
PHP 8.5.12 — security release; для Windows выбран обновлённый архив 8.5.12-1.
Для SQLite дополнительно закреплён опубликованный SHA3-256.
CA snapshot от 2026-11-05 закреплён по SHA256: изменившийся upstream
останавливает загрузку вместо молчаливого обновления.

Перед исполнением проверяются хеши и весь список членов архива: абсолютные
пути, traversal, ссылки, специальные файлы и дубликаты отклоняются.
Архитектура проверяется smoke на целевой ОС. Загрузка идёт только по HTTPS
с проверкой сертификата. `--offline` разрешает только уже полученные
и совпадающие по хешу файлы. Composer при создании production autoload
работает без сети; текущий lock не содержит сторонних PHP-пакетов.

PGP-подпись curl 8.23.0 фактически проверена ключом
`27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2` с сайта автора.
PGP-подпись PHP проверена ключом release manager
`D95C03BC702BE9515344AE3374E44BC9067701A5` с официальной страницы php.net.
Сборщик проверяет обе подписи через GnuPG, включая fingerprint подписанта.
Gum checksum manifest проверяется Cosign 3.1.4 по Sigstore bundle,
GitHub Actions identity и OIDC issuer из инструкции upstream.
Build-only Cosign (Apache-2.0) берётся из официального sigstore/cosign release:
его SHA256 закреплён до исполнения. Trust root закреплён точным commit
официального sigstore/root-signing и SHA256. Cosign не включается в приложение.
Все материалы доступны из cache для offline сборки.
Go 1.27.4 (BSD) также загружается только для сборки в отдельный временный
каталог с проверкой SHA256 из официального go.dev/dl. Системный Go
не используется; автоматическое переключение toolchain отключено.

StaticPHP рассмотрен как build provider, но не используется: проверенный
каталог готовых runtime отставал от PHP 8.5.12. Нет зависимости от floating
SPC blobs или Homebrew PHP. Linux использует проверенные исходники
PHP/curl/SQLite; Windows — официальный NTS ZIP.

Пакет сохраняет notices PHP, Gum, Go и Linux-библиотек.
Полнота notices всех транзитивных библиотек Windows и встроенных компонентов
PHP ещё должна быть подтверждена перед выпуском. Лицензия приложения
этим документом не устанавливается.

Результат: уникальный каталог с архивом, файлом `.sha256`,
`SHA256SUMS.json` внутри папки и `runtime/PROVENANCE.json`.
Provenance включает входы, хеши публичных исходников и build host.
По умолчанию в provenance стоит NOT VERIFIED до отдельного native smoke;
сам факт сборки не объявляет smoke успешным. Побайтовая идентичность архивов
между сборками не обещается: остаются timestamps/toolchain metadata.

Для повторной упаковки своих исходников без компиляции runtime можно передать
`--linux-runtime-bundle /path/to/trusted/archive --linux-runtime-sha256 <SHA256>`;
аналогичные пары имеют префиксы `--windows-` и `--macos-`.
Проверяются SHA архива, входы, target и хеши runtime из манифеста. Используйте
только ранее собранный доверенный архив; это не приём произвольного PHP blob.
В Windows reuse дополнительно проверяет полноту runtime manifest и CRT provenance
(если он уже был). Прежний доверенный архив без CRT допускается: после проверки
runtime обязательная CRT-процедура добавляет DLL и актуальные notices. Повторное
использование не отменяет CRT-параметры и не требует перекомпиляции PHP.
Это необязательная оптимизация для той же платформы и текущих pins, без
автопоиска случайных опубликованных архивов и без переноса Linux PHP на другую ОС.

Проверки разработчика:

```bash
composer check
composer audit --locked
```

`composer check` сохраняет все PHP/03 suites. Lint выполняется PHP-скриптом,
без Unix find/xargs. `tests/PlatformSmoke.php` выбирает дополнительную UI-проверку
по реальному PHP_OS_FAMILY: на Linux — прежние Python PTY-сценарии через Bash;
на Windows — PowerShell 5.1 synthetic scenarios функций frontend с общим helper.
Windows-тест не требует Bash/WSL/Python. На macOS эта UI-suite явно сообщает
NOT VERIFIED; PHP suites и lint продолжают выполняться.
Synthetic Windows проверяет категории, secrecy, Again/Main, identify, RU/EN
и живой stdout, но не эмулирует настоящий Gum console и не доказывает Ctrl+C.
Python и PowerShell здесь — build/test tooling; distributed app Python не вызывает.

Архивные native проверки запускаются отдельно, только при наличии артефакта:

```bash
bash tests/PortableRuntimeSmoke.sh /path/to/linux-bundle.tar.gz
bash tests/MacOSPortableSmoke.sh /path/to/macos-bundle.tar.gz
```

Windows smoke на native host:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\WindowsPortableSmoke.ps1 -Archive C:\Temp\telegram-vacancy-monitor-windows-x64.zip
```

Bypass действует только на процесс теста/launcher и не меняет системную policy.
Организационная Group Policy может запретить неподписанные скрипты.

Linux portable smoke требует Python 3, bwrap, разрешённые user/mount/network
namespaces и PTY. Он распаковывает отдельную копию, проверяет SHA, отсутствие
приватных данных, настоящий runtime, autoload и SQLite в памяти; затем проходит
настоящий Gum Help/Back/Exit в namespace без host PHP/Composer/Gum.
Сеть изолирована. UI fake-тесты используют синтетический INI/профиль,
не реальный бот, и отдельно проверяют Ctrl+C, ошибки, повтор, язык и отмену.

## Limitations

- macOS: нет собранного архива и native smoke. Нет Developer ID signing,
  notarization или проверки Gatekeeper/quarantine. Ad-hoc подпись не решает
  распространение через Gatekeeper. Intel Mac не поддерживается.
- Windows: CRT включается новым сборщиком; старые архивы без CRT остаются
  неполными. Право публичного распространения — отдельный открытый release gate.
  Чистые Windows 10/11 VM без host CRT, cleanup при Ctrl+C и политика подписей
  требуют проверки. Реальные Windows 11 RU/EN/Help/readiness/identify/live
  vacancy-to-Телеграмм сценарии на прежнем архиве владелец уже прошёл.
- Linux: clean namespace проверен на одном kernel; минимальный kernel baseline,
  DNS/TLS на чистых дистрибутивах и другие libc hosts ещё не измерены.
- Проверки не запускают живой бот, не обращаются к AI и не проверяют реальную БД.
- Полный аудит транзитивных upstream notices остаётся release gate.
- Build-only Go не нужен пользователю; используется закреплённый Go 1.27.4.
- Никакие архивы не опубликованы; окончательная приёмка требует распаковки
  и ручного запуска владельцем на чистых целевых системах.

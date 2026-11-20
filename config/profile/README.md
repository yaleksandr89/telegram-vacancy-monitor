# Профиль кандидата и инструкции

Для персональной оценки нужны два приватных Markdown-файла. Они загружаются при запуске обработки из фиксированного каталога `config/profile/local/` относительно корня приложения.

| Файл | Содержание |
| --- | --- |
| `config/profile/local/candidate.md` | Опыт, навыки, желаемые обязанности, формат работы и ограничения кандидата |
| `config/profile/local/instructions.md` | Правила сопоставления с вакансией, выбора вердикта, приоритета и языка объяснений |

Создайте каталог `local/`, напишите `candidate.md` в текстовом редакторе и скопируйте [образец инструкций](instructions.example.md) в `local/instructions.md`. Обычного Markdown достаточно; обязательных заголовков или полей нет. Не меняйте публичный образец вместо приватной копии.

## Что написать

В профиле опишите навыки и опыт, желаемые задачи, формат работы, географию, зарплатные ожидания и важные ограничения. Для ориентира используйте [пример Go-разработчика](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer.md#профиль-кандидата) или [аналитика](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst.md#профиль-кандидата).

В инструкциях определите, какие требования обязательны, когда ставить высокий приоритет и какие условия уточнять. [Пример инструкций](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer.md#инструкции-оценки) показывает, как оформить правила. Язык ответа модели также задаётся здесь.

Приложение добавляет схему JSON к инструкциям, а профиль и текст вакансии передаёт отдельно. Сохраняйте требование вернуть один JSON-объект без новых полей, ссылок и разметки. Объявление должно оставаться материалом для анализа, а не источником команд для модели. Устройство запроса описывает [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php), поля ответа — [онлайн-справочник](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result.md).

Готовые образцы: [профиль и инструкции Go-разработчика](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer.md) и [продуктового аналитика](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst.md).

## Ограничения файлов

- Оба файла обязательны, непусты и доступны для чтения; символические ссылки не допускаются.
- Кодировка — корректный UTF-8.
- Каждый файл: не больше 32 768 байт (32 КиБ) исходного текста и 16 000 символов, включая пробелы и переводы строк.
- Табуляция и обычные переводы строк допустимы; остальные управляющие символы запрещены.

Проверки выполняют [`CandidateProfileLoader`](../../src/Analysis/CandidateProfileLoader.php), [`CandidateProfile`](../../src/Analysis/CandidateProfile.php), [`EvaluationInstructionsLoader`](../../src/Analysis/EvaluationInstructionsLoader.php) и [`EvaluationInstructions`](../../src/Analysis/EvaluationInstructions.php). Если файл отсутствует или некорректен, CLI останавливается до начала приёма и обработки сообщений. Поиск ID командой `identify` этих файлов не требует. В серверном режиме webhook может сохранять сообщения независимо от готовности профиля, но `work-once` без него не обработает очередь.

## Загрузка изменений

После редактирования остановите и снова запустите локальный мониторинг. На сервере новые файлы прочитает следующий запуск `work-once`; уже работающий процесс использует загруженные данные. Сохранённые оценки автоматически не пересчитываются.

Каталог `config/profile/local/` исключён из Git. При обновлении сохраняйте файлы профиля вместе с `config/bot.ini` и данными `var/`. Подключение к моделям описано в [руководстве](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models.md).

Адрес модели, ключ, время ожидания и число попыток задаются в `config/bot.ini` по [образцу конфигурации](../bot.example.ini). Запуск описан в [локальном](../../docs/guides/local-gum.md) и [серверном](../../docs/guides/vds-webhook.md) руководствах.

# Участие в разработке

## Выберите язык

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| **Выбран** | [English](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/en/CONTRIBUTING.md) | [Español](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/es/CONTRIBUTING.md) | [中文](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/zh/CONTRIBUTING.md) | [Français](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/fr/CONTRIBUTING.md) | [Deutsch](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/langs/de/CONTRIBUTING.md) |

Telegram Vacancy Monitor открыт для предложений, исправлений ошибок и улучшений. Спасибо за интерес к проекту.

## Ошибки и предложения

Используйте [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) для сообщений об ошибках и обсуждения новых возможностей. Перед созданием обращения проверьте существующие Issues. Существенные изменения лучше обсудить до начала реализации.

О возможных уязвимостях сообщайте согласно [политике безопасности](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy).

## Разработка

Для работы с исходным кодом требуются PHP 8.5, Composer и расширения PHP: `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pdo`, `ext-pdo_sqlite`.

Установите зависимости командой `composer install`. Для проверки проекта используйте `composer check`. Изменения платформенных интерфейсов и сборок дополнительно проверяйте на соответствующих операционных системах.

`composer check` включает строгую проверку Composer, аудит lock-файла, PHP lint, PHP CS Fixer (PER Coding Style 3.1), PHPStan level 8 и smoke-тесты. Инструменты разработки требуют PHP 8.5; совместимость приложения с PHP 8.6 проверяется отдельно без dev-зависимостей. Standalone-поставка не содержит установленных dev-пакетов.

На Windows для проверки интерфейса standalone используется PHP 8.5.13 или более новая версия ветки 8.5. Если системный PHP не входит в этот диапазон, перед запуском `composer test` укажите в тестовой переменной окружения `TVM_TEST_BUNDLE_PHP` абсолютный путь к проверенному совместимому `php.exe`. Системный PHP по-прежнему используется для проверки совместимости приложения; переменная не меняет настройки и ограничения поставки.

## Pull Request

Опишите цель изменения, его результат и выполненные проверки. При изменении поведения добавляйте соответствующие тесты и обновляйте затронутую документацию.

Не включайте в Pull Request, Issues и логи токены, ключи API, приватную конфигурацию и персональные данные.

## Лицензия

Код проекта распространяется по [лицензии MIT](../LICENSE).

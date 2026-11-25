# Contributing

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | **Selected** | [Español](../es/CONTRIBUTING.md) | [中文](../zh/CONTRIBUTING.md) | [Français](../fr/CONTRIBUTING.md) | [Deutsch](../de/CONTRIBUTING.md) |

[← Back to README](README.md)

Telegram Vacancy Monitor welcomes suggestions, bug fixes, and improvements. Thank you for your interest in the project.

## Issues and suggestions

Use [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) to report bugs and discuss new features. Check existing issues before opening one. Discuss substantial changes before starting implementation.

Report potential vulnerabilities in accordance with the [security policy](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy).

## Development

Working with the source requires PHP 8.5, Composer, and the PHP extensions `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pdo`, and `ext-pdo_sqlite`.

Install dependencies with `composer install`. Run `composer check` to check the project. Changes to platform interfaces and bundles must additionally be tested on the relevant operating systems.

`composer check` includes strict Composer validation, a locked dependency audit, PHP lint, PHP CS Fixer (PER Coding Style 3.1), PHPStan level 8 and smoke tests. Development tools require PHP 8.5; application compatibility with PHP 8.6 is tested separately without dev dependencies. Standalone distributions contain no installed dev packages.

On Windows, standalone UI tests use PHP 8.5.13 or a later PHP 8.5 patch release. If your system PHP is outside that range, set the test-only environment variable `TVM_TEST_BUNDLE_PHP` to the absolute path of a verified compatible `php.exe` before running `composer test`. The system PHP is still used to test application compatibility; this variable does not change bundle settings or restrictions.

## Pull Request

Describe the purpose and outcome of the change and the checks you performed. When behavior changes, add the corresponding tests and update affected documentation.

Do not include tokens, API keys, private configuration, or personal data in pull requests, issues, or logs.

## License

The project code is distributed under the [MIT License](../../../LICENSE).

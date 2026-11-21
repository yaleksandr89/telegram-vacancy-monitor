# Contributing

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | **Selected** | [Español](CONTRIBUTING_es.md) | [中文](CONTRIBUTING_zh.md) | [Français](CONTRIBUTING_fr.md) | [Deutsch](CONTRIBUTING_de.md) |

[← Back to README](../readme/README_en.md)

Telegram Vacancy Monitor welcomes suggestions, bug fixes, and improvements. Thank you for your interest in the project.

## Issues and suggestions

Use [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) to report bugs and discuss new features. Check existing issues before opening one. Discuss substantial changes before starting implementation.

Report potential vulnerabilities in accordance with the [security policy](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy).

## Development

Working with the source requires PHP 8.5, Composer, and the PHP extensions `ext-curl`, `ext-json`, `ext-mbstring`, `ext-pdo`, and `ext-pdo_sqlite`.

Install dependencies with `composer install`. Run `composer check` to check the project. Changes to platform interfaces and bundles must additionally be tested on the relevant operating systems.

## Pull Request

Describe the purpose and outcome of the change and the checks you performed. When behavior changes, add the corresponding tests and update affected documentation.

Do not include tokens, API keys, private configuration, or personal data in pull requests, issues, or logs.

## License

The project code is distributed under the [MIT License](../../LICENSE).

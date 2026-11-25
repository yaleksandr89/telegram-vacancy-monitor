# 参与贡献

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | [English](../en/CONTRIBUTING.md) | [Español](../es/CONTRIBUTING.md) | **已选择** | [Français](../fr/CONTRIBUTING.md) | [Deutsch](../de/CONTRIBUTING.md) |

[← 返回 README](README.md)

Telegram Vacancy Monitor 欢迎功能建议、错误修复与改进。感谢你关注本项目。

## 问题反馈与建议

请通过 [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) 报告错误并讨论新功能。创建 Issue 前请先查看现有问题。重大改动最好在开始实现前先讨论。

发现潜在漏洞时，请遵循[安全政策](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy)进行报告。

## 开发

从源码开发需要 PHP 8.5、Composer，以及 PHP 扩展 `ext-curl`、`ext-json`、`ext-mbstring`、`ext-pdo`、`ext-pdo_sqlite`。

运行 `composer install` 安装依赖。使用 `composer check` 检查项目。涉及特定平台的界面和打包内容的更改，还必须在对应的操作系统上验证。

`composer check` 包含 Composer 严格校验、锁定依赖审计、PHP 语法检查、PHP CS Fixer（PER Coding Style 3.1）、PHPStan level 8 和冒烟测试。开发工具需要 PHP 8.5；应用对 PHP 8.6 的兼容性单独在不安装开发依赖的情况下验证。独立发行包不包含已安装的开发依赖。

在 Windows 上，独立发行包的界面测试使用 PHP 8.5.13 或 8.5 分支中更新的补丁版本。如果系统 PHP 不在此范围内，请在运行 `composer test` 前将仅供测试使用的环境变量 `TVM_TEST_BUNDLE_PHP` 设置为经过验证且兼容的 `php.exe` 的绝对路径。应用兼容性仍使用系统 PHP 进行测试；此变量不会改变发行包的设置或限制。

## Pull Request

说明改动目的、实现结果和已执行的检查。如果修改了行为，应增加相应测试并更新受影响的文档。

不要在 Pull Request、Issue 或日志中包含令牌、API 密钥、私有配置或个人数据。

## 许可证

项目代码采用 [MIT 许可证](../../../LICENSE)。

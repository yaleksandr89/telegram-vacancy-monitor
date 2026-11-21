# 参与贡献

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/.github/CONTRIBUTING.md) | [English](CONTRIBUTING_en.md) | [Español](CONTRIBUTING_es.md) | **已选择** | [Français](CONTRIBUTING_fr.md) | [Deutsch](CONTRIBUTING_de.md) |

[← 返回 README](../readme/README_zh.md)

Telegram Vacancy Monitor 欢迎功能建议、错误修复与改进。感谢你关注本项目。

## 问题反馈与建议

请通过 [GitHub Issues](https://github.com/yaleksandr89/telegram-vacancy-monitor/issues) 报告错误并讨论新功能。创建 Issue 前请先查看现有问题。重大改动最好在开始实现前先讨论。

发现潜在漏洞时，请遵循[安全政策](https://github.com/yaleksandr89/telegram-vacancy-monitor/security/policy)进行报告。

## 开发

从源码开发需要 PHP 8.5、Composer，以及 PHP 扩展 `ext-curl`、`ext-json`、`ext-mbstring`、`ext-pdo`、`ext-pdo_sqlite`。

运行 `composer install` 安装依赖。使用 `composer check` 检查项目。涉及特定平台的界面和打包内容的更改，还必须在对应的操作系统上验证。

## Pull Request

说明改动目的、实现结果和已执行的检查。如果修改了行为，应增加相应测试并更新受影响的文档。

不要在 Pull Request、Issue 或日志中包含令牌、API 密钥、私有配置或个人数据。

## 许可证

项目代码采用 [MIT 许可证](../../LICENSE)。

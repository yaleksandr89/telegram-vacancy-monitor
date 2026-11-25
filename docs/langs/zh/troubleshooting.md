# 故障排除

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/troubleshooting.md) | [English](../en/troubleshooting.md) | [Español](../es/troubleshooting.md) | **已选择** | [Français](../fr/troubleshooting.md) | [Deutsch](../de/troubleshooting.md) |

[← 返回 README](README.md)

先在本地应用中选择“Проверить готовность”（检查准备情况），然后打开相应错误类别的“Подробнее”（详情）。该检查不会访问 Telegram 或 AI，不读取档案内容，也不会写入 SQLite。检查通过只说明本地前置条件符合要求；要测试实际连接，需在机器人运行时发送测试文本。

界面文本位于[界面消息](../../../src/ui/windows/messages.json)，检查逻辑和错误类别位于 [`inspect.php`](../../../src/ui/windows/inspect.php)。寻求帮助时，请提供类别、退出代码、操作系统和启动方式，切勿发送令牌或私人档案内容。

## 应用无法启动

| 症状 | 处理方法 |
| --- | --- |
| “Файлы приложения отсутствуют или повреждены”（应用文件缺失或损坏） | 将可信安装包重新解压到独立目录，保留原有结构，只迁移自己的 INI、档案和 `var/`。 |
| “Нужен интерактивный терминал”（需要交互式终端） | 在普通终端中启动，不要重定向标准输入、输出或错误。 |
| “Среда PHP приложения неполна или устарела”（PHP 环境不完整或版本过旧） | 确认安装包匹配操作系统与架构，并且已完整解压 `runtime/`。 |
| 无法准备启动和诊断 | 检查系统临时目录访问权限及文件执行权限。 |
| Windows 禁止运行脚本 | 检查组织的 PowerShell 执行策略，并与管理员确认。 |
| macOS 阻止下载的应用 | 检查安装包来源和 macOS 安全设置提示的阻止原因。 |

如果公开文件损坏，应恢复**完整安装包**：[可执行程序](../../../src/launcher/main.go)会按清单验证文件。如何在保留数据的情况下重新安装，见[本地指南](local-gum.md#数据和更新)。

源码安装要求 PHP `^8.5`、[`composer.json`](../../../composer.json) 声明的扩展以及 Composer 生成的自动加载器。如果缺少 `vendor/autoload.php`，按 `composer.lock` 安装依赖；不需要更改档案或 SQLite。

## 配置或档案尚未准备好

- 根据[示例](../../../config/bot.example.ini)创建 `config/bot.ini`，确认编辑器没有自动添加 `.txt` 后缀。
- 检查引号与键名。预过滤规则列表必须使用带 `[]` 的重复键。
- 填写令牌、自己账号的 Telegram ID（`TELEGRAM_ALLOWED_USER_ID`）、模型地址与标识符。云模型还需要 API 密钥。
- 创建 `config/profile/local/candidate.md` 和 `config/profile/local/instructions.md` 两份文件。
- 文件必须是普通的非空 UTF-8 文件，不能是符号链接，每份最多 32,768 字节和 16,000 字符。允许制表符和普通换行，不允许其他控制字符。

如果准备检查通过但启动时仍拒绝文件，请检查编码、字符数及内容：菜单只检查文件是否可读和大小。具体限制见[档案指南](profile.md)及[配置参考](configuration.md)。

修改后重启本地监控。在服务器上，下一次 `work-once` 会读取新文件。

## 无法获得 ID

直接发送不带其他文字的 `/start`，再次选择“Проверить сейчас”（立即检查），或在源码安装中执行 `php bin/bot.php identify`。转发的命令或带参数的 `/start` 均不符合要求。查询仅在手动触发时执行，不会确认待处理更新。

如果提示 `var/bot.lock` 被占用，停止另一 polling 进程后重试，不要删除锁文件。源码安装可用 `php bin/bot.php webhook info` 检查注册情况，并按[模式切换步骤](vds-webhook.md#切换模式)通过 `webhook delete` 删除注册。如果出现多个 ID，切勿猜测，使用[核实 ID 的方法](telegram-bot.md#确认你的-id)。首次查询 ID 不需要档案或 AI 配置。

## 鉴权、访问与接收冲突

| 错误 | 原因与处理方法 |
| --- | --- |
| Bot API 401，鉴权失败 | 检查 BotFather 令牌；重新签发后更新 INI。 |
| Bot API 403，聊天不可用 | 确认已与机器人开始对话、没有屏蔽机器人且配置的是自己账号的 ID。 |
| Bot API 409 或 polling 冲突 | 停止第二个实例，用 `php bin/bot.php webhook info` 检查 webhook。 |
| “Занята блокировка var/bot.lock.” | 停止正在运行的 `run` 或 `identify`，不要删除锁文件。 |
| “Недоступна блокировка var/bot.lock.” | 检查私人目录 `var/` 与锁文件的访问权限。 |
| 已选择 webhook，禁止本地启动 | 使用服务器端 `work-once` 或完成切回 polling 的步骤。 |
| Bot API 429 | 最终发送会遵守有效的 `retry_after`；不要启动多个接收进程，等待允许的重试。 |

机器人只能通过 `getUpdates` **或** webhook 接收更新。仅更改 INI 不会取消已注册的 webhook。返回 polling 时执行 `php bin/bot.php webhook delete`（保留待处理更新），用 `webhook info` 确认“Webhook не зарегистрирован.”，然后遵照[切换步骤](vds-webhook.md#切换模式)。

菜单使用的 `identify --json` 模式故意只返回通用错误，不暴露底层异常。错误类别可能未知；这不能证明具体的网络或配置原因。

## 网络或 TLS 错误

访问 Telegram 时请检查 DNS、到 `api.telegram.org` 的 HTTPS 连接、系统时间和证书信任。Standalone 安装包必须保留 `runtime/cacert.pem`。不要通过禁用 TLS 校验绕过错误。

对于 AI，另行检查服务器是否启动、模型是否加载、地址是否能从应用环境访问，以及防火墙端口是否开放。WSL、虚拟机或 VDS 内的 `127.0.0.1` 不指向另一台电脑。`local` 模式应使用可访问的私有 IPv4。

AI 网络传输不使用代理，也不跟随 HTTP 重定向。请直接配置最终 API 基础地址。相关限制由 [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php) 实现。

## 已保存职位但没有评估结果

1. 确保本地监控仍在运行。服务器上检查 cron 调度、执行用户、PHP 与项目的绝对路径以及受限的命令输出。
2. 考虑队列和响应时间：请求顺序执行；服务器按 cron 调度开始处理。
3. 检查模型及 API 格式。基础地址不要附带 `/chat/completions`，应用会自动添加。
4. 对较慢模型，将 `AI_REQUEST_TIMEOUT_SECONDS` 从 `180` 提高到 `300` 或 `600` 并重启处理；范围为 30–1800。
5. 如果出现“Не удалось обработать”，请先修复原因再测试：原职位仍在数据库中，但最终失败的任务不会自动恢复。

成功接收 webhook 并不说明 AI 已准备好：HTTP 处理器不会加载档案或调用模型。`work-once` 必须独立完成配置检查。

要确定处理在哪个阶段停止，请用本地 SQLite 客户端以**只读**方式打开**你自己的** `var/vacancies.sqlite`，查询不包含职位文本的状态：

```sql
-- 只读：查询最近十条状态，不读取职位文本。
SELECT message_id, analysis_status, analysis_error, analysis_attempts,
       delivery_status, delivery_error, delivery_attempts
FROM analysis_jobs
ORDER BY message_id DESC
LIMIT 10;
```

状态由 [`SqliteAnalysisRepository`](../../../src/Storage/SqliteAnalysisRepository.php) 保存。不要手动修改计数器或状态。不要公开数据库；分享诊断结果前应去掉个人标识符。

## 模型返回错误或不合规输出

| 分析类别 | 检查内容 |
| --- | --- |
| `http_401`、`http_403` | 密钥及模型访问权限。 |
| `http_404` | 供应商文档中的 API 基础路径及模型标识符。 |
| `http_429`、`http_5xx` | 服务限额与可用性；这些错误可能重试。 |
| `timeout` | 超时设置、模型加载与速度。 |
| `invalid_response` | Chat Completions 外壳：字符串形式的 `choices[0].message.content`，不含工具调用。 |
| `invalid_output` | 只有一个包含必填字段及合法值的 JSON 对象；可为裸对象或**一个**标记 `json` 的 Markdown 代码块，不能有其他文字。 |
| `response_size` | API 响应过大，上限 65,536 字节。 |
| `vacancy_size_or_encoding` | 职位文本的 UTF-8 有效性及 65,536 字节上限。 |
| `transport` | AI 服务器的连接、TLS 和网络访问。 |

`http_5xx` 指某类具体状态，例如 `http_503`。能否重试取决于错误原因，而不只是剩余次数。详见[结果结构和字符串限制](analysis-result.md)及[模型配置](ai-models.md)。

## 数据库中有评估但聊天中没有

确认机器人未被屏蔽且聊天可用。保存结果的发送有独立重试次数，不会再次调用模型。若 `delivery_status = failed`，结果仍在 SQLite 中，但不会额外发送失败通知。没有手动重新发送命令。

开始分析通知只尝试一次，没收到不代表任务丢失。如果发送完成后、保存确认前发生崩溃，最终消息可能重复出现。详见[处理流程](message-processing.md)。

## SQLite 或 webhook 不可用

检查可用空间、数据库读写权限以及 `var/` 对 SQLite 日志文件的访问。服务器上 CLI 和 FPM 必须使用同一数据库，应分别检查两个运行账号的权限。SQLite 等待锁的上限是 5 秒。

存储未准备好或出错时 webhook 返回 503，密钥错误返回 403。检查私人 INI 中的 `TELEGRAM_WEBHOOK_SECRET`，并用 `php bin/bot.php webhook info` 查看注册状态。若密钥已改，用自己的 HTTPS 地址重新执行 `webhook set`。HTTP 状态、数据库准备和权限见[服务器指南](vds-webhook.md)。

不要为了“干净启动”而删除 `var/`、数据库或本地配置。更新时应保留私人文件与队列状态。

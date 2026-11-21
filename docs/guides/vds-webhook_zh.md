# 服务器：webhook 与 cron

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](vds-webhook.md) | [English](vds-webhook_en.md) | [Español](vds-webhook_es.md) | **已选择** | [Français](vds-webhook_fr.md) | [Deutsch](vds-webhook_de.md) |

[← 返回 README](../readme/README_zh.md)

服务器通过 HTTPS 接收职位信息，保存到 SQLite，再按计划处理。你可以向机器人发送职位文本，也可以从其他聊天转发。

[`public/webhook.php`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/public/webhook.php) 保存收到的消息；`php bin/bot.php work-once` 负责 AI 评估和发送结果。对于一个机器人令牌，只能选择此模式或本地 polling，不能同时使用。

## 准备运行环境

将仓库文件放入服务器上的固定目录，且必须位于网站公开目录**之外**。

需要用于 CLI 和 PHP-FPM 的 PHP `^8.5`、Composer、有效证书的 HTTPS，以及扩展 `curl`、`json`、`mbstring`、`PDO`、`pdo_sqlite`。CLI 与 FPM 的配置可能不同，请分别检查扩展。要求见 [`composer.json`](../../composer.json)。

在服务器安装目录的根目录执行：

```bash
# 按项目的 composer.lock 安装依赖。
composer install
```

使用本项目的 `composer.lock`。Composer 用于准备安装，不参与 webhook 请求处理。

根据实际 HTTP 服务器和系统配置专用的 PHP-FPM 进程池：

- DocumentRoot 必须**仅**指向 `public/`；唯一公开的 PHP 入口是 `/webhook.php`。
- 禁止列出目录内容、执行其他 PHP 路径，以及通过别名或备用路由访问项目根目录。
- 不能通过 HTTP 公开 `config/`、`vendor/`、`var/`、`.git/` 和源代码。
- 请求体上限设为 256 KiB；应用本身也会检查该限制。
- FPM 应禁用 `display_errors`。HTTP 日志不得记录请求体、令牌、秘密请求头或职位文本。

具体指令取决于 HTTP 服务器。在注册 webhook 之前，须从外网验证 HTTPS 正常且私人路径不可访问。

## 创建配置和档案

若 `config/bot.ini` 不存在，请复制[示例](../../config/bot.example.ini)。在编辑器中修改，不要把令牌写入终端命令。

1. 通过 [BotFather](https://t.me/BotFather) 创建机器人，将令牌写入 `TELEGRAM_BOT_TOKEN`。
2. 当使用 `polling` 且尚未注册 webhook 时，直接发送 `/start` 并执行 `php bin/bot.php identify`。若 `var/bot.lock` 被占用，请先停止其他 polling 进程。
3. 将自己账号的 ID 写入 `TELEGRAM_ALLOWED_USER_ID`。
4. 依照[档案指南](../../config/profile/README_zh.md)准备 `config/profile/local/candidate.md` 与 `config/profile/local/instructions.md`。
5. 设置 `AI_PROVIDER_LOCATION`、`AI_BASE_URL`、`AI_MODEL`，按需设置 `AI_API_KEY`。[模型指南](ai-models_zh.md)介绍连接和数据传输。

若模型运行在同一 VDS，`http://127.0.0.1:1234/v1` 是合法的 `local` 地址。若运行在其他电脑，应使用 VDS 可以访问的私有 IPv4。云模式必须使用 HTTPS 与 API 密钥。

确定用户 ID 后，调整设置：

```ini
; 启用 webhook，并将空的密钥替换为生成的随机值。
TELEGRAM_RECEIVE_MODE = "webhook"
TELEGRAM_WEBHOOK_SECRET = ""
```

用长度为 1–256 的随机字符串代替空密钥，允许字符 `A–Z`、`a–z`、`0–9`、`_`、`-`。例如，使用 PHP 生成 64 个字符：

```bash
# 生成密钥，命令本身不会将其写入终端历史记录。
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

将输出放入私人 `config/bot.ini` 的 `TELEGRAM_WEBHOOK_SECRET`。不要在日志或截图中公开。`webhook set` 会作为 `secret_token` 将其交给 Telegram，**不要**把它拼入 URL。

完整参数见[配置参考](../reference/configuration_zh.md)。

## 设置权限并准备 SQLite

CLI 与 FPM 必须使用**同一安装目录绝对路径**及同一个 `var/vacancies.sqlite` 数据库。建议为 cron 与 PHP-FPM 进程池使用专用的无特权应用账号。

该账号需要读取代码、`vendor/`、INI 和档案，并能写入数据库与 `var/`：SQLite 会在数据库旁创建日志，CLI 会创建 `bot.lock`。不要向其他用户开放配置与数据。若 CLI 和 FPM 使用不同账号，需配置精确的 ACL，并为新文件设置继承 ACL。CLI/FPM 使用 `umask(0077)`，仅依靠共同用户组不够。

分别以**两个**账号检查权限。不要对整个项目执行宽泛的递归 `chown` 或 `chmod`。

注册 webhook 前，以 cron 用户运行：

```bash
# 创建数据库结构，也可能处理待处理任务；不是 dry-run。
php bin/bot.php work-once
```

确保使用正确的 PHP CLI 版本。该命令会验证配置和档案，必要时创建 `var/` 和数据库结构，**同时处理已经排队的任务**。创建数据库后，webhook 才能接收消息。

升级前，先停止相关处理进程，再私下备份 INI、档案和 SQLite。不要把已有数据库换成空数据库，也不要为了测试而清空队列。

## 注册 webhook

确保 Telegram 能通过 HTTPS 访问 `/webhook.php`。缺少秘密请求头时返回 HTTP 403，GET 返回 HTTP 405。注册前停止这个机器人的所有 `run`、`identify`：polling 与 webhook 不可同时运行。

源码安装应使用自带的 `php bin/bot.php webhook` 命令。它们从私人 `config/bot.ini` 读取令牌与（必要时）密钥，不需要在终端输入机密值。

| 命令 | 作用 |
| --- | --- |
| `php bin/bot.php webhook set <https-url>` | 按指定 HTTPS 地址注册；需要 `webhook` 模式与合法的 INI 密钥。 |
| `php bin/bot.php webhook info` | 查看注册状态、待处理更新数与最近一次发送错误。 |
| `php bin/bot.php webhook delete` | 注销但保留待处理更新。 |

实现调用官方 [setWebhook](https://core.telegram.org/bots/api#setwebhook)、[getWebhookInfo](https://core.telegram.org/bots/api#getwebhookinfo)、[deleteWebhook](https://core.telegram.org/bots/api#deletewebhook)。注册时使用 `allowed_updates=["message"]`、`drop_pending_updates=false`、`max_connections=1`；注销时也使用 `drop_pending_updates=false`。

### 管理 webhook

设置 `TELEGRAM_RECEIVE_MODE = "webhook"` 和非空 `TELEGRAM_WEBHOOK_SECRET` 后，从服务器安装目录根目录执行（把示例域名换成**自己的公开 HTTPS 域名**）：

```bash
# 注册自己的 HTTPS 地址并查看注册状态。
php bin/bot.php webhook set 'https://bot.example.org/webhook.php'
php bin/bot.php webhook info
```

`set` 的地址必须有公开 DNS 域名，使用 HTTPS 的 443、80、88 或 8443 端口；不允许 URL 凭据、query 或 fragment。成功时 `set` 输出 `Webhook зарегистрирован.`。`info` 会报告注册状态、待处理数和错误信息，**不会打印已注册 URL，也不会将其与输入地址比较**。

核实后可启用 cron。注销时使用：

```bash
# 取消注册，保留待处理更新，然后核实状态。
php bin/bot.php webhook delete
php bin/bot.php webhook info
```

`delete` 应输出 `Webhook удалён. Ожидающие обновления сохранены.`，随后 `info` 应显示 `Webhook не зарегистрирован.`。`info` 和 `delete` 只需合法的机器人令牌，因此即使选择 `polling` 也可执行；不需要 SQLite、档案或 AI 配置。不要自行编写临时 PHP 脚本直接调用 Bot API。

## 通过 cron 定时处理

以指定账号每分钟运行一次。将**两个**绝对路径换成自己的：

```cron
# 每分钟运行一次；请替换两个绝对路径。
* * * * * /absolute/path/to/php8.5 /absolute/path/to/project/bin/bot.php work-once
```

cron 的工作目录对应用没有影响：[CLI 入口](../../bin/bot.php)根据自身位置解析路径。cron 输出只能写入私人日志或管理员邮箱，不能进入公开目录。

命令会取得共用锁 `var/bot.lock`，恢复中断的尝试，最多处理 10 批。20 秒后不再开始新批次，但已开始的请求可持续到 `AI_REQUEST_TIMEOUT_SECONDS`（30–1800 秒）。如果已有工作进程，下次 cron 运行会跳过，而不是并行评估。详见 [`BotRunner::workOnce()`](../../src/Application/BotRunner.php)。

向机器人发送一条虚构的简短职位信息。先应收到 `Вакансия сохранена.`，随后在 cron 运行后收到开始分析通知和结果。网络故障可能导致保存确认或开始通知未送达，但持久化的消息并不会因此消失。

## 接收与发送错误

[`WebhookReceiver`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/src/Application/WebhookReceiver.php) 会在解析 JSON 或打开 SQLite **之前**验证 `X-Telegram-Bot-Api-Secret-Token`。更新、消息与任务在一个事务中保存，数据写入 SQLite 成功后才返回 HTTP 204。重复的 `update_id` 不会创建新任务；更新可能乱序到达。

| HTTP 状态 | 应执行的操作 |
| --- | --- |
| 400 | 检查 JSON 格式和请求必填字段。 |
| 403 | 核实 INI 与 webhook 注册中的密钥，确保请求头传到 FPM。 |
| 405 | 使用 POST；GET 只能用于测试拒绝行为。 |
| 413 | 检查请求体不超过 256 KiB。 |
| 415 | 使用 `Content-Type: application/json`。 |
| 503 | 检查模式、配置、Composer 自动加载器、现有数据库及 SQLite 权限。 |

SQLite 最多等待锁 5 秒。写入失败返回 503，Telegram 因而可以重试；但重试次数有限，应及时排除故障。只有 SQLite 成功保存后才确认。重复更新不会创建第二个任务。详见[重复消息处理](message-processing_zh.md)。

每条职位的开始分析通知只尝试发送一次。完成的评估在发送之前保存，发送失败时不会重新计算。`AI_MAX_ATTEMPTS` 分别限制分析和发送。最终失败的任务不会被自动重新创建。详见[结果格式和状态](../reference/analysis-result_zh.md)。

## 切换模式

**从本地应用切换到服务器：**

1. 停止监控和正在执行的 `identify`，等待已启动的处理完成。
2. 在所有进程停止后，把 `config/bot.ini`、档案及**整个 `var/`**（包括原有 SQLite 数据库）搬到服务器。不要用同一令牌运行两份数据不一致的实例。
3. 配好 PHP-FPM、HTTPS、权限和 cron 计划，但**此时不要启用 cron，也不要注册 webhook**。确认 CLI/FPM 使用同一数据库。
4. 设定 `TELEGRAM_RECEIVE_MODE = "webhook"` 和密钥，以 cron 用户运行 `php bin/bot.php work-once` 准备数据库。**这不是诊断 dry-run：**若有等待任务，它可能调用 AI 并发送消息。如果暂时不希望处理队列，就不要只为检查配置而运行 `work-once`。
5. 执行 `php bin/bot.php webhook set 'https://bot.example.org/webhook.php'` 注册自己的 HTTPS 地址（必须替换示例地址），用 `webhook info` 检查，然后启用 cron。

切换期间只能使用一份最新的数据副本。移动数据库无法恢复 Telegram 已确认或已删除的更新。

**从服务器切换回本地：**

1. 禁用 cron，等待当前工作进程结束。
2. 执行 `php bin/bot.php webhook delete`（保留待处理更新），再用 `php bin/bot.php webhook info` 确认显示 `Webhook не зарегистрирован.`。
3. 等待已开始的 PHP-FPM 请求完成，并禁用公开的 webhook 处理器，使其不再写入数据库。
4. 所有处理器停止后，保留同一数据库和私人文件；迁移时复制整个 `var/`。
5. 将 `TELEGRAM_RECEIVE_MODE = "polling"`，然后运行本地应用或源码中的 `php bin/bot.php run`。

不要手动清除游标、已接收更新表或消息。长时间停机后，polling 会在不删除数据的情况下重设不可信的外发 `offset`。选择 `webhook` 时不能运行 `run` 或 `identify`；仅修改 INI 不会注册或删除 webhook，必须使用 `webhook set` 和 `webhook delete`。

有问题时参考[本地提示](local-gum_zh.md#出现问题时)和[完整故障排除指南](troubleshooting_zh.md)。

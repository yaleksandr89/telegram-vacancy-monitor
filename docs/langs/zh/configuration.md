# 配置

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/configuration.md) | [English](../en/configuration.md) | [Español](../es/configuration.md) | **已选择** | [Français](../fr/configuration.md) | [Deutsch](../de/configuration.md) |

[← 返回 README](README.md)

私有配置文件位于相对于应用根目录的 `config/bot.ini`。请依据 [`config/bot.example.ini`](../../../config/bot.example.ini) 创建文件，不要把密钥写进公开示例。所有参数都在此 INI 文件中设置。

文件由内置 INI 解析器读取，不会把字符串自动转换成布尔值。保留引号，数值参数使用十进制整数，重复列表项使用 `[]`。修改后请重启本地监控；新的 `work-once` 执行及每次新 Webhook 请求都会读取当前文件。

## 模式与命令

命令定义于 [`bin/bot.php`](../../../bin/bot.php)。没有参数时默认执行 `run`。

| 命令 | 模式 | 要求 |
| --- | --- | --- |
| `php bin/bot.php identify` | `polling` | 机器人令牌和可用的 `var/bot.lock`；无需档案、用户 ID 和 AI 设置。 |
| `php bin/bot.php run` | `polling` | 机器人令牌、用户 Telegram ID、档案、指令、AI 设置和存储访问权限。 |
| `php bin/bot.php work-once` | `webhook` | 机器人令牌、用户 Telegram ID、Webhook 密钥、档案、指令、AI 设置和存储访问权限。 |
| `php bin/bot.php webhook set <https-url>` | 管理 Webhook | 令牌、`TELEGRAM_RECEIVE_MODE=webhook`、有效的 `TELEGRAM_WEBHOOK_SECRET` 及公开 HTTPS 地址。 |
| `php bin/bot.php webhook info` | 管理 Webhook | 只需要令牌；无需用户 ID、档案、AI 设置或已选接收模式。 |
| `php bin/bot.php webhook delete` | 管理 Webhook | 只需要令牌；无需用户 ID、档案、AI 设置或已选接收模式。 |
| 通过 FPM 访问 `public/webhook.php` | `webhook` | 令牌、用户 Telegram ID、Webhook 密钥及已经准备好的数据库；HTTP 请求不加载 AI 或档案。 |

`webhook set` 接受具有公开 DNS 名称且端口为 443、80、88 或 8443 的 HTTPS URL。带有用户名密码、query 或 fragment 的 URL 会被拒绝。`webhook info` 显示注册状态、待处理更新数量和最近错误，**但不输出已注册的 URL**。`webhook delete` 保留待处理更新（`drop_pending_updates=false`）。这些命令不会打开 SQLite 或更改 `config/bot.ini`；仅切换接收模式不会注册 Webhook。

本地归档中，`identify` 和 `run` 由菜单使用内置 PHP 执行，不需要系统安装 PHP。`work-once` 和 Webhook 管理适用于从源码安装的服务器。`identify` 在调用 Bot API 前取得非阻塞锁；首次检查时可能只创建私有 `var/` 目录和 `bot.lock`。

## Telegram

| 键 | 作用与允许值 | 必填与默认值 |
| --- | --- | --- |
| `TELEGRAM_BOT_TOKEN` | BotFather 颁发的令牌，格式为“数字:字母/数字/`_`/`-`”。符合格式并不意味着令牌实际有效。 | 所有机器人调用都必须填写；示例中为空。 |
| `TELEGRAM_ALLOWED_USER_ID` | 允许发送职位、接收结果的 Telegram 账户数字 ID。在发送 `/start` 后通过 `identify` 或菜单获得。 | `run`、`work-once` 和接收 Webhook 必填；`identify` 和 Webhook 管理无需填写；示例中为空。 |
| `TELEGRAM_RECEIVE_MODE` | `polling` 用于本地 `run`/`identify`；`webhook` 用于 FPM、`work-once` 及 `webhook set`。 | 可选，缺失时为 `polling`。 |
| `TELEGRAM_WEBHOOK_SECRET` | 服务器认证 Webhook 请求的秘密令牌，长度 1–256，只能含 `A–Z`、`a–z`、`0–9`、`_`、`-`。与 `webhook set` 中的 `secret_token` 相同。 | 接收 Webhook、`work-once` 和 `webhook set` 必填；`webhook info/delete` 不需要；示例中为空。 |

设置 `TELEGRAM_RECEIVE_MODE` 本身不会注册或删除 Webhook；使用独立的 `webhook set/info/delete` 命令。HTTP 验证由 [`WebhookReceiver`](../../../src/Application/WebhookReceiver.php) 完成，配置由 [`public/webhook.php`](../../../public/webhook.php) 加载。切换流程见[服务器指南](vds-webhook.md#切换模式)。

## 连接 AI

模型连接参数写在 `config/bot.ini` 中，由 [`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) 验证。

| 键 | 作用与允许值 | 必填与默认值 |
| --- | --- | --- |
| `AI_PROVIDER_LOCATION` | `local` 或 `cloud`，决定 URL 允许范围和是否必须提供密钥。 | 可选，默认 `local`。 |
| `AI_BASE_URL` | 不包含 `/chat/completions` 的基础 URL。HTTP/HTTPS；不能包含用户名、密码、查询参数、片段、空格或控制字符；最长 2048 字节。 | 必填，不自动替换 URL；示例为 `http://127.0.0.1:1234/v1`。 |
| `AI_MODEL` | 非空 API 模型标识符，UTF-8，最多 200 字节，不包含 ASCII 控制字符。 | 必填；示例中为空。 |
| `AI_API_KEY` | 用于 `Authorization: Bearer` 的密钥，UTF-8，最多 4096 字节，不包含 ASCII 控制字符。 | `local` 下如果服务器不要求则可省略；`cloud` 下必须提供非空密钥；示例中为空。 |
| `AI_MAX_ATTEMPTS` | 整数 1–5：包括第一次在内的总尝试次数；分析和投递分别计数。 | 可选，默认 `3`。 |
| `AI_REQUEST_TIMEOUT_SECONDS` | 整数 30–1800：单次 AI 请求的秒数上限。 | 可选，默认 `180`；空值不会被默认值替换。 |

在 `local` 模式下，允许 `localhost`、`[::1]`、IPv4 网段 `127.0.0.0/8`、`10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16`。局域网主机名和其他 IPv6 地址不被接受。`cloud` 模式必须使用 HTTPS 和非空密钥。

连接超时固定为 5 秒，不能单独配置。AI 请求禁止 HTTP 重定向和代理，并验证 TLS。限制由 [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php) 实施。临时错误会延迟后重试，永久错误则直接结束而不消耗剩余尝试次数。详情见[模型连接指南](ai-models.md#超时与重试)。

## 预过滤器

预过滤器可在不调用 AI 的情况下跳过预定义短消息。两个列表默认都是空的。规则由 [`ConservativePrefilter`](../../../src/Analysis/ConservativePrefilter.php) 执行。

| 键 | 用途 |
| --- | --- |
| `AI_PREFILTER_EXACT_REJECT_LINES[]` | 可以直接拒绝的整条消息的精确文本。 |
| `AI_PREFILTER_AMBIGUITY_TERMS[]` | 出现时**取消精确拒绝**的词或短语。 |

### 单条短语

```ini
; 只有整条消息精确匹配时才拒绝。
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
```

如果消息只有 `Только ночная смена.`，去掉两端空格后会跳过分析。`Только ночная смена. Есть варианты?` 不再与整条文本匹配，因此会交给模型。

### 多条短语及例外

每条短语必须占**独立一行**。以下示例包含三条精确规则和两个消除歧义的词：

```ini
; 精确拒绝规则及取消拒绝的模糊性词语。
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

整条消息与任一短语完全匹配时会被拒绝。如果包含任一消除歧义的词，拒绝就会取消，文本交给模型处理。精确短语匹配区分大小写，消除歧义词的检测不区分大小写。

列表中的每项是**一个非空字符串**，而非逗号分隔的多条规则。多行职位公告不会被拒绝：比较的是整条消息，而不是单个句子。不使用正则表达式。

## 档案与存储

以下路径没有对应的 INI 键，均由 [CLI 入口](../../../bin/bot.php)定义。

| 路径 | 用途 |
| --- | --- |
| `config/profile/local/candidate.md` | 候选人档案，处理时必需。 |
| `config/profile/local/instructions.md` | 模型指令，处理时必需。 |
| `var/vacancies.sqlite` | 消息、结果和队列。 |
| `var/bot.lock` | `run`、`identify`、cron `work-once` 共用的锁；Webhook 请求使用 SQLite 事务。 |

Markdown 文件限制见[档案指南](profile.md)。配置、档案及 `var/` 都包含私有数据，已从 Git 中排除，更新时应保留。

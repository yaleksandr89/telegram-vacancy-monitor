# 应用如何接收和处理职位

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](message-processing.md) | [English](message-processing_en.md) | [Español](message-processing_es.md) | **已选择** | [Français](message-processing_fr.md) | [Deutsch](message-processing_de.md) |

[← 返回 README](../readme/README_zh.md)

向机器人发送或转发职位时，Telegram 会向应用传递一条更新。应用保存文本并创建评估任务。分析完成后，结果发送到同一个聊天窗口。

## 保存的内容

SQLite 会为每条职位保存文本、消息标识符，以及在可用时保存转发原始发布内容的信息。评估状态和发送状态分别存储。

在[数据库结构](../../src/Storage/SqliteSchema.php)中，这些数据对应 `messages` 和 `analysis_jobs` 表。

## 为什么需要游标

本地运行时，应用通过 `getUpdates` 接收更新。每条更新都有数字 `update_id`。**游标**保存最后一条可靠处理并提交的更新之后的下一个位置。每次请求前，应用都会检查这个位置是否仍可信。

例如：

1. 应用收到包含新职位的更新 `104`。
2. 将职位写入 SQLite，创建任务，并保存下一游标 `105`。
3. 下一次向 Telegram 请求时使用 `offset = 105`，不再需要重复获取 `104`。

保存职位和推进游标是一个原子操作。如果写入失败，游标保持不变，Telegram 可以再次投递该更新。如果更新已经保存，应用不会创建第二份数据。

### 游标字段的含义

`cursor` 表中有一条供本地接收消息使用的记录：

| 列 | 示例 | 含义 |
| --- | --- | --- |
| `name` | `main` | 固定游标名称，不是机器人或聊天名称。 |
| `next_offset` | `105` | 最后一个已保存 `update_id` 之后的位置；使用前会检查是否仍然有效。 |
| `last_handled_at` | `1795000000` | 与游标一同提交处理结果时的 Unix 时间戳。旧数据库迁移后可能为 `NULL`。 |

**`next_offset` 根据 `update_id` 计算，而不是消息编号。** 处理更新 `104` 后保存 `105`。在有效更新序列内，游标不会减小；空的 `getUpdates` 响应不会更新 `last_handled_at`。时间戳、游标、消息和任务（如有）在同一个事务中提交。

### 长时间未运行后的恢复

如果至少一周没有新更新，Telegram 可能会为新的更新分配更小的 `update_id`。这时发送旧的较大 `offset` 可能让 Telegram Bot API 确认尚未处理的低 ID 更新。

因此，在**每一次**发出 `getUpdates` 请求前，如果 `last_handled_at` 缺失、无效、指向未来或早于五天前，应用就会发送 `offset = 0`。旧游标不会被提前清除。第一条成功提交的更新会形成新位置，即使其 ID 小于旧位置。SQLite 唯一性约束仍会防止重复职位和任务。

如果新响应包含低于请求 `offset` 的 ID，或更新顺序不正确，应用会停止接收且不推进游标。SQLite 写入失败时，游标、时间戳、消息和任务会一同回滚。此机制无法恢复 Telegram 已确认或超过保留时间（最多 24 小时）而删除的更新。

不同 ID 的用途不同：

| 字段 | 含义 |
| --- | --- |
| Telegram 更新中的 `update_id` | 用于计算 `next_offset` 的事件编号。 |
| `messages` 表中的 `recipient_message_id` | 机器人聊天内的消息编号，不用于计算游标。 |
| `messages` 表中的 `origin_message_id` | 如果 Telegram 在转发时提供，表示原频道发布内容的消息编号，不用于计算游标。 |

例如，更新 `104` 可能包含机器人聊天中的消息 `312` 和频道中的原始发布 `87`。游标变为 `105`，`312` 和 `87` 不影响游标。

游标既不是职位编号，也不是来源编号。它仅用于通过 `getUpdates` 顺序接收更新。参见 [`SqliteInboxRepository::pollingOffset()` 和 `applyUpdate()`](../../src/Storage/SqliteInboxRepository.php)。

## 使用 Webhook 时会怎样？

Webhook 模式下，Telegram 向服务器发送请求。这些请求可能重复或乱序到达。应用不使用 polling 游标，而是把每条 `update_id` 记录到 `received_updates`，并检查是否以前接收过。

例如，带有 `update_id = 205` 的请求成功写入，Telegram 收到 HTTP 204。相同请求再次到达时，不会创建新任务。如果数据库写入失败，服务器返回错误，Telegram 可以重试投递。

机制由 [`SqliteInboxRepository::applyWebhookUpdate()`](../../src/Storage/SqliteInboxRepository.php) 和 [`WebhookReceiver`](../../src/Application/WebhookReceiver.php) 实现。

## 哪些职位被视为重复？

应用使用两类标识符：

- **机器人聊天中的消息。** 同一个消息 ID 被重复投递时，不会创建新职位。
- **转发内容的原始发布。** 如果 Telegram 提供原频道和发布标识符，再次转发同一发布也不会重新评估，即使新的机器人聊天消息具有不同 ID。

例如，今天从频道转发一个职位，明天再次转发。如果原始发布标识符可用，应用回复 `Эта публикация уже сохранена.`（“此发布已保存”）。**仅凭文本相同不会判定为重复：** 不同公告可能包含完全相同的文本。

规则位于 [`IncomingUpdateProcessor`](../../src/Application/IncomingUpdateProcessor.php)，消息唯一键位于 [`SqliteSchema`](../../src/Storage/SqliteSchema.php)。

## 保存之后的处理

1. 机器人回复 `Вакансия сохранена.`（“职位已保存”）。
2. 应用结合档案和指令分析保存的文本。服务器模式下，由 cron 启动的工作进程执行分析。
3. 完成的评估保存到 SQLite，然后发送到 Telegram。
4. 如果发送暂时失败，应用会**重试发送已经保存的结果**，而不是重新分析。如果 Bot API 返回 HTTP 429，当有效的 `parameters.retry_after` 大于本地延迟时，以其为准。
5. 如果进程中断，未完成的工作会在下次启动时根据剩余尝试次数继续处理。

网络故障可能导致“分析开始”通知没有送达，但评估仍会继续。尝试次数全部用尽后，不会自动重新发起分析。极少数情况下，Telegram 已收到最终消息，但确认没有到达应用，最终通知可能重复发送。

[`SqliteAnalysisRepository`](../../src/Storage/SqliteAnalysisRepository.php) 保存分析和投递状态；[`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) 执行处理。参阅[评估和通知格式](../reference/analysis-result_zh.md)。

# 评估结果与通知格式

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/analysis-result.md) | [English](../en/analysis-result.md) | [Español](../es/analysis-result.md) | **已选择** | [Français](../fr/analysis-result.md) | [Deutsch](../de/analysis-result.md) |

[← 返回 README](README.md)

应用从模型接收结构化评估，将其保存，再为 Telegram 生成单独的文本通知。模型 JSON 与发送给用户的消息格式不同。

## API 响应与模型 JSON

[`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) 接受非流式的 Chat Completions 响应：`choices` 的第一个元素中，`message.content` 必须为字符串。字符串必须恰好包含一个 JSON 对象。非空 `tool_calls` 或任何非 null 的 `function_call` 都会被拒绝；应用不会执行工具调用。

[`EvaluationPromptBuilder::build()`](../../../src/Analysis/EvaluationPromptBuilder.php) 将 schema 与你的指令一起放入系统消息。候选人档案和不可信的职位文本放在用户消息中，不会另行传输聊天元数据。

下表中的所有字段都是必填的，不允许额外字段。结构由 [`AnalysisResult::jsonSchema()`](../../../src/Analysis/AnalysisResult.php) 定义，并由 [`AnalysisResultValidator::validate()`](../../../src/Analysis/AnalysisResultValidator.php) 执行验证。

| 字段 | 类型与允许值 | 含义 |
| --- | --- | --- |
| `company` | 非空字符串 | 公司名称，或说明未提供公司名称。 |
| `position` | 非空字符串 | 职位名称。 |
| `verdict` | `apply`、`borderline`、`skip` | 是否值得申请。 |
| `priority` | `high`、`medium`、`low` | 处理优先级。 |
| `matches` | 非空字符串数组；数组本身可以为空 | 职位要求与档案匹配的部分。 |
| `risks` | 非空字符串数组；数组本身可以为空 | 风险和重要不匹配点。 |
| `geography` | 非空字符串 | 地点与工作方式。 |
| `pay` | 非空字符串 | 薪酬或说明没有薪酬信息。 |
| `missing` | 非空字符串数组；数组本身可以为空 | 待确认问题与缺失信息。 |
| `rationale` | 非空字符串 | 简短理由。 |
| `source_status` | 模型只能返回 `unavailable` | 模型不能验证职位发布的来源。 |

所有字符串必须是有效 UTF-8，且包含可见文本。验证器拒绝控制字符、不可见格式字符、行分隔符、HTTP/HTTPS 链接及类似 HTML 标签的结构。字符串内部也不能包含换行；多个要点应分别放入数组项。唯一允许的外层包装是**一个**标记为 `json` 的 Markdown 代码块，适配器会在严格验证对象前剥离它。其他文字、额外代码块或格式都将被拒绝。

下面是 `message.content` 中的**虚构 JSON 对象模板**。为保持演示场景一致，字段值沿用俄语示例：

```json
{
  "company": "Компания из объявления",
  "position": "Должность из объявления",
  "verdict": "borderline",
  "priority": "medium",
  "matches": ["Подтверждённое совпадение"],
  "risks": [],
  "geography": "Формат и география из объявления",
  "pay": "Не указана",
  "missing": ["Условие, которое нужно уточнить"],
  "rationale": "Краткое объяснение на основе профиля и вакансии",
  "source_status": "unavailable"
}
```

在 HTTP 响应中，这个对象必须作为**字符串**编码到 `choices[0].message.content`，不能作为对象直接嵌套。完整 API 响应最多 65,536 字节。响应外壳或 JSON 无效时，可在 `AI_MAX_ATTEMPTS` 范围内重试。

## 结论和优先级

值由 [`Verdict`](../../../src/Analysis/Verdict.php) 和 [`Priority`](../../../src/Analysis/Priority.php) 定义；[`VacancyResultFormatter::format()`](../../../src/Application/VacancyResultFormatter.php) 生成俄语文本标签。

| 值 | Telegram 文本（俄语） | 含义 |
| --- | --- | --- |
| `apply` | Откликаться | 值得考虑申请。 |
| `borderline` | Погранично | 存在疑虑或重要条件需要确认。 |
| `skip` | Пропустить | 根据所设标准不值得考虑。 |
| `high` | высокий | 高优先级。 |
| `medium` | средний | 中优先级。 |
| `low` | низкий | 低优先级。 |

应用检查枚举值是否合法，但不设定数值阈值，也不要求某种固定的结论和优先级组合。实际评估标准由你在 `instructions.md` 中定义；**由模型作出判断**。结论不会自动向雇主发送求职申请。

## Telegram 通知

[格式化器](../../../src/Application/VacancyResultFormatter.php)生成普通文本消息。下列是带占位符的布局示意，**不是实际评估结果**。运行时消息使用俄语，因此标签保留俄文：

```text
Откликаться / Погранично / Пропустить
Приоритет: высокий / средний / низкий
{компания} — {должность}
{краткое обоснование}
География и формат: {география и формат}
Оплата: {оплата}
Совпадения: {пункты через точку с запятой}
Риски: {пункты через точку с запятой}
Уточнить: {пункты через точку с запятой}
Источник: {ссылка с пометкой или «ссылка недоступна»}
Сообщение в чате: {ID входящего сообщения}
```

如果 `matches`、`risks` 或 `missing` 数组为空，对应行将被省略。最后一行的 ID 表示你发给机器人的聊天消息，而非原始频道发布的编号。

如果完整消息超过 4,096 个 UTF-16 代码单元，会使用 `(сокращено для Telegram)` 标记截短。公司、职位和理由具有单独标签；结论、优先级和来源会保留。表情符号不会被截断到半个字符。完整评估仍保存在 SQLite 中。

## 来源及其可靠性

链接来自职位文本，而不是模型回复。应用不会打开网站或下载页面。

- 没有符合条件的链接时，显示 `Источник: ссылка недоступна`（“来源：链接不可用”）。
- 找到 HTTP/HTTPS 链接时，显示 `ссылка из объявления (не проверена)`（“来自公告的链接，未验证”）。
- 如果已有 `https://t.me/c/…/…` 格式的链接，且其中数字 ID 与转发的频道发布元数据一致，则显示 `совпадает с ID пересланной публикации`（“与转发发布的 ID 一致”）。

精确选择与验证逻辑位于 [`sourceDescription()` 和 `verifiedSource()`](../../../src/Application/VacancyResultFormatter.php)。ID 一致只能证明链接与转发内容关联，**不能证明**职位真实或雇主可信。应用不会仅凭元数据拼接 URL。

模型响应中的 `source_status` 必须始终为 `unavailable`。保存 [`AnalysisResult::toJson()`](../../../src/Analysis/AnalysisResult.php) 时，如果确实验证了上述链接和 ID 的对应关系，应用会自行设置 `verified_origin_ids`；否则保存 `unavailable`。模型无权自行声明来源已验证。

## 处理进度消息

[`IncomingUpdateProcessor`](../../../src/Application/IncomingUpdateProcessor.php) 和 [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php) 分别负责接收和处理。

| 事件 | 消息或状态 |
| --- | --- |
| 新职位已保存 | `Вакансия сохранена.` |
| 再次发送已知发布内容 | `Эта публикация уже сохранена.` |
| AI 请求开始 | `Вакансия передана на анализ ИИ. Результат придёт сюда, как только анализ завершится.` |
| 预过滤器精确拒绝 | `Пропустить` 及精确匹配原因；不执行 AI 评估，也不发送开始通知。 |
| 分析最终失败 | `Не удалось обработать` 和 `Техническая ошибка анализа. Исходная вакансия сохранена.`，随后附来源与消息 ID。 |
| 发送最终失败 | SQLite 中 `delivery_status = failed`；不会额外发送失败通知。 |

## 处理步骤

1. **保存。** 应用把职位和任务写入 SQLite。成功后机器人回复 `Вакансия сохранена.`。相同更新重复投递不会创建新职位。
2. **开始分析。** 机器人只尝试发送一次“开始分析”通知。即使通知丢失，分析仍会继续。
3. **评估。** 模型结合档案和指令分析职位。完成的评估在发送到 Telegram **之前**先保存到 SQLite。
4. **发送。** 机器人发送已保存的结果。暂时性发送错误可能触发重试，**不会重新调用模型**。
5. **恢复。** 重启后，未完成的任务会考虑已使用的尝试次数继续执行。次数耗尽的任务不会自动重新启动。

如果发送时发生网络故障，Telegram 可能已收到消息，而应用未收到确认；最终结果便可能重复送达。游标、保存和重试机制见[职位处理指南](message-processing.md)。具体实现由 [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php) 完成。

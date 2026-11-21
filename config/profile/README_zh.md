# 候选人档案与评估指令

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](README.md) | [English](README_en.md) | [Español](README_es.md) | **已选择** | [Français](README_fr.md) | [Deutsch](README_de.md) |

[← 返回 README](../../docs/readme/README_zh.md)

个性化评估需要两个私有 Markdown 文件。处理开始时，应用会从相对于根目录的固定路径 `config/profile/local/` 加载它们。

| 文件 | 内容 |
| --- | --- |
| `config/profile/local/candidate.md` | 候选人的经验、技能、期望职责、工作方式和限制条件 |
| `config/profile/local/instructions.md` | 职位匹配、结论选择、优先级以及解释语言的规则 |

创建 `local/` 目录，使用文本编辑器编写 `candidate.md`，并把[指令示例](instructions.example.md)复制到 `local/instructions.md`。普通 Markdown 即可，没有强制要求的标题或字段。不要直接修改公开示例来代替私有副本。

## 应填写的内容

在档案中描述技能和经验、期望任务、工作方式、工作地点、薪资期望以及重要限制。可参考 [Go 开发者](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_zh.md#候选人档案)或[产品分析师](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_zh.md#候选人档案)示例。

在指令中明确哪些条件是硬性要求、何时评定为高优先级以及哪些事项需要进一步确认。[指令示例](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_zh.md#评估指令)演示了如何编写规则。模型的回答语言也在此处设置。随应用提供的指令示例要求用俄语解释；翻译本指南不会改变这一行为。

应用会把 JSON schema 附加到指令中，并分别传入档案和职位文本。请保留返回单个 JSON 对象、不得添加额外字段、链接或格式的要求。招聘信息应作为分析数据，而不是模型必须执行的指令。请求的构建方式见 [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php)，响应字段见[在线参考文档](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_zh.md)。

可复制的示例：[Go 开发者档案与指令](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_zh.md)和[产品分析师](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_zh.md)。

## 文件限制

- 两个文件都必须存在、非空且可读取；不允许使用符号链接。
- 编码必须是有效的 UTF-8。
- 每个文件的原始文本不得超过 32,768 字节（32 KiB）和 16,000 个字符，空格与换行也计算在内。
- 允许制表符和普通换行符；不允许其他控制字符。

检查由 [`CandidateProfileLoader`](../../src/Analysis/CandidateProfileLoader.php)、[`CandidateProfile`](../../src/Analysis/CandidateProfile.php)、[`EvaluationInstructionsLoader`](../../src/Analysis/EvaluationInstructionsLoader.php) 和 [`EvaluationInstructions`](../../src/Analysis/EvaluationInstructions.php) 执行。如果文件缺失或无效，CLI 会在开始接收和处理消息前停止。`identify` 命令不需要这些文件。在服务器模式下，即使档案尚未准备好，Webhook 仍可保存消息；但缺少档案时，`work-once` 无法处理队列。

## 重新加载更改

编辑完成后，停止并重新启动本地监控。在服务器上，下一次执行 `work-once` 时会读取新文件；已在运行的进程继续使用先前加载的数据。已保存的评估不会自动重新计算。

`config/profile/local/` 已从 Git 中排除。更新应用时，请连同 `config/bot.ini` 和 `var/` 中的数据一起保留档案文件。有关连接模型的方法，请参阅[指南](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_zh.md)。

模型地址、密钥、请求超时时间和尝试次数在 `config/bot.ini` 中设置，格式参见[配置示例](../bot.example.ini)。运行方法见[本地指南](../../docs/guides/local-gum_zh.md)和[服务器指南](../../docs/guides/vds-webhook_zh.md)。

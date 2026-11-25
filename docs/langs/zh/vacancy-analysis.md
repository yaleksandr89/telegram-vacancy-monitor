# 职位评估示例

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/vacancy-analysis.md) | [English](../en/vacancy-analysis.md) | [Español](../es/vacancy-analysis.md) | **已选择** | [Français](../fr/vacancy-analysis.md) | [Deutsch](../de/vacancy-analysis.md) |

[← 返回 README](README.md)

两个相互独立的示例展示如何编写候选人档案、评估指令以及职位文本。所有输入数据都是虚构的，评估结果取决于所选模型和配置的规则。

- [Go 开发者](go-developer.md) — 后端开发、经验要求、值班安排和薪酬条件.
- [产品分析师](analyst.md) — SQL、研究、实验和工作条件.

## 如何使用示例

1. 将所选档案保存为 `config/profile/local/candidate.md`，把指令保存为 `config/profile/local/instructions.md`。
2. [连接模型](ai-models.md)并启动监控。
3. 将对应示例中的职位文本发送给机器人。
4. 阅读返回的说明和待确认问题。评估结果不是预先设定的。

有关结果结构的详情，请参阅[参考文档](analysis-result.md)。

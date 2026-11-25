# Vacancy assessment examples

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/vacancy-analysis.md) | **Selected** | [Español](../es/vacancy-analysis.md) | [中文](../zh/vacancy-analysis.md) | [Français](../fr/vacancy-analysis.md) | [Deutsch](../de/vacancy-analysis.md) |

[← Back to README](README.md)

Two independent examples show how to define a candidate profile, evaluation instructions, and vacancy text. All input data is fictional; the result depends on the selected model and configured rules.

- [Go developer](go-developer.md) — server-side development, requirements concerning experience, on-call duties, and compensation.
- [Product analyst](analyst.md) — SQL, research, experiments, and working conditions.

## How to use the examples

1. Save the chosen profile as `config/profile/local/candidate.md` and the instructions as `config/profile/local/instructions.md`.
2. [Connect a model](ai-models.md) and start monitoring.
3. Send the vacancy text from the corresponding example to the bot.
4. Read the explanation and questions returned. The assessment is not predetermined.

For details of the result structure, see the [reference](analysis-result.md).

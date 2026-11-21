# Candidate profile and instructions

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](README.md) | **Selected** | [Español](README_es.md) | [中文](README_zh.md) | [Français](README_fr.md) | [Deutsch](README_de.md) |

[← Back to README](../../docs/readme/README_en.md)

Personalized evaluation requires two private Markdown files. They are loaded when processing starts from the fixed `config/profile/local/` directory relative to the application root.

| File | Contents |
| --- | --- |
| `config/profile/local/candidate.md` | Candidate's experience, skills, desired responsibilities, work format, and constraints |
| `config/profile/local/instructions.md` | Rules for comparing vacancies, choosing a verdict, setting priority, and specifying the explanation language |

Create the `local/` directory, write `candidate.md` in a text editor, and copy the [instructions example](instructions.example.md) to `local/instructions.md`. Ordinary Markdown is sufficient; no particular headings or fields are mandatory. Do not edit the public example instead of your private copy.

## What to write

Describe your skills and experience, desired responsibilities, work format, location, salary expectations, and important constraints. See the [Go developer](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_en.md#candidate-profile) or [analyst](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_en.md#candidate-profile) example.

In your instructions, specify which requirements are mandatory, when to assign high priority, and which conditions need clarification. The [instructions example](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_en.md#evaluation-instructions) illustrates how to organize the rules. This is also where the model's response language is configured. The distributed example instructions ask for Russian explanations; translating this guide does not change that behavior.

The application appends the JSON schema to the instructions and passes the profile and vacancy text separately. Keep the requirement to return a single JSON object without extra fields, links, or formatting. A vacancy must be treated as data for analysis, not as a source of instructions to the model. [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php) describes the request construction; the response fields are documented in the [online reference](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_en.md).

Ready-to-copy examples: [Go developer profile and instructions](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_en.md) and [product analyst](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_en.md).

## File constraints

- Both files are required, nonempty, and readable; symbolic links are not allowed.
- Encoding must be valid UTF-8.
- Each file is limited to 32,768 bytes (32 KiB) of original text and 16,000 characters, including spaces and line breaks.
- Tabs and ordinary line breaks are allowed; other control characters are forbidden.

Checks are performed by [`CandidateProfileLoader`](../../src/Analysis/CandidateProfileLoader.php), [`CandidateProfile`](../../src/Analysis/CandidateProfile.php), [`EvaluationInstructionsLoader`](../../src/Analysis/EvaluationInstructionsLoader.php), and [`EvaluationInstructions`](../../src/Analysis/EvaluationInstructions.php). If a file is missing or invalid, the CLI stops before receiving and processing messages. The `identify` command does not require these files. In server mode, the webhook can save messages even if the profile is not ready, but `work-once` cannot process the queue without it.

## Reloading changes

After editing the files, stop and restart local monitoring. On the server, the next `work-once` run loads the new files; an already running process keeps using the data it loaded. Saved assessments are not recalculated automatically.

The `config/profile/local/` directory is excluded from Git. When updating, preserve the profile files along with `config/bot.ini` and the contents of `var/`. Model setup is described in the [guide](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_en.md).

The model address, key, request timeout, and number of attempts are set in `config/bot.ini` according to the [configuration example](../bot.example.ini). Startup is covered by the [local](../../docs/guides/local-gum_en.md) and [server](../../docs/guides/vds-webhook_en.md) guides.

# Connecting an AI model

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/ai-models.md) | **Selected** | [Español](../es/ai-models.md) | [中文](../zh/ai-models.md) | [Français](../fr/ai-models.md) | [Deutsch](../de/ai-models.md) |

[← Back to README](README.md)

The application works with local and cloud models through the Chat Completions interface. Set the server address and model identifier in `config/bot.ini` using the [example](../../../config/bot.example.ini).

## API requirements

The server must accept a `POST` to `AI_BASE_URL` with `/chat/completions` appended, messages with `system` and `user` roles, and `stream = false`. The response must contain `choices[0].message.content` as a string with one JSON object matching the schema included by the application in the instructions.

One JSON object is accepted in `message.content`: either unwrapped or enclosed in exactly one Markdown code block labeled `json`. Arbitrary text before or after the object, additional code blocks, other Markdown formatting, and split responses are not accepted. Function and tool calls are also rejected. The schema is provided in the request itself; no separate `response_format` parameter is used.

The required format is documented in the [result reference](analysis-result.md). Implementation: [`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) and [`EvaluationPromptBuilder`](../../../src/Analysis/EvaluationPromptBuilder.php).

## Local model

Start an API server with the model loaded, then configure:

```ini
; Local model: replace model-id and the API address if needed.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

The address and `model-id` are placeholders. Obtain the identifier from your server; the model filename and its API name may differ. If the server needs a key, enter it in `AI_API_KEY`.

Both HTTP and HTTPS are allowed. In `local` mode, address validation permits:

| Address | When to use it |
| --- | --- |
| `localhost`, IPv4 `127.0.0.0/8`, IPv6 `[::1]` | Model server on the same machine. |
| `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` | Server reachable through a private IPv4 address on the network. |

Other hostnames, public IP addresses, and private IPv6 addresses are not allowed by this validation. For a model on another machine, use its private IPv4 address. In WSL, a virtual machine, or a VDS, `127.0.0.1` refers to the environment running PHP, not your desktop machine.

Restrict API access to the computers that need it. The application neither installs the model nor opens ports. Memory, hardware, and context-size requirements depend on the chosen model. The context must fit the profile, instructions, schema, and entire vacancy.

## Cloud provider

Create a key with your chosen provider, and obtain its base URL and model identifier from the provider's documentation. This is a placeholder address:

```ini
; Cloud API: replace the address and model ID, and provide a key.
AI_PROVIDER_LOCATION = "cloud"
AI_BASE_URL = "https://api.example.org/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

Replace the address and model and enter a nonempty key in a text editor. `cloud` requires HTTPS and a key. The URL must not contain a username, password, query parameters, or fragment. Do not append `/chat/completions` to `AI_BASE_URL`.

The cloud service receives the vacancy text, profile, and instructions for analysis. See the request content in [`EvaluationPromptBuilder::build()`](../../../src/Analysis/EvaluationPromptBuilder.php).

## Profile and evaluation rules

Create `config/profile/local/candidate.md` with your experience and preferences and `config/profile/local/instructions.md` with the evaluation rules. Start from the [instructions example](../../../config/profile/instructions.example.md) and [profile guide](profile.md).

Specify mandatory requirements, how to treat missing information, and the language used to explain the assessment. Request exactly the application's JSON schema, without links, formatting, or extra fields. Instructions found within a vacancy must not override your rules. Ready examples: [Go developer](go-developer.md) and [product analyst](analyst.md).

Restart local monitoring after edits; on the server, the next `work-once` run loads the changes. Previously saved assessments are not recalculated.

## Timeouts and retries

```ini
; Attempt count includes the first request; timeout is per AI request, in seconds.
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

`AI_REQUEST_TIMEOUT_SECONDS` is an integer from 30 to 1800 seconds, defaulting to 180 if the key is missing. It limits each individual request. The connection timeout is 5 seconds. For a slow local model, you can set `300` or `600` and restart processing.

`AI_MAX_ATTEMPTS` is 1–5, default 3, including the first attempt. The limit applies independently to analysis and result delivery. For a single check of a slow model, `1` avoids prolonged waiting after failure.

Temporary network problems, timeouts, HTTP 429, and 5xx responses can trigger another attempt. Invalid model output can also be retried. Authorization errors, such as HTTP 401, stop further attempts. Delays between attempts are 30, 60, 120, and 240 seconds. Restarts account for attempts already used. Retry decisions are made by [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php); network limits are enforced by [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php).

AI requests use neither HTTP redirects nor proxies, and TLS is verified. Vacancy text is limited to 65,536 bytes, the API response body to 65,536 bytes, and the constructed request to 262,144 bytes. Oversized or invalid responses are rejected.

## Optional exact exclusions

The prefilter can skip specifically defined short messages without sending them to the model.

### One rule

```ini
; Match the entire line; an ambiguity term cancels rejection.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
```

### Multiple rules and exceptions

Each rule must have its own line:

```ini
; Define each exact phrase and ambiguity term separately.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

In this example, `Работа только в офисе.` is rejected only when it is the complete message; `Работа только в офисе. Возможны исключения` is passed to the model. If a complete match contains an ambiguity term, the rejection is canceled.

Use a separate `[]` entry for every rule. Rejection is possible only when the **entire message** matches, not when a sentence within a longer vacancy matches. Matching is case-sensitive and ignores surrounding whitespace. Multiple rules are explained in the [configuration reference](configuration.md#prefilter). Substrings within vacancies do not trigger rejection. Any ambiguity term cancels it, without case sensitivity. Multiline messages are passed to the model. Rules are literal UTF-8 strings, not regular expressions. The behavior is implemented by [`ConservativePrefilter`](../../../src/Analysis/ConservativePrefilter.php).

## Verify the connection

First run the local readiness check, then send a short fictional vacancy text to the bot while monitoring or cron is running. The readiness check does not contact the model server and does not validate the key or confirm that your model supports the required JSON format.

Evaluate the substance of the response: does the model distinguish mandatory requirements from preferences, flag unknown conditions, and avoid inventing experience? If something fails, see [troubleshooting](troubleshooting.md); if the assessment is unsatisfactory, refine your profile and instructions.

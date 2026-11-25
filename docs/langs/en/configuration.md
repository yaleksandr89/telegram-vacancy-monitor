# Configuration

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/configuration.md) | **Selected** | [Español](../es/configuration.md) | [中文](../zh/configuration.md) | [Français](../fr/configuration.md) | [Deutsch](../de/configuration.md) |

[← Back to README](README.md)

The private configuration is in `config/bot.ini` relative to the application root. Create it from [`config/bot.example.ini`](../../../config/bot.example.ini), leaving the example free of secrets. All settings are defined in this INI file.

The file is read by the built-in INI parser without converting text to booleans. Preserve the quotation marks, use decimal integers for numeric settings, and `[]` for repeated list items. Restart local monitoring after editing. A new `work-once` run and each new webhook request read the current file.

## Modes and commands

Commands are defined in [`bin/bot.php`](../../../bin/bot.php). Without arguments, the default is `run`.

| Command | Mode | Requirements |
| --- | --- | --- |
| `php bin/bot.php identify` | `polling` | Bot token and an available `var/bot.lock`; profile, user ID, and AI settings are not required. |
| `php bin/bot.php run` | `polling` | Bot token, Telegram user ID, profile, instructions, AI settings, and storage access. |
| `php bin/bot.php work-once` | `webhook` | Bot token, Telegram user ID, webhook secret, profile, instructions, AI settings, and storage access. |
| `php bin/bot.php webhook set <https-url>` | Webhook management | Bot token, `TELEGRAM_RECEIVE_MODE=webhook`, valid `TELEGRAM_WEBHOOK_SECRET`, and a public HTTPS address. |
| `php bin/bot.php webhook info` | Webhook management | Bot token only; does not need an ID, profile, AI settings, or a selected receive mode. |
| `php bin/bot.php webhook delete` | Webhook management | Bot token only; does not need an ID, profile, AI settings, or a selected receive mode. |
| `public/webhook.php` via FPM | `webhook` | Bot token, Telegram user ID, webhook secret, and an initialized database; AI and profile are not loaded in the HTTP request. |

`webhook set` accepts an HTTPS URL with a public DNS name and port 443, 80, 88, or 8443. URLs with credentials, a query, or a fragment are rejected. `webhook info` displays registration status, pending update count, and the last error details, **but does not print the registered URL**. `webhook delete` keeps pending updates (`drop_pending_updates=false`). These commands do not open SQLite or modify `config/bot.ini`; selecting a mode does not register a webhook.

In a local archive, `identify` and `run` are invoked from the menu using the bundled PHP; system PHP is not required. `work-once` and webhook management are described for the source-based server installation. `identify` acquires the nonblocking lock before contacting Bot API; during an initial lookup it may create only the private `var/` directory and `bot.lock`.

## Telegram

| Key | Purpose and allowed values | Required / default |
| --- | --- | --- |
| `TELEGRAM_BOT_TOKEN` | Token from BotFather in the form “digits:letters/digits/`_`/`-`”. Matching the format does not prove the token is valid. | Required for all bot interactions; empty in the example. |
| `TELEGRAM_ALLOWED_USER_ID` | Numeric Telegram account ID whose vacancies the bot accepts and to which it sends results. Obtain through `identify` or the menu after `/start`. | Required for `run`, `work-once`, and webhook reception; not needed for `identify` or webhook management; empty in the example. |
| `TELEGRAM_RECEIVE_MODE` | `polling` for local `run`/`identify`; `webhook` for FPM, `work-once`, and the `webhook set` requirement. | Optional; defaults to `polling` if absent. |
| `TELEGRAM_WEBHOOK_SECRET` | Secret token used by the server to authenticate incoming webhook requests: 1–256 characters from `A–Z`, `a–z`, `0–9`, `_`, `-`. Matches `secret_token` when running `webhook set`. | Required for webhook reception, `work-once`, and `webhook set`; not needed for `webhook info/delete`; empty in the example. |

Changing `TELEGRAM_RECEIVE_MODE` does not register or remove a webhook; use the separate `webhook set/info/delete` commands. HTTP validation is in [`WebhookReceiver`](../../../src/Application/WebhookReceiver.php), and its settings are loaded by [`public/webhook.php`](../../../public/webhook.php). The switching procedure is in the [server guide](vds-webhook.md#switching-modes).

## Connecting AI

Model connection settings are defined in `config/bot.ini`. [`OpenAiCompatibleEvaluator`](../../../src/Analysis/OpenAiCompatibleEvaluator.php) validates the requirements.

| Key | Purpose and allowed values | Required / default |
| --- | --- | --- |
| `AI_PROVIDER_LOCATION` | `local` or `cloud`; controls allowed URLs and whether a key is required. | Optional; defaults to `local`. |
| `AI_BASE_URL` | Base URL without `/chat/completions`. HTTP/HTTPS; no username, password, query parameters, fragments, spaces, or control characters; maximum 2048 bytes. | Required with no URL fallback; example: `http://127.0.0.1:1234/v1`. |
| `AI_MODEL` | Nonempty API model identifier in UTF-8; maximum 200 bytes, no ASCII control characters. | Required; empty in the example. |
| `AI_API_KEY` | Key sent in `Authorization: Bearer`; UTF-8, maximum 4096 bytes, no ASCII control characters. | Optional for `local` unless required by the server; required and nonempty for `cloud`; empty in the example. |
| `AI_MAX_ATTEMPTS` | Integer 1–5: total attempts including the first one, independently for analysis and delivery. | Optional; default `3`. |
| `AI_REQUEST_TIMEOUT_SECONDS` | Integer 30–1800: time limit for a single AI request, in seconds. | Optional; default `180`; an empty value does not trigger the default. |

For `local`, the permitted hosts are `localhost`, `[::1]`, IPv4 ranges `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, and `192.168.0.0/16`. Local network names and other IPv6 addresses are not allowed. For `cloud`, HTTPS and a nonempty key are mandatory.

The connection timeout is 5 seconds and is not configurable separately. HTTP redirects and proxies are disabled for AI requests; TLS verification is enabled. [`CurlAiTransport`](../../../src/Analysis/Http/CurlAiTransport.php) enforces these limits. Temporary errors are retried after a delay, while permanent errors stop processing without waiting for remaining attempts. See the [model setup guide](ai-models.md#timeouts-and-retries).

## Prefilter

The prefilter skips predefined short messages without contacting AI. Both lists are empty by default. Rules are implemented by [`ConservativePrefilter`](../../../src/Analysis/ConservativePrefilter.php).

| Key | Purpose |
| --- | --- |
| `AI_PREFILTER_EXACT_REJECT_LINES[]` | Exact text of the whole message that may be immediately rejected. |
| `AI_PREFILTER_AMBIGUITY_TERMS[]` | Word or phrase whose presence **cancels an exact rejection**. |

### One phrase

```ini
; Reject only if the entire message matches exactly.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
```

A message consisting only of `Только ночная смена.` is skipped without analysis after surrounding whitespace is trimmed. A message such as `Только ночная смена. Есть варианты?` no longer matches the entire text and is passed to the model.

### Multiple phrases and exceptions

Each phrase is entered on **its own line**. This example has three exact rules and two ambiguity terms:

```ini
; Exact rejection phrases and ambiguity terms that cancel rejection.
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

A complete match to any of the three phrases results in rejection. If any ambiguity term is present, rejection is canceled and the text is passed to the model. Exact phrase matching is case-sensitive; ambiguity terms are matched without regard to case.

Each list item contains **one nonempty string**, not comma-separated rules. Multiline vacancy texts are not rejected: the entire message is compared, not a single sentence. Regular expressions are not used.

## Profile and storage

The following paths have no INI keys; they are defined by the [CLI entry point](../../../bin/bot.php).

| Path | Purpose |
| --- | --- |
| `config/profile/local/candidate.md` | Candidate profile, required for processing. |
| `config/profile/local/instructions.md` | Model instructions, required for processing. |
| `var/vacancies.sqlite` | Messages, results, and queue. |
| `var/bot.lock` | Shared lock for `run`, `identify`, and cron `work-once`; webhook requests use SQLite transactions. |

Markdown file limits are described in the [profile guide](profile.md). The configuration, profile, and `var/` contain private data and are excluded from Git; preserve them during updates.

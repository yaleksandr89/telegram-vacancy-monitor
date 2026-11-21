# Troubleshooting

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](troubleshooting.md) | **Selected** | [Español](troubleshooting_es.md) | [中文](troubleshooting_zh.md) | [Français](troubleshooting_fr.md) | [Deutsch](troubleshooting_de.md) |

[← Back to README](../readme/README_en.md)

Start with “Проверить готовность” (“Check readiness”) in the local application and open “Подробнее” (“Details”) for the flagged category. This check does not contact Telegram or an AI provider, inspect profile contents, or write to SQLite. Success means local prerequisites are met; connectivity must be tested by sending a sample text to the running bot.

Interface strings are in [UI messages](../../src/ui/windows/messages.json); checks and error categories are in [`inspect.php`](../../src/ui/windows/inspect.php). When requesting help, provide the category, exit code, OS, and launch method, but no tokens or private profile text.

## Application does not open

| Symptom | Action |
| --- | --- |
| “Файлы приложения отсутствуют или повреждены” (missing/damaged files) | Extract a trusted archive into a separate folder again; retain its structure and migrate only private INI, profile, and `var/`. |
| “Нужен интерактивный терминал” (interactive terminal required) | Run from a regular terminal without redirecting standard input, output, or error. |
| “Среда PHP приложения неполна или устарела” (incomplete/outdated PHP environment) | Verify that the archive matches your OS/architecture and all of `runtime/` was extracted. |
| Launch/diagnostics setup failed | Check system temporary directory access and file execution permissions. |
| Windows blocks the script | Check the organization’s effective PowerShell policy and coordinate with the administrator. |
| macOS blocks a downloaded app | Check archive origin and the reason shown in macOS security settings. |

If public files are damaged, restore the complete archive: the [executable](../../src/launcher/main.go) checks them against a manifest. The [local guide](local-gum_en.md#data-and-updates) covers reinstalling without losing private data.

Source installations require PHP `^8.5`, extensions from [`composer.json`](../../composer.json), and Composer’s generated autoloader. If `vendor/autoload.php` is missing, install dependencies from `composer.lock`; the profile and SQLite do not need to change.

## Configuration or profile not ready

- Create `config/bot.ini` from the [example](../../config/bot.example.ini). Ensure the editor did not add `.txt` to the filename.
- Check quotation marks and INI key names. Prefilter lists need repeated keys with `[]`.
- Set the bot token, your account’s Telegram ID in `TELEGRAM_ALLOWED_USER_ID`, and the model URL/identifier. Cloud models also require an API key.
- Create both `config/profile/local/candidate.md` and `config/profile/local/instructions.md`.
- Use ordinary, nonempty UTF-8 files without symlinks, no more than 32,768 bytes and 16,000 characters each. Tabs/newlines are permitted; other control characters are not.

If readiness passes a file but starting the application rejects it, check encoding, character count, and content: the menu checks only file accessibility and size. Precise requirements are in the [profile guide](../../config/profile/README_en.md) and the [configuration reference](../reference/configuration_en.md).

After changes, restart local monitoring. On the server, the next `work-once` loads updated files.

## Cannot get an ID

Send `/start` without extra text, then choose “Проверить сейчас” (“Check now”) again or run `php bin/bot.php identify` from a source installation. A forwarded command or `/start` with parameters does not count. The search runs only on request and does not acknowledge updates.

If `var/bot.lock` is busy, stop the other polling process and retry without deleting the lock. From a source installation you can inspect registration with `php bin/bot.php webhook info` and remove it with `webhook delete` following the [mode-switch procedure](vds-webhook_en.md#switching-modes). If multiple IDs appear, do not guess: use the [ID verification procedure](telegram-bot_en.md#find-your-id). The profile and AI connection are not required for initial ID lookup.

## Authentication, access, and reception conflicts

| Error | Cause and action |
| --- | --- |
| Bot API 401 / authentication rejected | Check the BotFather token; if revoked, replace it in INI. |
| Bot API 403 / chat unavailable | Make sure you started the chat, did not block the bot, and specified your own Telegram ID. |
| Bot API 409 / polling conflict | Stop the second copy and check webhook registration with `php bin/bot.php webhook info`. |
| “Занята блокировка var/bot.lock.” | Stop the active `run` or `identify`, without deleting the lock file. |
| “Недоступна блокировка var/bot.lock.” | Check access rights to the private `var/` directory and lock file. |
| Webhook selected; local launch prohibited | Use server-side `work-once` or complete the switch back to polling. |
| Bot API 429 | Final delivery respects a valid `retry_after`; do not run several consumers, and wait for the permitted retry. |

A bot receives updates through either `getUpdates` or a webhook. Changing the INI does not remove an existing webhook. To return to polling, run `php bin/bot.php webhook delete` (which keeps pending updates), verify “Webhook не зарегистрирован.” with `webhook info`, and follow the [mode-switch procedure](vds-webhook_en.md#switching-modes).

The menu’s `identify --json` mode intentionally returns a generic error without the underlying exception. The category can remain unknown; this does not prove a particular network or configuration cause.

## Network or TLS errors

For Telegram, check DNS, HTTPS access to `api.telegram.org`, system time, and certificate trust. Standalone archives must retain `runtime/cacert.pem`. Do not disable TLS verification to hide an error.

For AI, check separately that the server is running, the model loaded, the address reachable from the application’s environment, and the firewall permits the port. `127.0.0.1` in WSL, a VM, or a VDS does not refer to another computer. In `local` mode use an accessible private IPv4.

The AI transport does not use proxies or follow HTTP redirects. Specify the final base URL. Network limits are implemented by [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php).

## Vacancy saved but no assessment

1. Ensure local monitoring is still running. On the server, check the cron schedule, its user, absolute PHP/project paths, and restricted command output.
2. Allow for the queue and response time: requests run sequentially; on the server, processing starts according to cron.
3. Check the model and API shape. Set the base URL without `/chat/completions`; the app appends it.
4. For a slow model, increase `AI_REQUEST_TIMEOUT_SECONDS` from `180` to `300` or `600` and restart processing. The range is 30–1800.
5. If “Не удалось обработать” appears, fix the cause before another test: the original vacancy remains in the database, but a permanently failed job does not resume automatically.

Successful webhook reception does not prove that AI is configured: the HTTP handler does not load the profile or call the model. `work-once` must complete its own initialization successfully.

To determine where processing stopped, open **your own** `var/vacancies.sqlite` with a local SQLite client **read-only** and inspect statuses without vacancy text:

```sql
-- Read-only: last ten states, without vacancy text.
SELECT message_id, analysis_status, analysis_error, analysis_attempts,
       delivery_status, delivery_error, delivery_attempts
FROM analysis_jobs
ORDER BY message_id DESC
LIMIT 10;
```

Statuses are stored by [`SqliteAnalysisRepository`](../../src/Storage/SqliteAnalysisRepository.php). Never edit the counters or states manually. Do not publish the database; redact personal IDs from diagnostic extracts before sharing them.

## Model error or invalid output

| Analysis category | What to check |
| --- | --- |
| `http_401`, `http_403` | Key and access rights for the chosen model. |
| `http_404` | API base path and model identifier in the provider’s documentation. |
| `http_429`, `http_5xx` | Provider availability and rate limits; these failures may be retried. |
| `timeout` | Timeout setting, model loading, and inference speed. |
| `invalid_response` | Chat Completions envelope: string `choices[0].message.content`, without tool calls. |
| `invalid_output` | Exactly one JSON object with required fields and valid values; either bare or in **one** Markdown fence labeled `json`, with no extra prose. |
| `response_size` | API response too large: limit 65,536 bytes. |
| `vacancy_size_or_encoding` | UTF-8 vacancy text and 65,536-byte limit. |
| `transport` | Connection, TLS, and network access to the AI server. |

`http_5xx` means a family of concrete statuses such as `http_503`. Retry eligibility depends on the cause, not just the remaining attempt count. See the [result schema and string rules](../reference/analysis-result_en.md) and [model setup](ai-models_en.md).

## Assessment in DB but not in chat

Check that the bot is not blocked and the chat is reachable. Sending the saved result has its own retry attempts and does not call the model again. When `delivery_status = failed`, the result remains in SQLite; no separate failure notification is sent. There is no manual resend command.

The start-of-analysis notification has only one attempt, so its absence does not mean the job was lost. A crash between delivery and recording confirmation can cause a duplicate final message. See [processing and delivery](message-processing_en.md).

## SQLite or webhook unavailable

Check free space, database read/write permissions, and `var/` access for SQLite journals. On a server, CLI and FPM must share the same database; check access from both users. SQLite lock waiting is limited to 5 seconds.

Webhook returns 503 when storage is not ready/fails, and 403 for an invalid secret. Check `TELEGRAM_WEBHOOK_SECRET` in the private INI and registration via `php bin/bot.php webhook info`. If the secret changed, register it again with `webhook set` and your HTTPS URL. The [server guide](vds-webhook_en.md) documents HTTP statuses, database preparation, and permissions.

Do not delete `var/`, the database, or local configuration to get a “clean” start. Preserve private files and queue state when updating.

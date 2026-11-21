# Server: webhook and cron

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](vds-webhook.md) | **Selected** | [Español](vds-webhook_es.md) | [中文](vds-webhook_zh.md) | [Français](vds-webhook_fr.md) | [Deutsch](vds-webhook_de.md) |

[← Back to README](../readme/README_en.md)

The server receives vacancies over HTTPS, saves them in SQLite, and processes them on a schedule. You can send the bot vacancy text or forward a message from another chat.

[`public/webhook.php`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/public/webhook.php) saves incoming messages. `php bin/bot.php work-once` performs AI evaluation and delivers results. Use either this mode or local polling for a single bot token, not both.

## Prepare the environment

Put the repository files in a permanent server directory **outside** the public website directory.

You need PHP `^8.5` for CLI and PHP-FPM, Composer, HTTPS with a valid certificate, and extensions `curl`, `json`, `mbstring`, `PDO`, `pdo_sqlite`. Check extensions separately in CLI and FPM because their configurations may differ. The requirements are in [`composer.json`](../../composer.json).

From the server installation root, run:

```bash
# Install dependencies from the project's composer.lock.
composer install
```

Use this project's `composer.lock`. Composer is needed to prepare the installation, not to handle webhook requests.

Configure your HTTP server and a dedicated PHP-FPM pool using the facilities of your platform:

- DocumentRoot must point **only** to `public/`; the sole public PHP entry point is `/webhook.php`.
- Disable directory listings, execution of other PHP paths, and alternate aliases/fallback routes exposing the project root.
- Never expose `config/`, `vendor/`, `var/`, `.git/`, or source files via HTTP.
- Limit the request body to 256 KiB; the application also checks this bound.
- Disable `display_errors` in FPM. Do not log request bodies, tokens, secret headers, or vacancy text in HTTP logs.

Specific directives depend on your HTTP server. Verify HTTPS and private-path inaccessibility from outside **before** registering the webhook.

## Create configuration and profile

If `config/bot.ini` does not exist, copy the [sample](../../config/bot.example.ini). Edit it with a text editor instead of entering tokens in shell commands.

1. Create a bot via [BotFather](https://t.me/BotFather), saving its token to `TELEGRAM_BOT_TOKEN`.
2. While `polling` is selected and no webhook is registered, send `/start` directly and run `php bin/bot.php identify`. If `var/bot.lock` is busy, stop the other polling process first.
3. Put your own ID in `TELEGRAM_ALLOWED_USER_ID`.
4. Create `config/profile/local/candidate.md` and `config/profile/local/instructions.md` according to the [profile guide](../../config/profile/README_en.md).
5. Configure `AI_PROVIDER_LOCATION`, `AI_BASE_URL`, `AI_MODEL`, and `AI_API_KEY` when needed. The [model guide](ai-models_en.md) explains connections and data transmission.

For a model on the same VDS, `http://127.0.0.1:1234/v1` is a valid `local` address. For a model on another computer, use a private IPv4 reachable from the VDS. Cloud mode requires HTTPS and an API key.

After determining the user ID, change the configuration:

```ini
; Enable webhook and replace the empty secret with a generated value.
TELEGRAM_RECEIVE_MODE = "webhook"
TELEGRAM_WEBHOOK_SECRET = ""
```

Replace the empty value with a random secret of 1–256 characters from `A–Z`, `a–z`, `0–9`, `_`, `-`. For example, generate a 64-character value with built-in PHP:

```bash
# Generate a secret without placing it in shell history.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Copy the output into `TELEGRAM_WEBHOOK_SECRET` in the private `config/bot.ini`; do not publish it in logs or screenshots. `webhook set` passes the value to Telegram as `secret_token`, so do **not** append it to the URL.

See the full list of settings in the [configuration reference](../reference/configuration_en.md).

## Set permissions and prepare SQLite

CLI and FPM must use the **same absolute installation path** and the same `var/vacancies.sqlite` database. Prefer a dedicated unprivileged application account for the cron task and PHP-FPM pool.

That account needs to read code, `vendor/`, INI, and profile, and write to the database and `var/`: SQLite creates a journal beside the DB and CLI creates `bot.lock`. Do not grant other users access to configuration and data. If CLI and FPM use different accounts, configure narrow ACLs, including defaults for newly created files. Both CLI/FPM use `umask(0077)`, so a shared group alone is insufficient.

Verify permissions as **both** identities. Do not apply broad recursive `chown` or `chmod` across the project.

Before registering the webhook, execute as the cron user:

```bash
# Creates DB schema and may process pending jobs; NOT a dry-run.
php bin/bot.php work-once
```

Use the correct PHP CLI version. This command validates configuration and profile, creates `var/` and DB schema if necessary, and **processes already pending jobs**. The webhook can receive messages after DB creation.

Before upgrades, back up private INI, profile, and SQLite with handlers stopped. Never replace an existing DB with an empty one or delete queue state merely to test the installation.

## Register webhook

Make sure Telegram can reach your HTTPS `/webhook.php` endpoint. A request without the secret header gets HTTP 403; GET gets HTTP 405. Before registration, stop all `run` and `identify` processes for this bot: polling and webhook cannot operate simultaneously.

On source installations, use the built-in `php bin/bot.php webhook` commands. They read token and, when needed, the secret from private `config/bot.ini`, so you do not enter secrets in terminal commands.

| Command | Purpose |
| --- | --- |
| `php bin/bot.php webhook set <https-url>` | Register a webhook at the specified HTTPS URL; requires `webhook` mode and a valid INI secret. |
| `php bin/bot.php webhook info` | Registration state, pending update count, and details of the latest delivery error. |
| `php bin/bot.php webhook delete` | Unregister without deleting pending updates. |

The implementation calls official [setWebhook](https://core.telegram.org/bots/api#setwebhook), [getWebhookInfo](https://core.telegram.org/bots/api#getwebhookinfo), and [deleteWebhook](https://core.telegram.org/bots/api#deletewebhook). Registration uses `allowed_updates=["message"]`, `drop_pending_updates=false`, and `max_connections=1`. Deletion also uses `drop_pending_updates=false`.

### Manage webhook

With `TELEGRAM_RECEIVE_MODE = "webhook"` and a nonempty `TELEGRAM_WEBHOOK_SECRET`, run from the server project root (replace the URL with your **actual public HTTPS domain**):

```bash
# Register your HTTPS address and inspect registration state.
php bin/bot.php webhook set 'https://bot.example.org/webhook.php'
php bin/bot.php webhook info
```

The `set` URL must have a public DNS hostname and HTTPS on port 443, 80, 88, or 8443. Credentials, query string, and fragment are forbidden. On success, `set` prints `Webhook зарегистрирован.`. `info` reports registration, pending updates, and errors, **but does not print the registered URL or compare it with the URL you supplied**.

After checking registration, you may enable cron. To unregister:

```bash
# Unregister, preserve pending updates, and check state.
php bin/bot.php webhook delete
php bin/bot.php webhook info
```

After `delete`, expect `Webhook удалён. Ожидающие обновления сохранены.`, then `Webhook не зарегистрирован.`. `info` and `delete` require only a valid bot token, so they work even with `polling` selected. They do not need SQLite, profile, or AI settings. Do not create temporary PHP scripts making independent Bot API calls.

## Run processing with cron

Schedule a run once a minute as the designated account. Replace **both** absolute paths:

```cron
# Run once per minute; replace both absolute paths.
* * * * * /absolute/path/to/php8.5 /absolute/path/to/project/bin/bot.php work-once
```

The cron working directory does not matter to the application: the [CLI entry point](../../bin/bot.php) resolves paths relative to itself. Send cron output only to a private log or administrator email, never a public directory.

The command acquires the shared `var/bot.lock`, recovers interrupted attempts, and processes up to 10 batches. After 20 seconds no new batches start, but an in-progress request may take up to `AI_REQUEST_TIMEOUT_SECONDS` (30–1800 seconds). A busy worker skips the next cron run instead of running concurrent analyses. This is implemented in [`BotRunner::workOnce()`](../../src/Application/BotRunner.php).

Send a short fictitious vacancy to the bot. Expect `Вакансия сохранена.` first, then, after cron runs, a start notification and final result. Receipt and start notices can fail during network errors without losing the persisted message.

## Reception and delivery failures

[`WebhookReceiver`](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/src/Application/WebhookReceiver.php) checks `X-Telegram-Bot-Api-Secret-Token` **before** parsing JSON or opening SQLite. The update, message, and job are stored in one transaction. HTTP 204 is returned after the SQLite commit. Repeated delivery of the same `update_id` does not add a job; updates may arrive out of order.

| HTTP status | Action |
| --- | --- |
| 400 | Check JSON format and required request fields. |
| 403 | Compare the secret in INI and webhook registration; ensure the header reaches FPM. |
| 405 | Use POST; GET only tests rejection. |
| 413 | Check body size: 256 KiB maximum. |
| 415 | Set `Content-Type: application/json`. |
| 503 | Check mode, configuration, Composer autoloader, existing DB, and SQLite permissions. |

SQLite waits up to 5 seconds for a lock. A failed write returns 503 so Telegram can retry delivery; retries are limited, so fix failures promptly. Acknowledgment is sent only **after** SQLite persists the data. Repeating the same update does not create another job. See [duplicate handling](message-processing_en.md).

The analysis-start notification is attempted only once per vacancy. A completed assessment is saved before sending and is not recalculated if delivery fails. `AI_MAX_ATTEMPTS` applies independently to analysis and delivery. Permanently failed jobs are not automatically recreated. See [result formats and states](../reference/analysis-result_en.md).

## Switching modes

**Local application to server:**

1. Stop monitoring and finish any running `identify`. Wait for in-progress processing to end.
2. With processes stopped, move `config/bot.ini`, profile, and **all of `var/`**, including the existing SQLite DB, to the server. Never run two copies with the same token and divergent data.
3. Set up PHP-FPM, HTTPS, permissions, and cron schedule, but **do not yet enable cron or register webhook**. Confirm CLI and FPM share one DB.
4. Set `TELEGRAM_RECEIVE_MODE = "webhook"` and the secret. As the cron user, run `php bin/bot.php work-once` to prepare DB schema. **This is not a diagnostic dry-run:** if jobs are pending, it can call the model and send messages. If processing the queue is undesirable now, do not run `work-once` merely to inspect settings.
5. Register your HTTPS URL with `php bin/bot.php webhook set 'https://bot.example.org/webhook.php'`, replacing the example with your own. Check `webhook info` and enable cron.

Use a single up-to-date data copy while switching. Moving the DB cannot recover updates Telegram already acknowledged or purged.

**Server to local application:**

1. Disable cron and wait for any active worker to finish.
2. Run `php bin/bot.php webhook delete` (preserves pending updates), then use `php bin/bot.php webhook info` to verify `Webhook не зарегистрирован.`.
3. Wait for in-flight PHP-FPM requests and disable the public webhook handler so it can no longer write to the DB.
4. With handlers stopped, preserve the same DB and private files; copy all of `var/` when moving the installation.
5. Set `TELEGRAM_RECEIVE_MODE = "polling"` and start the local app or run `php bin/bot.php run` from sources.

Do not manually clear the cursor, received-updates table, or messages. After long inactivity, polling resets an untrusted outgoing `offset` without deleting stored data. `run` and `identify` cannot start with `webhook` selected; changing the INI alone neither registers nor removes webhook: use `webhook set` and `webhook delete`.

For failures, see [local diagnostics](local-gum_en.md#troubleshooting) and [detailed troubleshooting](troubleshooting_en.md).

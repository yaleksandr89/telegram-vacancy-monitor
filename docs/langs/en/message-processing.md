# How the application receives and processes vacancies

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/message-processing.md) | **Selected** | [Español](../es/message-processing.md) | [中文](../zh/message-processing.md) | [Français](../fr/message-processing.md) | [Deutsch](../de/message-processing.md) |

[← Back to README](README.md)

When you send or forward a vacancy to the bot, Telegram delivers an update to the application. The application saves the text and creates an assessment job. After analysis, the result is sent back to the same chat.

## What is stored

For each vacancy, SQLite stores the text, message identifiers, and, when available, information about the original forwarded publication. The assessment and delivery states are stored separately.

The [`messages` and `analysis_jobs` tables](../../../src/Storage/SqliteSchema.php) implement this in the database schema.

## Why a cursor is needed

During local execution, the application receives updates with `getUpdates`. Each update has a numeric `update_id`. The **cursor** stores the next position after the last update that was processed and committed reliably. Before every request, the application checks whether that position is still trustworthy.

For example:

1. The application receives update `104` containing a new vacancy.
2. It writes the vacancy to SQLite, creates a job, and saves the next cursor value, `105`.
3. The next request to Telegram starts with `offset = 105`: update `104` no longer needs to be delivered.

Saving the vacancy and advancing the cursor happen together. If the write fails, the cursor stays unchanged so Telegram can deliver the update again. If an update was already stored, the application does not create a second copy.

### Cursor fields

The `cursor` table has one record for local message reception:

| Column | Example | Meaning |
| --- | --- | --- |
| `name` | `main` | Fixed cursor name, not a bot or chat name. |
| `next_offset` | `105` | Position after the last stored `update_id`; checked for freshness before use. |
| `last_handled_at` | `1795000000` | Unix timestamp for when processing was committed together with the cursor. Can be `NULL` in an old database after migration. |

**`next_offset` is calculated from `update_id`, not from a message number.** After update `104`, the stored value is `105`. Within a current sequence, the cursor does not decrease; empty `getUpdates` responses do not update `last_handled_at`. The timestamp and cursor are committed in the same transaction as the message and job, when present.

### Recovery after a long idle period

Telegram may assign new, lower `update_id` values after at least a week with no new updates. Sending an old high `offset` in that situation could acknowledge lower-ID updates that have not yet been handled on the Telegram Bot API side.

Therefore, before **every** outgoing `getUpdates` request, the application sends `offset = 0` when `last_handled_at` is missing, invalid, in the future, or older than five days. It does not erase the old cursor in advance. The first successfully committed update establishes the new position, even if its ID is lower than the old one. SQLite unique constraints still prevent duplicate vacancies and jobs.

If a fresh response contains IDs lower than the requested `offset`, or the updates arrive out of order, reception stops without moving the cursor forward. If writing to SQLite fails, the cursor, timestamp, message, and job roll back together. This mechanism cannot recover updates that Telegram has already acknowledged or deleted after its retention period (at most 24 hours).

Different IDs serve different purposes:

| Field | Meaning |
| --- | --- |
| Telegram update's `update_id` | Event identifier used to calculate `next_offset`. |
| `recipient_message_id` in `messages` | Message ID in the chat with the bot; not used to calculate the cursor. |
| `origin_message_id` in `messages` | Original channel publication's message ID, when Telegram provides it for a forwarded message; not used to calculate the cursor. |

For example, update `104` can contain message `312` in the bot chat and original publication `87` in a channel. The cursor becomes `105`; `312` and `87` have no effect on it.

The cursor is not a vacancy ID or source ID. It is used only to receive `getUpdates` in sequence. See [`SqliteInboxRepository::pollingOffset()` and `applyUpdate()`](../../../src/Storage/SqliteInboxRepository.php).

## What happens with a webhook?

In webhook mode, Telegram sends requests to the server. They may be repeated or arrive out of order. Instead of a polling cursor, the application records each `update_id` in `received_updates` and checks whether that update was already received.

For example, a request with `update_id = 205` is committed and Telegram receives HTTP 204. If the same request is delivered again, no new job is created. If the database write fails, the server responds with an error and Telegram may retry delivery.

This is implemented in [`SqliteInboxRepository::applyWebhookUpdate()`](../../../src/Storage/SqliteInboxRepository.php) and [`WebhookReceiver`](../../../src/Application/WebhookReceiver.php).

## Which vacancies are duplicates?

The application uses two kinds of identifiers:

- **Message in the bot chat.** A redelivery of the same message with the same ID does not create another vacancy.
- **Original forwarded publication.** If Telegram provides the originating channel and publication identifiers, forwarding that same publication again does not produce a new assessment even when the new bot-chat message has a different ID.

For example, you forward a vacancy from a channel today and forward it again tomorrow. If the original publication identifiers are available, the application replies `Эта публикация уже сохранена.` (“This publication has already been saved”). **Identical text alone is not treated as a duplicate:** distinct announcements can share exactly the same text.

The rules are in [`IncomingUpdateProcessor`](../../../src/Application/IncomingUpdateProcessor.php), and message uniqueness constraints are in [`SqliteSchema`](../../../src/Storage/SqliteSchema.php).

## What happens after saving?

1. The bot confirms with `Вакансия сохранена.` (“Vacancy saved”).
2. The application analyzes the stored text using the profile and instructions. On the server, a cron-triggered worker performs this step.
3. The completed assessment is saved in SQLite and sent to Telegram.
4. If sending temporarily fails, the application retries **delivering the saved result**, without requesting a new assessment. For Bot API HTTP 429 responses, it uses a valid `parameters.retry_after` when that exceeds the local delay.
5. If the process stops, unfinished work resumes on the next run, subject to the remaining attempt limit.

The start-of-analysis notification may be missing after a network failure even while analysis continues. Once all attempts are exhausted, analysis does not restart automatically. In the rare case where Telegram receives the final message but its acknowledgment never reaches the application, the final notification may be delivered again.

[`SqliteAnalysisRepository`](../../../src/Storage/SqliteAnalysisRepository.php) stores analysis and delivery states; [`VacancyAnalysisPipeline`](../../../src/Application/VacancyAnalysisPipeline.php) runs the steps. See the [assessment and notification format](analysis-result.md).

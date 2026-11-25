# Configuring the Telegram bot

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/telegram-bot.md) | **Selected** | [Español](../es/telegram-bot.md) | [中文](../zh/telegram-bot.md) | [Français](../fr/telegram-bot.md) | [Deutsch](../de/telegram-bot.md) |

[← Back to README](README.md)

You need a bot created with BotFather. `TELEGRAM_ALLOWED_USER_ID` is the numeric ID of the Telegram account from which the application accepts vacancies and to which it sends results.

## Create a bot

1. Open [BotFather](https://t.me/BotFather) in Telegram and send `/newbot`.
2. Follow the prompts to set the bot's display name and username.
3. Save the provided token in the private `config/bot.ini` created from the [example](../../../config/bot.example.ini).
4. Open your new bot and send `/start`.

The token grants access to the bot. Keep it in the INI file; never place it in commands, screenshots, Issues, or public examples. If it is exposed, regenerate it through BotFather, update the configuration, and restart processing. Bot management is documented in the [official BotFather guide](https://core.telegram.org/bots/features#botfather).

## Find your ID

For initial ID discovery, leave `TELEGRAM_RECEIVE_MODE = "polling"`. The token must already be configured; the user ID, profile, and model settings are not required for this lookup.

**In the local application:** run `./vacancy-monitor` on Linux/macOS or `.\vacancy-monitor.exe` in PowerShell. Select the menu item for finding the Telegram User ID and then the option to check now. These menu labels are available in Russian and English, not in every documentation language.

**When running from source:** execute this from the project root:

```bash
# Show the IDs of accounts that sent /start.
php bin/bot.php identify
```

After the check, the numeric ID of the account that sent `/start` appears. Write **your own ID** to `TELEGRAM_ALLOWED_USER_ID` in `config/bot.ini`. A name beginning with `@` or the bot's own ID will not work.

If no ID appears, send `/start` to the bot again and repeat the check. A forwarded message, attachment caption, or text with extra characters (such as `/starter` or `/start тест`) does not count as the setup command.

If several IDs appear, **do not guess**. In a source installation, run `php bin/bot.php identify` and save the output. Send a fresh `/start` to the bot, then run the command again. If the old lines remain and a new `ID отправителя /start: ...` line appears at the end, it corresponds to your message *provided nobody else sent `/start` during that interval*. If the result does not change or other users' commands arrive, this method cannot reliably identify your ID: `getUpdates` returns at most 100 pending updates. The menu only shows distinct IDs without sender details. Do not guess IDs or clear the update queue for this purpose.

The lookup makes one `getUpdates` request, acknowledges no updates, and does not save IDs to the database. `identify` shares the nonblocking `var/bot.lock` lock with `run`; if the lock is held, no Bot API request occurs. Only the token and `polling` mode are needed for the initial lookup. The command can create the private `var/` directory and a lock file, but does not open SQLite. If a webhook is registered, first [switch modes safely](vds-webhook.md#switching-modes). The lookup logic is in [`bin/bot.php`](../../../bin/bot.php).

## Complete the setup

Prepare the [profile and instructions](profile.md), then [connect an AI model](ai-models.md). Start local monitoring or configure [webhook and cron](vds-webhook.md).

To find the ID, send `/start` and run the lookup. Once monitoring is running, the bot responds to this command by asking you to forward a vacancy. Messages that merely start with `/start` are treated as ordinary vacancy text from an allowed user.

## How to send vacancies

- Send the vacancy text to the bot as an ordinary message.
- Or forward a publication from a chat or channel to the bot.
- Text in an attachment caption can also be processed. Images and voice messages themselves are not recognized.

The bot confirms storage of the vacancy and then sends the assessment. See [duplicate delivery and forwarding](message-processing.md) and [how to read the result](analysis-result.md). If no message arrives, see [troubleshooting](troubleshooting.md).

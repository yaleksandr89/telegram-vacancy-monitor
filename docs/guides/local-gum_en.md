# Local application

## Choose a language

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](local-gum.md) | **Selected** | [Español](local-gum_es.md) | [中文](local-gum_zh.md) | [Français](local-gum_fr.md) | [Deutsch](local-gum_de.md) |

[← Back to README](../readme/README_en.md)

Send a vacancy as text or forward an advertisement from another chat or channel. The application saves it, assesses it against your profile, and sends you the result. Keep the application window open while monitoring is running.

## Choose an archive

Verified archives are published on the [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases) page after native checks. No ready-made archives are available there before the first release. The names below refer to future published builds.

| Platform | Archive name | Required on the computer |
| --- | --- | --- |
| Linux x86_64 | `telegram-vacancy-monitor-linux-x86_64.tar.gz` | Interactive terminal, Bash, and `dirname` |
| Windows x64 | `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 and built-in Windows PowerShell 5.1 |
| macOS ARM64 | `telegram-vacancy-monitor-macos-arm64.tar.gz` | macOS 14+ on Apple Silicon and an interactive terminal |

The archive contains a `telegram-vacancy-monitor/` folder with an executable, PHP, Gum, configuration samples, `LICENSE`, and two guides: `docs/guides/local-gum.md` and `docs/guides/vds-webhook.md`. The remaining documentation is available in the repository. On Windows, PowerShell launches the Gum menu. You do not need to install PHP, Composer, or Gum separately. The model server must be started separately.

Keep the entire directory structure intact. Linux uses bundled libraries and a loader; macOS also uses system libraries. The ARM64 archive targets Apple Silicon and the Windows x64 archive targets x64 Windows. macOS may block a downloaded application: inspect the archive’s origin and the security settings message instead of disabling system protection altogether.

The [packager](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/build/common/package.py) defines archive contents. Before starting, the executable checks public files against `SHA256SUMS.json`. Keep settings in the files described below.

## Extract and run

Extract the archive to a writable personal folder. Run these commands from the directory containing the downloaded archive.

Linux:

```bash
# Extract the archive and run the application from the created directory.
tar -xzf telegram-vacancy-monitor-linux-x86_64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Windows: extract the ZIP and **double-click** `telegram-vacancy-monitor/vacancy-monitor.exe`. You can also use PowerShell:

```powershell
# Extract to a separate folder and run the application.
Expand-Archive -LiteralPath .\telegram-vacancy-monitor-windows-x64.zip -DestinationPath .\vacancy-monitor-app
Set-Location -LiteralPath .\vacancy-monitor-app\telegram-vacancy-monitor
.\vacancy-monitor.exe
```

macOS on Apple Silicon:

```bash
# Extract the ARM64 build and run it.
tar -xzf telegram-vacancy-monitor-macos-arm64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

For later starts on Windows, double-click `vacancy-monitor.exe` again. On Linux and macOS, run `./vacancy-monitor` from the application folder. Paths with spaces are supported.

## What the application looks like

The main menu, help, and configuration messages are shown in the [interface overview](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-interface_en.md). The actual UI supports Russian and English, not every documentation language.

## Create private configuration

All subsequent paths are relative to the folder containing the executable. Copy the [sample](../../config/bot.example.ini) to `config/bot.ini` if it does not already exist.

Linux/macOS:

```bash
# Create configuration without overwriting an existing file.
cp -n config/bot.example.ini config/bot.ini

# Prepare the profile directory and copy the instructions sample.
mkdir -p config/profile/local
cp -n config/profile/instructions.example.md config/profile/local/instructions.md
```

Windows PowerShell:

```powershell
# Do not overwrite your existing configuration during setup.
if (-not (Test-Path -LiteralPath .\config\bot.ini)) {
    Copy-Item -LiteralPath .\config\bot.example.ini -Destination .\config\bot.ini
}
# Create the directory for your private profile files.
New-Item -ItemType Directory -Path .\config\profile\local -Force | Out-Null
# Copy the sample only if you do not already have instructions.
if (-not (Test-Path -LiteralPath .\config\profile\local\instructions.md)) {
    Copy-Item -LiteralPath .\config\profile\instructions.example.md -Destination .\config\profile\local\instructions.md
}
```

Edit `config/bot.ini` in a text editor; never enter credentials as terminal commands.

1. Open [BotFather](https://t.me/BotFather), create a bot with `/newbot`, and save the token in `TELEGRAM_BOT_TOKEN`.
2. Send `/start` directly to your bot as a standalone message, not a forwarded message or a message with extra text.
3. In the application choose “Узнать Telegram User ID” (“Find Telegram User ID”), then “Проверить сейчас” (“Check now”). You only need the bot token and `polling` mode at this stage; the profile and AI settings are not required.
4. Put **your own numeric Telegram ID** in `TELEGRAM_ALLOWED_USER_ID`. If multiple IDs appear and you cannot identify yours, do not guess: follow the [bot guide](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_en.md#find-your-id).
5. Keep `TELEGRAM_RECEIVE_MODE = "polling"`.

ID lookup happens only when requested and does not acknowledge pending updates. `identify` shares the nonblocking `var/bot.lock` lock: stop active monitoring first, without deleting the lock file. If a webhook is registered, first follow the [safe switch to local mode](vds-webhook_en.md#switching-modes). More details are in the [online bot guide](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_en.md).

## Set up the profile and model

Create `config/profile/local/candidate.md` in your editor and describe your experience, skills, preferred work, and constraints. Put the assessment rules in `config/profile/local/instructions.md`. Both files must be nonempty, valid UTF-8, and no larger than 32 KiB and 16,000 characters each. See the [profile guide](../../config/profile/README_en.md).

Start a model server exposing Chat Completions. If it runs on the same computer, an example is:

```ini
; Local API example: supply your own model identifier.
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

Replace `model-id` with the loaded model’s API identifier and the address with your endpoint. The application appends `/chat/completions` to `AI_BASE_URL`. The server must accept nonstreaming requests and return JSON in `message.content` matching the supplied schema. Test model/server compatibility using a sample vacancy.

In `local` mode, the allowed addresses are `localhost`, `127.0.0.0/8`, `[::1]`, and private IPv4 in `10.0.0.0/8`, `172.16.0.0/12`, or `192.168.0.0/16`. For a model on another computer, use its private IPv4 and restrict access to the necessary network. A local network hostname instead of an IP will fail validation. `127.0.0.1` means the machine where the application runs.

For a cloud model, use `AI_PROVIDER_LOCATION = "cloud"`, the provider’s HTTPS URL, model identifier, and `AI_API_KEY`.

See [connecting models](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_en.md) and the [INI reference](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/configuration_en.md).

## Check readiness and start monitoring

Select “Проверить готовность” (“Check readiness”). “Подробнее” (“Details”) shows results for runtime, configuration, profile, and storage. You can then send a [sample vacancy](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/vacancy-analysis_en.md) to test the full workflow: that examples page is not shipped in standalone archives.

After configuration, choose “Запустить мониторинг” (“Start monitoring”) and send your bot a short vacancy. Normally, it responds with `Вакансия сохранена.`, followed by a start notification and an assessment. The result contains verdict, priority, reasoning, conditions, matches, risks, and questions. The source is labeled separately; a URL in the vacancy is not automatically verified.

Model requests are processed sequentially. For a slow model, increase `AI_REQUEST_TIMEOUT_SECONDS` to `300` or `600` and restart monitoring. The allowed range is 30–1800 seconds for each request. `AI_MAX_ATTEMPTS` allows 1–5 total attempts separately for analysis and result delivery, including the initial attempt. Permanent errors may end the operation immediately.

Assessments are stored before delivery, so repeated message delivery does not require another analysis. See [duplicates and recovery](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/message-processing_en.md).

## Menu and stopping

| Action | Purpose |
| --- | --- |
| “Запустить мониторинг” | Receive messages and process the queue while the process is open. |
| “Проверить готовность” | Local diagnosis without an API request. |
| “Узнать Telegram User ID” | On-demand lookup of `/start` senders. |
| “Справка” | Quick start, processing, privacy, model, and server help. |
| “Язык” | Russian or English menu language until the app closes. |
| “Выход” | Quit the application. |

Use arrow keys and Enter to navigate. Esc goes back; on the main menu it leaves that menu open. Press Ctrl+C to stop monitoring and the application. After a normal command completion or an error, the menu offers retry, back, or exit; there is no automatic restart. The model’s output language is configured separately in `instructions.md`, not by the menu language.

## Data and updates

Personal files are created during setup and are not included in the archive. Examples: [`config/bot.example.ini`](../../config/bot.example.ini), [Go profile](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_en.md#candidate-profile), [analyst profile](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_en.md#candidate-profile), and [model instructions](../../config/profile/instructions.example.md).

| Path | What to keep |
| --- | --- |
| `config/bot.ini` | Bot token, Telegram ID, AI connection, and rules. |
| `config/profile/local/` | Profile, instructions, and personal backups. |
| `var/` | SQLite, queue, and processing state. |

Before an update, stop the application and confirm the process has exited. Make a private backup of those three paths. Extract the new archive into a separate folder, move private files and the entire `var/` directory into the matching locations, compare your INI with the updated sample, and launch only the new copy. Do not extract over a running installation or replace your database with an empty one.

After changing configuration, profile, or instructions, restart monitoring. Previously stored assessments are not automatically recalculated.

## Troubleshooting

- **No ID:** send `/start` and select “Проверить сейчас” again. Check token, webhook registration, and `var/bot.lock`.
- **Configuration error:** inspect `config/bot.ini`, both profile files, and readiness details.
- **Authentication error:** check the BotFather token, update the INI and restart.
- **Network or TLS error:** check access to Telegram, DNS, system time, and certificates. Do not disable TLS verification.
- **Storage unavailable:** inspect free space and permissions for the application directory, `var/`, and SQLite. Do not delete the database or lock file to bypass an error.
- **Saved but no assessment:** make sure monitoring runs, the model server is reachable, and the model returns valid JSON.
- **Damaged public files:** extract a trusted archive again into a separate folder and restore only personal data.

Further help: [troubleshooting](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/troubleshooting_en.md) and [result format](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_en.md).

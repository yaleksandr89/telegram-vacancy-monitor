# 本地应用

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](local-gum.md) | [English](local-gum_en.md) | [Español](local-gum_es.md) | **已选择** | [Français](local-gum_fr.md) | [Deutsch](local-gum_de.md) |

[← 返回 README](../readme/README_zh.md)

向机器人发送职位文本，或从其他聊天或频道转发招聘公告。应用会保存职位，根据个人档案评估，并将结果发回。监控运行期间请保持应用窗口打开。

## 选择安装包

适用于 Linux x86_64、Windows x64 和 macOS ARM64 的 standalone 安装包已在 [Releases](https://github.com/yaleksandr89/telegram-vacancy-monitor/releases) 页面提供下载。请从下表选择与你的平台对应的安装包。

| 平台 | 文件名 | 电脑要求 |
| --- | --- | --- |
| Linux x86_64 | `telegram-vacancy-monitor-linux-x86_64.tar.gz` | 交互式终端、Bash 和 `dirname` |
| Windows x64 | `telegram-vacancy-monitor-windows-x64.zip` | Windows 10/11 和系统自带 Windows PowerShell 5.1 |
| macOS ARM64 | `telegram-vacancy-monitor-macos-arm64.tar.gz` | Apple Silicon 上的 macOS 14+ 和交互式终端 |

安装包内有 `telegram-vacancy-monitor/` 文件夹，包含可执行文件、PHP、Gum、配置示例、`LICENSE` 和两份指南：`docs/guides/local-gum.md`、`docs/guides/vds-webhook.md`。其他文档位于仓库。Windows 通过 PowerShell 启动 Gum 菜单。无需另外安装 PHP、Composer 或 Gum；模型服务器需要单独启动。

请完整保留目录结构。Linux 使用随包附带的库和加载器；macOS 还使用系统库。ARM64 包适用于 Apple Silicon，Windows x64 包适用于 x64 Windows。macOS 可能阻止运行下载的应用：应核实安装包来源及安全设置中的提示，不要直接关闭整个系统保护。

安装包内容由[打包脚本](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/build/common/package.py)定义。启动前可执行文件会根据 `SHA256SUMS.json` 检查公开文件的完整性。请将设置保存在下述文件中。

## 解压与启动

将安装包解压到个人可写目录。以下命令在包含下载文件的目录中运行。

Linux：

```bash
# 解压安装包，并从生成的目录启动应用。
tar -xzf telegram-vacancy-monitor-linux-x86_64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

Windows：解压 ZIP，**双击** `telegram-vacancy-monitor/vacancy-monitor.exe`。也可以通过 PowerShell 启动：

```powershell
# 解压到独立目录并启动应用。
Expand-Archive -LiteralPath .\telegram-vacancy-monitor-windows-x64.zip -DestinationPath .\vacancy-monitor-app
Set-Location -LiteralPath .\vacancy-monitor-app\telegram-vacancy-monitor
.\vacancy-monitor.exe
```

Apple Silicon macOS：

```bash
# 解压 ARM64 版本并运行。
tar -xzf telegram-vacancy-monitor-macos-arm64.tar.gz
cd telegram-vacancy-monitor
./vacancy-monitor
```

之后在 Windows 上可再次双击 `vacancy-monitor.exe`；Linux 和 macOS 则在应用目录中执行 `./vacancy-monitor`。路径可以包含空格。

## 应用界面

主菜单、帮助和配置提示见[界面介绍](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/local-interface_zh.md)。应用实际只提供俄语和英语界面，并不支持文档的全部语言。

## 创建私人配置

以下路径均相对于可执行文件所在目录。如果 `config/bot.ini` 尚不存在，将[示例](../../config/bot.example.ini)复制到该路径。

Linux/macOS：

```bash
# 仅在文件不存在时创建配置，不覆盖已有设置。
cp -n config/bot.example.ini config/bot.ini

# 准备档案目录并复制示例指令。
mkdir -p config/profile/local
cp -n config/profile/instructions.example.md config/profile/local/instructions.md
```

Windows PowerShell：

```powershell
# 再次配置时不要覆盖已有设置。
if (-not (Test-Path -LiteralPath .\config\bot.ini)) {
    Copy-Item -LiteralPath .\config\bot.example.ini -Destination .\config\bot.ini
}
# 创建存放私人档案文件的目录。
New-Item -ItemType Directory -Path .\config\profile\local -Force | Out-Null
# 仅在说明文件不存在时复制示例。
if (-not (Test-Path -LiteralPath .\config\profile\local\instructions.md)) {
    Copy-Item -LiteralPath .\config\profile\instructions.example.md -Destination .\config\profile\local\instructions.md
}
```

使用文本编辑器修改 `config/bot.ini`，不要在终端命令中输入机密信息。

1. 打开 [BotFather](https://t.me/BotFather)，用 `/newbot` 创建机器人，将得到的令牌保存到 `TELEGRAM_BOT_TOKEN`。
2. 向机器人**直接发送**单独的 `/start` 消息，不要转发，也不要添加其他文字。
3. 在应用中选择“Узнать Telegram User ID”（查询 Telegram 用户 ID），然后选择“Проверить сейчас”（立即检查）。此时只需要机器人令牌和 `polling` 模式，尚不需要档案或 AI 配置。
4. 将**自己**的 Telegram 数字 ID 写入 `TELEGRAM_ALLOWED_USER_ID`。如果出现多个 ID 而不能确定哪个属于自己，切勿猜测；请按[机器人指南](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_zh.md#确认你的-id)核实。
5. 保持 `TELEGRAM_RECEIVE_MODE = "polling"`。

查询 ID 只会在手动操作时执行，并且不会确认待处理更新。`identify` 与监控进程共用非阻塞锁 `var/bot.lock`：若正在监控，请先停止，不要删除锁文件。如果已注册 webhook，请先依照[安全切换到本地模式](vds-webhook_zh.md#切换模式)的步骤操作。详见[在线机器人指南](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/telegram-bot_zh.md)。

## 准备档案和模型

使用编辑器创建 `config/profile/local/candidate.md`，写明经验、技能、目标工作与限制；在 `config/profile/local/instructions.md` 中设定评估规则。两份文件都必填、非空，必须为有效 UTF-8，且每份不超过 32 KiB 和 16,000 个字符。详见[档案指南](../../config/profile/README_zh.md)。

启动提供 Chat Completions 的模型服务器。如果模型运行在同一台电脑上，可使用以下配置：

```ini
; 本地 API 示例：请填入实际模型标识符。
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

将 `model-id` 替换为已加载模型的 API 标识符，将地址替换为自己的接口地址。应用会在 `AI_BASE_URL` 后附加 `/chat/completions`。服务器必须接受非流式请求，并在 `message.content` 中返回符合所给 schema 的 JSON。请用测试职位检查服务器和模型的兼容性。

`local` 模式允许 `localhost`、`127.0.0.0/8`、`[::1]` 和 `10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16` 范围内的私有 IPv4。若模型位于另一台电脑，请使用其私有 IPv4，并仅允许必要网络访问。局域网主机名不能代替 IP 通过验证。`127.0.0.1` 指运行应用的电脑。

云模型需要 `AI_PROVIDER_LOCATION = "cloud"`、HTTPS 地址、模型标识符及 `AI_API_KEY`。

详情见[模型接入指南](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/ai-models_zh.md)和 [INI 配置参考](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/configuration_zh.md)。

## 检查环境并开始监控

选择“Проверить готовность”（检查准备情况）。“Подробнее”（详情）显示运行环境、设置、档案和存储检查结果。随后可向机器人发送一条[示例职位](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/vacancy-analysis_zh.md)验证完整流程；示例页面不包含在 standalone 安装包内。

配置完成后，选择“Запустить мониторинг”（开始监控）并发送一段简短职位文本。通常会依次出现 `Вакансия сохранена.`、开始分析提示及评估结果。结果包含结论、优先级、理由、工作条件、匹配项、风险及待确认问题。来源单独标记；公告中的链接不会被自动认定为已验证。

模型请求按顺序处理。如果模型较慢，可将 `AI_REQUEST_TIMEOUT_SECONDS` 设为 `300` 或 `600` 并重启监控。单次请求允许 30–1800 秒。`AI_MAX_ATTEMPTS` 分别规定分析和结果发送的 1–5 次总尝试次数（包括第一次）；永久性错误可能立即结束操作。

评估在发送前已经保存，因此重复接收同一消息无需重新分析。详见[去重与恢复](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/message-processing_zh.md)。

## 菜单与退出

| 操作 | 作用 |
| --- | --- |
| “Запустить мониторинг” | 进程保持打开时接收消息并处理队列。 |
| “Проверить готовность” | 不访问 API 的本地诊断。 |
| “Узнать Telegram User ID” | 手动查询 `/start` 的发送者。 |
| “Справка” | 快速开始、处理、隐私、模型和服务器帮助。 |
| “Язык” | 在应用关闭前选择俄语或英语菜单。 |
| “Выход” | 退出应用。 |

使用方向键和 Enter 选择项目。Esc 返回上一层，在主菜单中不会关闭它。按 Ctrl+C 停止监控和应用。操作完成或出错后，菜单提供重试、返回或退出选项，不会自动重启。模型回答的语言由 `instructions.md` 设置，与菜单语言无关。

## 数据和更新

个人文件在配置时创建，不包含在安装包中。示例：[ `config/bot.example.ini` ](../../config/bot.example.ini)、[Go 开发者档案](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/go-developer_zh.md#候选人档案)、[分析师档案](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/examples/analyst_zh.md#候选人档案)及[模型指令](../../config/profile/instructions.example.md)。

| 路径 | 需要保留的内容 |
| --- | --- |
| `config/bot.ini` | 机器人令牌、Telegram ID、AI 连接与规则。 |
| `config/profile/local/` | 档案、指令和个人备份。 |
| `var/` | SQLite、队列和处理状态。 |

更新前停止应用并确认进程已经退出。私下备份上述三个路径。将新安装包解压到另一个目录，把私人文件和整个 `var/` 放入新目录对应位置，与新版示例核对 INI，然后**只启动新副本**。不要在运行中的安装目录上直接解压，也不要用空数据库替换已有数据库。

配置、档案或指令更改后需重启监控。已保存的评估不会自动重新计算。

## 出现问题时

- **查不到 ID：** 再次发送 `/start` 并选择“Проверить сейчас”；核实令牌、已注册的 webhook 和 `var/bot.lock`。
- **配置错误：** 检查 `config/bot.ini`、两份档案文件及准备情况详情。
- **鉴权失败：** 检查 BotFather 令牌，更新 INI 并重启。
- **网络或 TLS 错误：** 检查 Telegram 连通性、DNS、系统时间与证书。不要关闭 TLS 验证。
- **存储不可用：** 检查应用目录、`var/` 和 SQLite 的空间与权限。不要为绕过错误而删除数据库或锁文件。
- **已保存但无评估：** 确保监控正常运行、模型服务器可访问且模型返回有效 JSON。
- **公开文件损坏：** 将可信安装包重新解压到独立目录，只迁移私人数据。

更多帮助：[故障排除](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/guides/troubleshooting_zh.md)和[结果格式](https://github.com/yaleksandr89/telegram-vacancy-monitor/blob/master/docs/reference/analysis-result_zh.md)。

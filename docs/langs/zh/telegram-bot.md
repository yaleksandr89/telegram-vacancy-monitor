# 配置 Telegram 机器人

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](../ru/telegram-bot.md) | [English](../en/telegram-bot.md) | [Español](../es/telegram-bot.md) | **已选择** | [Français](../fr/telegram-bot.md) | [Deutsch](../de/telegram-bot.md) |

[← 返回 README](README.md)

你需要通过 BotFather 创建机器人。`TELEGRAM_ALLOWED_USER_ID` 是允许向应用发送职位并接收评估结果的 Telegram 账户数字 ID。

## 创建机器人

1. 在 Telegram 中打开 [BotFather](https://t.me/BotFather)，发送 `/newbot`。
2. 按提示设置机器人的显示名称和用户名。
3. 根据[示例](../../../config/bot.example.ini)创建私有的 `config/bot.ini`，并保存获取到的令牌。
4. 打开新创建的机器人并发送 `/start`。

令牌可用于访问机器人。应将令牌保存在 INI 文件中，不要放进命令、截图、Issues 或公开示例。如果令牌泄露，请通过 BotFather 重新生成，更新配置并重启处理进程。机器人管理方法见 [BotFather 官方指南](https://core.telegram.org/bots/features#botfather)。

## 确认你的 ID

首次确认 ID 时，请保持 `TELEGRAM_RECEIVE_MODE = "polling"`。必须先填写令牌；此时不需要用户 ID、档案或模型参数。

**在本地应用中：** 在 Linux/macOS 上运行 `./vacancy-monitor`，在 PowerShell 中运行 `.\vacancy-monitor.exe`。选择查询 Telegram User ID 的菜单项，再选择立即检查。应用菜单只有俄语和英语，没有中文版。

**从源码运行时：** 在项目根目录执行：

```bash
# 显示发送过 /start 的账户 ID。
php bin/bot.php identify
```

检查后，会出现发送 `/start` 的账户数字 ID。将**自己的 ID** 写入 `config/bot.ini` 的 `TELEGRAM_ALLOWED_USER_ID`。带 `@` 的用户名和机器人自身的 ID 都不适用。

如果没有出现 ID，请再次向机器人发送 `/start`，然后重复检查。转发消息、附件说明以及带有额外字符的文本（例如 `/starter` 或 `/start тест`）都不属于此设置命令。

如果找到多个 ID，**不要猜测**。在源码安装环境中执行 `php bin/bot.php identify` 并保存输出。随后向机器人发送新的 `/start`，再执行一次命令。如果旧行仍在，且末尾新增 `ID отправителя /start: ...` 一行，那么*只有在这段时间内无人发送其他 `/start`* 的条件下，新行才属于你的消息。如果结果没有变化或有其他用户的命令到达，该方法就无法可靠确认你的 ID：`getUpdates` 最多返回 100 条待处理更新。菜单只显示去重后的 ID，不包含发送者详情。不要凭猜测选择 ID，也不要为了查找 ID 清空更新队列。

查询只发起一次 `getUpdates` 请求，不确认更新，也不把 ID 写入数据库。`identify` 和 `run` 共用非阻塞锁 `var/bot.lock`；如果锁被占用，就不会调用 Bot API。首次确认 ID 只需要令牌和 `polling` 模式。该命令可能创建私有的 `var/` 目录和锁文件，但不会打开 SQLite。如果已注册 Webhook，请先[切换模式](vds-webhook.md#切换模式)。查询逻辑在 [`bin/bot.php`](../../../bin/bot.php) 中。

## 完成设置

准备好[档案和指令](profile.md)，然后[连接 AI](ai-models.md)。启动本地监控，或配置 [Webhook 和 cron](vds-webhook.md)。

查找 ID 时，向机器人发送 `/start` 再检查。监控运行后，机器人会用邀请你转发职位的消息回复该命令。只是以 `/start` 开头的其他消息会被视为授权用户发送的普通职位文本。

## 如何发送职位

- 将职位文本作为普通消息发送给机器人。
- 或把聊天或频道中的职位发布转发给机器人。
- 附件说明中的文本也能被处理；应用不会识别图片和语音消息本身。

机器人会确认保存职位，随后发送评估结果。可阅读[重复投递与转发处理](message-processing.md)以及[如何理解结果](analysis-result.md)。如果没有收到消息，请参阅[故障排查](troubleshooting.md)。

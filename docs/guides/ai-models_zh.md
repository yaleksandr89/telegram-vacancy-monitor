# 连接 AI 模型

## 选择语言

| Русский | English | Español | 中文 | Français | Deutsch |
| --- | --- | --- | --- | --- | --- |
| [Русский](ai-models.md) | [English](ai-models_en.md) | [Español](ai-models_es.md) | **已选择** | [Français](ai-models_fr.md) | [Deutsch](ai-models_de.md) |

[← 返回 README](../readme/README_zh.md)

应用通过 Chat Completions 接口连接本地和云端模型。按照[配置示例](../../config/bot.example.ini)在 `config/bot.ini` 中填写服务器地址和模型标识符。

## API 要求

服务器必须接受发往 `AI_BASE_URL` 加上 `/chat/completions` 的 `POST` 请求，支持 `system` 和 `user` 角色消息，以及 `stream = false` 参数。响应必须包含字符串类型的 `choices[0].message.content`，其中仅包含一个符合应用附加到指令中的 schema 的 JSON 对象。

`message.content` 中接受一个 JSON 对象：可以不带外层包装，也可以使用**唯一一个**标记为 `json` 的 Markdown 代码块。对象前后的额外文字、多余代码块、其他 Markdown 格式和分段回复均不接受。函数调用和工具调用也会被拒绝。Schema 直接包含在请求中，不另行使用 `response_format` 参数。

所需格式见[结果参考文档](../reference/analysis-result_zh.md)。实现代码为 [`OpenAiCompatibleEvaluator`](../../src/Analysis/OpenAiCompatibleEvaluator.php) 和 [`EvaluationPromptBuilder`](../../src/Analysis/EvaluationPromptBuilder.php)。

## 本地模型

启动已加载模型的 API 服务器，然后配置：

```ini
; 本地模型：替换 model-id，必要时修改 API 地址。
AI_PROVIDER_LOCATION = "local"
AI_BASE_URL = "http://127.0.0.1:1234/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

地址和 `model-id` 是占位示例，需要替换。请从自己的服务器获取标识符；模型文件名可能与 API 名称不同。如果服务器需要密钥，请填写 `AI_API_KEY`。

允许 HTTP 和 HTTPS。`local` 模式下，地址验证允许：

| 地址 | 适用场景 |
| --- | --- |
| `localhost`、IPv4 `127.0.0.0/8`、IPv6 `[::1]` | 模型服务器运行在同一台计算机上。 |
| `10.0.0.0/8`、`172.16.0.0/12`、`192.168.0.0/16` | 模型服务器可通过私有 IPv4 网络地址访问。 |

其他主机名、公网 IP 和私有 IPv6 地址不会通过此验证。若模型位于另一台计算机，请使用它的私有 IPv4 地址。在 WSL、虚拟机或 VDS 中，`127.0.0.1` 指向运行 PHP 的环境，而不是桌面电脑。

只允许需要访问的计算机连接 API。应用不会安装模型或开放端口。内存、硬件和上下文长度要求由具体模型决定；上下文必须能够容纳档案、指令、schema 和完整职位文本。

## 云端服务

向所选服务商申请密钥，并从其文档中取得基础 URL 和模型标识符。以下地址仅用于示例：

```ini
; 云端 API：替换地址、模型标识符并设置密钥。
AI_PROVIDER_LOCATION = "cloud"
AI_BASE_URL = "https://api.example.org/v1"
AI_MODEL = "model-id"
AI_API_KEY = ""
```

请替换地址和模型，并在编辑器中填写非空密钥。`cloud` 模式要求 HTTPS 和密钥。URL 不能包含用户名、密码、查询参数或片段。不要把 `/chat/completions` 加到 `AI_BASE_URL` 中。

云端服务会收到用于分析的职位文本、档案和指令。请求内容见 [`EvaluationPromptBuilder::build()`](../../src/Analysis/EvaluationPromptBuilder.php)。

## 档案和评估规则

创建 `config/profile/local/candidate.md` 来描述经验和偏好，在 `config/profile/local/instructions.md` 中编写分析规则。可以从[指令示例](../../config/profile/instructions.example.md)和[档案指南](../../config/profile/README_zh.md)开始。

明确哪些要求是必备的、如何处理缺失信息，以及解释使用何种语言。要求严格按照应用 schema 返回 JSON，不得添加链接、格式或字段。职位文本中的指令不得取代你的评估规则。示例：[Go 开发者](../examples/go-developer_zh.md)和[产品分析师](../examples/analyst_zh.md)。

修改后重启本地监控；服务器上下一次 `work-once` 会加载更改。已保存的评估不会重新计算。

## 超时与重试

```ini
; 包含第一次在内的尝试次数，以及单次 AI 请求的秒数上限。
AI_MAX_ATTEMPTS = "3"
AI_REQUEST_TIMEOUT_SECONDS = "180"
```

`AI_REQUEST_TIMEOUT_SECONDS` 为 30–1800 之间的整数，缺少键时默认为 180 秒。该限制针对每一次请求。连接建立时间最多 5 秒。对于较慢的本地模型，可设为 `300` 或 `600`，随后重启处理。

`AI_MAX_ATTEMPTS` 为 1–5，默认 3，包括首次尝试。分析和结果发送分别计算次数。对较慢模型进行单次检查时，设为 `1` 可以避免失败后的长时间等待。

临时网络故障、请求超时、HTTP 429 和 5xx 响应会触发重试。模型返回的格式无效也可能重试。HTTP 401 等授权错误会终止后续尝试。重试间隔依次为 30、60、120 和 240 秒。重启后仍会考虑已用尝试次数。重试决策由 [`VacancyAnalysisPipeline`](../../src/Application/VacancyAnalysisPipeline.php) 负责，网络限制由 [`CurlAiTransport`](../../src/Analysis/Http/CurlAiTransport.php) 执行。

AI 请求不跟随 HTTP 重定向、不使用代理，并执行 TLS 验证。职位文本上限为 65,536 字节，API 响应体上限为 65,536 字节，构造出的请求上限为 262,144 字节。超大或无效响应会被拒绝。

## 可选的精确排除规则

预过滤器可以不调用模型就跳过预先定义的短消息。

### 单条规则

```ini
; 必须整条消息匹配；存在歧义词时取消拒绝。
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
```

### 多条规则及例外

每条规则占一行：

```ini
; 每条精确短语和消除歧义词分别配置。
AI_PREFILTER_EXACT_REJECT_LINES[] = "Только ночная смена."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Работа только в офисе."
AI_PREFILTER_EXACT_REJECT_LINES[] = "Без удалённого формата."

AI_PREFILTER_AMBIGUITY_TERMS[] = "обсуждается"
AI_PREFILTER_AMBIGUITY_TERMS[] = "возможны исключения"
```

此例中，只有当 `Работа только в офисе.` 构成整条消息时才会被拒绝，而 `Работа только в офисе. Возможны исключения` 会交给模型。如果消息与规则完全匹配，但又包含消除歧义的词，则取消拒绝。

每条规则单独使用一行 `[]`。只有**整条消息**与规则匹配才会拒绝，而不是长篇职位中的某个句子匹配。匹配区分大小写，但忽略首尾空格。多规则说明见[配置参考](../reference/configuration_zh.md#预过滤器)。职位正文中包含子串不会触发拒绝。任何消除歧义词均会取消拒绝，且不区分大小写。带换行的文本也会交给模型。规则是 UTF-8 字面字符串，而非正则表达式。实现见 [`ConservativePrefilter`](../../src/Analysis/ConservativePrefilter.php)。

## 验证连接

先运行本地准备检查，再在监控或 cron 正常运行时向机器人发送一小段虚构职位文本。准备检查不会访问模型服务器，也不能证明密钥有效或所选模型支持要求的 JSON 格式。

检查回答内容：模型是否区分硬性要求与加分项、指出不明确条件并避免捏造经验？发生错误时请参阅[故障排查](troubleshooting_zh.md)；如果评估不理想，请调整档案与指令。

# mercurius-llm

[English](README.md) | [Русский](README.ru.md) | **中文**

调用大语言模型的统一接口：消息、工具、流式输出、token 和成本核算、错误与重试。适配器：OpenRouter、OpenAI API（Chat Completions 和 Responses）、Anthropic Messages API 以及 Amazon Bedrock 中的 Claude；其他提供商以适配器的形式添加，无需改动接口。

[Mercurius](https://github.com/opencck/mercurius) 的一部分，一个自主的个人智能体。该包不依赖 Mercurius 应用，可以单独使用。

**状态：** 早期开发。

**技术栈：** PHP 8.2+、AMPHP v3（`amphp/http-client` 5）。

## 安装

在该包发布到 Packagist 之前，请从 GitHub 安装：

```bash
composer config repositories.mercurius-llm vcs https://github.com/opencck/mercurius-llm
composer require opencck/mercurius-llm:dev-master
```

## 用法

```php
use Amp\Http\Client\HttpClientBuilder;
use Mercurius\Llm\{ChatRequest, Message, RetryingClient, StreamEnd, TextDelta, ToolDefinition};
use Mercurius\Llm\OpenRouter\OpenRouterClient;

$llm = new RetryingClient(new OpenRouterClient(
    HttpClientBuilder::buildDefault(), // any HttpClient: route it through a proxy if needed
    getenv('OPENROUTER_API_KEY'),
    appName: 'My app',
));

$request = new ChatRequest('anthropic/claude-sonnet-4', [
    Message::system('You are a concise assistant.'),
    Message::user('What is the weather in Paris?'),
], tools: [
    new ToolDefinition('weather', 'Current weather in a city', [
        'type' => 'object',
        'properties' => ['city' => ['type' => 'string']],
        'required' => ['city'],
    ]),
], maxTokens: 1024, reasoningEffort: 'low');

// Whole answer at once
$response = $llm->complete($request);
$request = $request->withAddedMessages($response->toMessage());
foreach ($response->toolCalls as $call) {
    $args = $call->arguments(); // ['city' => 'Paris']; throws JsonException on malformed JSON
    $request = $request->withAddedMessages(Message::toolResult($call->id, '18°C, cloudy'));
}

// Streaming: consume to the end or cancel
foreach ($llm->stream($request) as $event) {
    if ($event instanceof TextDelta) {
        echo $event->text;
    } elseif ($event instanceof StreamEnd) {
        $usage = $event->response->usage; // tokens and cost in USD (when OpenRouter reports it)
    }
}
```

这些调用只会阻塞当前的 fiber：请在 Revolt 事件循环中运行它们（`Amp\async()`、AMPHP 服务器、`AsyncTestCase`）。

## 主要接口

| 类型 | 用途 |
|------|---------|
| `LlmClient` | `complete(ChatRequest, ?Cancellation): ChatResponse` 和 `stream(ChatRequest, ?Cancellation): iterable<StreamEvent>` |
| `ChatRequest` | 不可变的请求：模型、消息、工具、`maxTokens`、`temperature`、`reasoningEffort`、`extra`（合并进负载的提供商字段）；`with*()` 方法 |
| `Message`、`Role` | 对话消息：`system()`、`user()`、`assistant($text, $toolCalls)`、`toolResult($callId, $content)` |
| `ToolDefinition`、`ToolCall` | 工具描述（JSON Schema）；模型请求的调用，带原始的 `argumentsJson` 和 `arguments(): array` |
| `ChatResponse` | 文本、工具调用、`FinishReason`、`Usage`、实际使用的模型、提供商 id、推理文本 |
| `Usage` | `inputTokens`（未缓存）、`outputTokens`、`cacheReadTokens`、`cacheWriteTokens`、`reasoningTokens`（输出的一部分）、`costUsd`；`plus()`、`promptTokens()`、`totalTokens()` |
| `StreamEvent` | `TextDelta`、`ReasoningDelta`、`ToolCallDelta`，以及带有组装好的 `ChatResponse` 的最终事件 `StreamEnd` |
| `LlmException`、`ErrorKind` | 已分类的失败（`auth`、`rate_limited`、`bad_request`、`context_length`、`content_filter`、`server`、`timeout`、`network`、`insufficient_credits`、`unknown`）、`retryable()`、`retryAfter`、`status` |
| `RetryingClient` | 装饰器：以带完全抖动的指数退避重试可重试的错误，遵循 `Retry-After`，流在第一个事件之后绝不重试 |
| `OpenRouter\OpenRouterClient` | OpenRouter Chat Completions 适配器：SSE 解析（keep-alive 注释、`[DONE]`、流中途错误、usage 块）、错误映射、超时和大小限制 |
| `OpenAI\OpenAIClient` | 基于同一套协议代码的 OpenAI API Chat Completions 适配器：`max_completion_tokens`、字符串形式的 `reasoning_effort`（请求带工具时为 `none`——Chat Completions 在开启推理时拒绝函数工具）、设置了推理强度时不带 `temperature`、不带 OpenRouter 的归属请求头；该 API 不报告成本，所以 `Usage::$costUsd` 为 null |
| `Anthropic\AnthropicClient` | 基于 AMPHP HTTP 客户端（而非会阻塞的官方 SDK）的 Anthropic Messages API 适配器（`POST /v1/messages`）：密钥放在 `x-api-key`，`anthropic-version: 2026-10-21`；系统消息作为顶层文本块发送，缓存标记变为 `cache_control`，同角色的轮次会被合并以使角色交替，工具结果放在 user 轮次中，图片为 base64 块（视频会得到 `BadRequest`）；推理强度进入 `output_config.effort`，不发送 temperature（该 API 版本会拒绝它）；`max_tokens` 默认为 16000；思考内容作为推理文本返回，且不会随历史一起回传；HTTP 529（过载）和流中的 `error` 事件是可重试的 `Server` 错误；该 API 不报告成本，所以 `Usage::$costUsd` 为 null |
| `AnthropicClient::bedrock()` | Amazon Bedrock 中的 Claude：同一套 Messages API，位于 `https://bedrock-mantle.{region}.api.aws/anthropic`，使用 Bedrock API 密钥（bearer 令牌）而非 SigV4 签名；模型 id 形如 `anthropic.claude-opus-5-5`；区域在成为主机名之前会经过检查 |
| `ModelCatalog\BedrockCatalog` | `fetch(): list<ModelInfo>`：Bedrock 在那里没有模型端点，所以取 OpenRouter 目录中的 Claude 模型，并改写为 Bedrock 的拼写（`anthropic/claude-opus-5.5` 即 `anthropic.claude-opus-5-5`）；每个模型能否访问由 Bedrock 决定 |
| `ModelCatalog\AnthropicCatalog` | `fetch(): list<ModelInfo>`：该密钥可用的模型（`GET /v1/models`），由公开的 OpenRouter 目录进行描述，该目录用点号书写版本（`claude-opus-5-5` 即 `anthropic/claude-opus-5.5`，带日期的 id 查找时去掉日期）；它不认识的模型会被略去 |
| `OpenRouter\ImageClient` | `generate(ImageRequest): ImageResult`：来自 OpenRouter 图像模型的图片（`POST /images`，`response_format: b64_json`——图片随响应返回，而不是会过期的链接）；`ImageRequest` 检查数量（1–10）、宽高比和分辨率档位；`ImageResult` 携带 `MediaPart` 图片、usage 和报告的成本；没有图片即为错误 |
| `OpenAI\ResponsesClient` | OpenAI Responses API 适配器（`POST /v1/responses`），这是 codex 模型和始终进行推理的模型所用的 API，这些模型在此处接受函数工具：系统消息变为 `instructions`，对话以条目（`message`、`function_call`、`function_call_output`）形式发送，图片为 `input_image`，`store: false`，强度放在 `reasoning.effort`（`max` 变为 `xhigh`），`max_output_tokens`；不完整的回答为 `Length` 或 `ContentFilter`；缓存的输入 token 单独成一档；`response.failed` 或 `error` 事件是已分类的错误 |
| `ModelCatalog\OpenAICatalog` | `fetch(): list<ModelInfo>`：该密钥可用的模型（`GET /v1/models`），由公开的 OpenRouter 目录描述（`openai/<id>`）；它不认识的模型（嵌入、语音）会被略去；始终进行推理的模型列出时不带工具。使用 `responses: true` 时——Responses API 的模型：保留 codex 和 `-pro` 系列，始终进行推理的模型保留其工具 |
| `ModelCatalog\OpenRouterCatalog`、`ModelInfo` | 来自 `GET /models` 的 `fetch(): list<ModelInfo>`：上下文长度、每 token 的价格、模态、支持的参数、已发布时的 Artificial Analysis 指数（intelligence、coding、agentic）、`estimateCost(Usage)` |

说明：

- 客户端接受任意 `Amp\Http\Client\HttpClient`，因此连接可以经过代理或自定义连接器；该包本身不做任何网络方面的决定。
- 错误中绝不包含 API 密钥或请求体；提供商的消息会被截断到 500 个字符。
- 在第一个数据事件之前超过 `firstEventTimeout`（240 秒）、或事件之间超过 `eventTimeout`（120 秒）没有发送任何数据事件的流，会以可重试的 `Timeout` 失败；keep-alive 注释不计入，因此一个让连接保持忙碌却不作答的提供商，无法让一次调用占满整个传输超时。
- `stream()` 在迭代开始时才发送请求。丢弃迭代器或取消会关闭响应体。

## 开发

```bash
composer install
composer check              # unit tests, PHPStan (max), Psalm (level 1)
composer test-integration   # real OpenRouter call; needs OPENROUTER_API_KEY, optional OPENROUTER_TEST_MODEL
```

单元测试不需要网络：HTTP 被伪造的 `DelegateHttpClient` 取代。

## 许可证

[MIT](LICENSE)

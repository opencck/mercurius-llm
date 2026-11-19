# mercurius-llm

**English** | [Русский](README.ru.md)

A unified interface for calling large language models: messages, tools, streaming, token and cost accounting, errors and retries. Adapters: OpenRouter, the OpenAI API, the Anthropic Messages API and Claude in Amazon Bedrock; other providers are added as adapters without changing the interface.

Part of [Mercurius](https://github.com/opencck/mercurius), an autonomous personal agent. The package does not depend on the Mercurius application and can be used on its own.

**Status:** early development.

**Stack:** PHP 8.2+, AMPHP v3 (`amphp/http-client` 5).

## Installation

Until the package is published on Packagist, install it from GitHub:

```bash
composer config repositories.mercurius-llm vcs https://github.com/opencck/mercurius-llm
composer require opencck/mercurius-llm:dev-master
```

## Usage

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

The calls block only the current fiber: run them inside the Revolt event loop (`Amp\async()`, an AMPHP server, `AsyncTestCase`).

## Main interfaces

| Type | Purpose |
|------|---------|
| `LlmClient` | `complete(ChatRequest, ?Cancellation): ChatResponse` and `stream(ChatRequest, ?Cancellation): iterable<StreamEvent>` |
| `ChatRequest` | Immutable request: model, messages, tools, `maxTokens`, `temperature`, `reasoningEffort`, `extra` (provider fields merged into the payload); `with*()` methods |
| `Message`, `Role` | Conversation message: `system()`, `user()`, `assistant($text, $toolCalls)`, `toolResult($callId, $content)` |
| `ToolDefinition`, `ToolCall` | Tool description (JSON Schema); a call requested by the model with raw `argumentsJson` and `arguments(): array` |
| `ChatResponse` | Text, tool calls, `FinishReason`, `Usage`, model actually used, provider id, reasoning text |
| `Usage` | `inputTokens` (uncached), `outputTokens`, `cacheReadTokens`, `cacheWriteTokens`, `reasoningTokens` (part of output), `costUsd`; `plus()`, `promptTokens()`, `totalTokens()` |
| `StreamEvent` | `TextDelta`, `ReasoningDelta`, `ToolCallDelta`, and the final `StreamEnd` with the assembled `ChatResponse` |
| `LlmException`, `ErrorKind` | Classified failure (`auth`, `rate_limited`, `bad_request`, `context_length`, `content_filter`, `server`, `timeout`, `network`, `insufficient_credits`, `unknown`), `retryable()`, `retryAfter`, `status` |
| `RetryingClient` | Decorator: retries retryable errors with exponential backoff and full jitter, honours `Retry-After`, never retries a stream after its first event |
| `OpenRouter\OpenRouterClient` | OpenRouter Chat Completions adapter: SSE parsing (keep-alive comments, `[DONE]`, mid-stream errors, usage chunk), error mapping, timeouts and size limits |
| `OpenAI\OpenAIClient` | OpenAI API Chat Completions adapter on the same protocol code: `max_completion_tokens`, a `reasoning_effort` string (`none` when the request has tools — Chat Completions refuses function tools with reasoning on), no `temperature` when a reasoning effort is set, no OpenRouter attribution headers; the API reports no cost, so `Usage::$costUsd` is null |
| `Anthropic\AnthropicClient` | Anthropic Messages API adapter (`POST /v1/messages`) over the AMPHP HTTP client, not the blocking official SDK: the key in `x-api-key`, `anthropic-version: 2026-10-21`; system messages go as top-level text blocks, a cache mark becomes `cache_control`, same-role turns merge so the roles alternate, tool results go in the user turn, pictures as base64 blocks (a video is a `BadRequest`); the reasoning effort goes to `output_config.effort`, the temperature is not sent (that API version refuses it); `max_tokens` defaults to 16000; thinking comes back as reasoning text and is not sent back with the history; HTTP 529 (overloaded) and an `error` stream event are retryable `Server` errors; the API reports no cost, so `Usage::$costUsd` is null |
| `AnthropicClient::bedrock()` | Claude in Amazon Bedrock: the same Messages API at `https://bedrock-mantle.{region}.api.aws/anthropic` with a Bedrock API key (bearer token) instead of SigV4 signing; model ids like `anthropic.claude-opus-5-5`; the region is checked before it becomes a host name |
| `ModelCatalog\BedrockCatalog` | `fetch(): list<ModelInfo>`: Bedrock has no models endpoint there, so the Claude models of the OpenRouter catalog in Bedrock spelling (`anthropic/claude-opus-5.5` is `anthropic.claude-opus-5-5`); access to each is decided by Bedrock |
| `ModelCatalog\AnthropicCatalog` | `fetch(): list<ModelInfo>`: the models of the key (`GET /v1/models`) described by the public OpenRouter catalog, which spells versions with dots (`claude-opus-5-5` is `anthropic/claude-opus-5.5`, a dated id is looked up without the date); models it does not know are left out |
| `ModelCatalog\OpenAICatalog` | `fetch(): list<ModelInfo>`: the models of the key (`GET /v1/models`) described by the public OpenRouter catalog (`openai/<id>`); models it does not know (embeddings, speech) are left out; a model that always reasons is listed without tools |
| `ModelCatalog\OpenRouterCatalog`, `ModelInfo` | `fetch(): list<ModelInfo>` from `GET /models`: context length, prices per token, modalities, supported parameters, Artificial Analysis indices (intelligence, coding, agentic) when published, `estimateCost(Usage)` |

Notes:

- The client takes any `Amp\Http\Client\HttpClient`, so connections can go through a proxy or a custom connector; the package itself makes no network decisions.
- Errors never contain the API key or the request body; provider messages are truncated to 500 characters.
- A stream that sends no data event for `firstEventTimeout` (240 s) before the first one, or `eventTimeout` (120 s) between them, fails with a retryable `Timeout`; keep-alive comments do not count, so a provider that keeps the connection busy without answering cannot hold a call for the whole transfer timeout.
- `stream()` sends the request when iteration starts. Dropping the iterator or cancelling closes the response body.

## Development

```bash
composer install
composer check              # unit tests, PHPStan (max), Psalm (level 1)
composer test-integration   # real OpenRouter call; needs OPENROUTER_API_KEY, optional OPENROUTER_TEST_MODEL
```

Unit tests need no network: HTTP is replaced with a fake `DelegateHttpClient`.

## License

[MIT](LICENSE)

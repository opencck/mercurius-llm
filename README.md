# mercurius-llm

**English** | [Русский](README.ru.md)

A unified interface for calling large language models: messages, tools, streaming, token and cost accounting, errors and retries. The first adapter is OpenRouter; other providers are added as adapters without changing the interface.

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
| `ModelCatalog\OpenRouterCatalog`, `ModelInfo` | `fetch(): list<ModelInfo>` from `GET /models`: context length, prices per token, modalities, supported parameters, `estimateCost(Usage)` |

Notes:

- The client takes any `Amp\Http\Client\HttpClient`, so connections can go through a proxy or a custom connector; the package itself makes no network decisions.
- Errors never contain the API key or the request body; provider messages are truncated to 500 characters.
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

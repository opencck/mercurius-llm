# mercurius-llm

[English](README.md) | **Русский**

Единый интерфейс вызова больших языковых моделей: сообщения, инструменты, потоковый ответ, учёт токенов и стоимости, ошибки и повторы. Адаптеры: OpenRouter и OpenAI API; другие провайдеры добавляются адаптерами без изменения интерфейса.

Часть проекта [Меркурий (Mercurius)](https://github.com/opencck/mercurius) — автономного персонального агента. Пакет не зависит от приложения Меркурия и может использоваться отдельно.

**Статус:** ранняя разработка.

**Стек:** PHP 8.2+, AMPHP v3 (`amphp/http-client` 5).

## Установка

Пока пакет не опубликован на Packagist, ставьте его из GitHub:

```bash
composer config repositories.mercurius-llm vcs https://github.com/opencck/mercurius-llm
composer require opencck/mercurius-llm:dev-master
```

## Использование

```php
use Amp\Http\Client\HttpClientBuilder;
use Mercurius\Llm\{ChatRequest, Message, RetryingClient, StreamEnd, TextDelta, ToolDefinition};
use Mercurius\Llm\OpenRouter\OpenRouterClient;

$llm = new RetryingClient(new OpenRouterClient(
    HttpClientBuilder::buildDefault(), // любой HttpClient: при необходимости — через прокси
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

// Ответ целиком
$response = $llm->complete($request);
$request = $request->withAddedMessages($response->toMessage());
foreach ($response->toolCalls as $call) {
    $args = $call->arguments(); // ['city' => 'Paris']; JsonException при битом JSON
    $request = $request->withAddedMessages(Message::toolResult($call->id, '18°C, cloudy'));
}

// Поток: дочитать до конца или отменить
foreach ($llm->stream($request) as $event) {
    if ($event instanceof TextDelta) {
        echo $event->text;
    } elseif ($event instanceof StreamEnd) {
        $usage = $event->response->usage; // токены и стоимость в USD (если OpenRouter её сообщил)
    }
}
```

Вызовы блокируют только текущий файбер: запускайте их внутри цикла событий Revolt (`Amp\async()`, сервер AMPHP, `AsyncTestCase`).

## Основные интерфейсы

| Тип | Назначение |
|-----|------------|
| `LlmClient` | `complete(ChatRequest, ?Cancellation): ChatResponse` и `stream(ChatRequest, ?Cancellation): iterable<StreamEvent>` |
| `ChatRequest` | Неизменяемый запрос: модель, сообщения, инструменты, `maxTokens`, `temperature`, `reasoningEffort`, `extra` (поля провайдера, вливаются в тело запроса); методы `with*()` |
| `Message`, `Role` | Сообщение диалога: `system()`, `user()`, `assistant($text, $toolCalls)`, `toolResult($callId, $content)` |
| `ToolDefinition`, `ToolCall` | Описание инструмента (JSON Schema); вызов, запрошенный моделью, с исходной строкой `argumentsJson` и `arguments(): array` |
| `ChatResponse` | Текст, вызовы инструментов, `FinishReason`, `Usage`, фактическая модель, id у провайдера, текст рассуждений |
| `Usage` | `inputTokens` (без кеша), `outputTokens`, `cacheReadTokens`, `cacheWriteTokens`, `reasoningTokens` (часть выходных), `costUsd`; `plus()`, `promptTokens()`, `totalTokens()` |
| `StreamEvent` | `TextDelta`, `ReasoningDelta`, `ToolCallDelta` и финальный `StreamEnd` с собранным `ChatResponse` |
| `LlmException`, `ErrorKind` | Классифицированная ошибка (`auth`, `rate_limited`, `bad_request`, `context_length`, `content_filter`, `server`, `timeout`, `network`, `insufficient_credits`, `unknown`), `retryable()`, `retryAfter`, `status` |
| `RetryingClient` | Декоратор: повторяет повторяемые ошибки с экспоненциальной задержкой и полным джиттером, учитывает `Retry-After`, не повторяет поток после первого события |
| `OpenRouter\OpenRouterClient` | Адаптер OpenRouter Chat Completions: разбор SSE (keep-alive комментарии, `[DONE]`, ошибки посреди потока, чанк с usage), классификация ошибок, таймауты и лимиты размера |
| `OpenAI\OpenAIClient` | Адаптер OpenAI API Chat Completions на том же коде протокола: `max_completion_tokens`, `reasoning_effort` строкой, без `temperature`, когда задано усилие рассуждения, без заголовков атрибуции OpenRouter; стоимость API не сообщает, поэтому `Usage::$costUsd` — null |
| `ModelCatalog\OpenAICatalog` | `fetch(): list<ModelInfo>`: модели ключа (`GET /v1/models`), описанные открытым каталогом OpenRouter (`openai/<id>`); модели, которых там нет (эмбеддинги, речь), не попадают |
| `ModelCatalog\OpenRouterCatalog`, `ModelInfo` | `fetch(): list<ModelInfo>` из `GET /models`: размер контекста, цены за токен, модальности, поддерживаемые параметры, индексы Artificial Analysis (интеллект, код, агентность), если опубликованы, `estimateCost(Usage)` |

Замечания:

- Клиент принимает любой `Amp\Http\Client\HttpClient`, поэтому соединения можно пустить через прокси или свой коннектор; сам пакет сетевых решений не принимает.
- В ошибках никогда нет API-ключа и тела запроса; сообщения провайдера обрезаются до 500 символов.
- Поток без событий с данными дольше `firstEventTimeout` (240 с) до первого или `eventTimeout` (120 с) между ними завершается повторяемой ошибкой `Timeout`; keep-alive-комментарии не в счёт, поэтому провайдер, который держит соединение и молчит, не задерживает вызов на весь таймаут передачи.
- `stream()` отправляет запрос, когда начинается перебор. Если бросить итератор или отменить запрос, тело ответа закрывается.

## Разработка

```bash
composer install
composer check              # юнит-тесты, PHPStan (max), Psalm (уровень 1)
composer test-integration   # реальный вызов OpenRouter; нужен OPENROUTER_API_KEY, модель — OPENROUTER_TEST_MODEL
```

Юнит-тестам сеть не нужна: HTTP подменяется фейковым `DelegateHttpClient`.

## Лицензия

[MIT](LICENSE)

# mercurius-llm

[English](README.md) | **Русский** | [中文](README.cn.md)

Единый интерфейс вызова больших языковых моделей: сообщения, инструменты, потоковый ответ, учёт токенов и стоимости, ошибки и повторы. Адаптеры: OpenRouter, OpenAI API (Chat Completions и Responses), Anthropic Messages API, Claude в Amazon Bedrock, Gemini API и Gemini в Google Cloud (Vertex); другие провайдеры добавляются адаптерами без изменения интерфейса.

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
| `OpenAI\OpenAIClient` | Адаптер OpenAI API Chat Completions на том же коде протокола: `max_completion_tokens`, `reasoning_effort` строкой (`none`, если в запросе есть инструменты: Chat Completions не принимает инструменты при включённом рассуждении), без `temperature`, когда задано усилие рассуждения, без заголовков атрибуции OpenRouter; стоимость API не сообщает, поэтому `Usage::$costUsd` — null. По модели: адаптивное рассуждение с показом сводки (`thinking: {type: adaptive, display: summarized}`) там, где оно есть, отключение — там, где модель его принимает, усилие, которого у модели нет, срезается, для Haiku 4.5 и старше усилие не шлётся. Поток просит аргументы инструментов по мере написания (`eager_input_streaming`) и проверяет их: вызов с неразбираемыми аргументами не возвращается, ответ кончается как оборванный по длине. Отказ несёт `refusalCategory` и `refusalExplanation` и не несёт вызовов; токены рассуждения идут в `Usage::$reasoningTokens`; идентификатор запроса — в `ChatResponse::$requestId` и `LlmException::$requestId`. 429 исчерпанного окна несёт `LlmException::$resetsAt` и не повторяется в `RetryingClient`. Конструктор принимает имя, которым называются ошибки (`name:`): один класс служит Anthropic, Bedrock и шлюзу, говорящему по Messages API |
| `Anthropic\AnthropicClient` | Адаптер Anthropic Messages API (`POST /v1/messages`) на HTTP-клиенте AMPHP, а не на блокирующем официальном SDK: ключ в `x-api-key`, `anthropic-version: 2026-10-21`; системные сообщения — текстовыми блоками верхнего уровня, метка кеша — `cache_control`, соседние реплики одной роли сливаются, чтобы роли чередовались, результаты инструментов — в реплике пользователя, картинки — блоками base64 (видео — `BadRequest`); усилие рассуждения — в `output_config.effort`, температура не отправляется (эта версия API её не принимает); `max_tokens` по умолчанию 16000; рассуждение приходит текстом и с историей обратно не отправляется; HTTP 529 (перегрузка) и событие `error` в потоке — повторяемые ошибки `Server`; стоимость API не сообщает, поэтому `Usage::$costUsd` — null |
| `AnthropicClient::bedrock()` | Claude в Amazon Bedrock: тот же Messages API по адресу `https://bedrock-mantle.{region}.api.aws/anthropic` с ключом Bedrock API (токен на предъявителя) вместо подписи SigV4; модели вида `anthropic.claude-opus-5-5`; регион проверяется, прежде чем стать именем хоста |
| `ModelCatalog\BedrockCatalog` | `fetch(): list<ModelInfo>`: адреса списка моделей у Bedrock здесь нет, поэтому это модели Claude из каталога OpenRouter в написании Bedrock (`anthropic/claude-opus-5.5` — это `anthropic.claude-opus-5-5`); доступ к каждой решает Bedrock |
| `Gemini\GeminiClient` | Gemini API по ключу Google AI Studio через адрес, совместимый с OpenAI (`https://generativelanguage.googleapis.com/v1beta/openai`): мышление — в `extra_body.google.thinking_config` с уровнем, который принимает семейство (сводки мыслей не запрашиваются), предел ответа поднимается, когда мышление запрошено, `temperature` не шлётся моделям Gemini 3.6 и новее, схемы инструментов очищаются от списков типов и ветвей null, подпись мышления у вызова инструмента хранится в `ToolCall::$signature` и возвращается в запросе (вызов, сделанный другим провайдером, получает заглушку) |
| `Gemini\VertexClient`, `Gemini\VertexAccount` | Gemini в Google Cloud (Gemini Enterprise Agent Platform, бывший Vertex AI) через адрес, совместимый с OpenAI, с теми же правилами запроса; модели называются `google/gemini-…`. `VertexAccount::fromJson()` принимает файл ключа сервисного аккаунта, подписывает JWT (RS256, `ext-openssl`) и меняет его на часовой токен, не блокируя цикл событий: токен обновляется за пять минут до истечения, одновременные вызовы делят один обмен |
| `ModelCatalog\GeminiCatalog`, `ModelCatalog\VertexCatalog` | `fetch(): list<ModelInfo>`: текстовые модели ключа Gemini (`GET …/openai/models`), описанные каталогом OpenRouter; у Vertex списка по этому адресу нет, поэтому его каталог — текстовые модели Gemini из каталога OpenRouter (написание то же). Цены базовые: повышенная цена Google для запросов длиннее 200 тысяч токенов не учитывается |
| `Antigravity\AntigravityClient` | Клиент сервиса `mercurius-antigravity` (модели плана Google Antigravity через официальный CLI в своём сервисе) на общем Chat Completions: обычное тело с `reasoning.effort`, длинные таймауты потока; 429 исчерпанного окна несёт `resetsAt`, 422 нарушения правил вызова не повторяется |
| `ModelCatalog\AnthropicCatalog` | `fetch(): list<ModelInfo>`: модели ключа (`GET /v1/models`), описанные открытым каталогом OpenRouter, где версии пишутся через точку (`claude-opus-5-5` — это `anthropic/claude-opus-5.5`, идентификатор с датой ищется без даты); модели, которых там нет, не попадают |
| `OpenRouter\ImageClient` | `generate(ImageRequest): ImageResult`: картинки от моделей изображений OpenRouter (`POST /images`, `response_format: b64_json` — картинки приходят в ответе, а не ссылками, которые истекают); `ImageRequest` проверяет число (1–10), соотношение сторон и разрешение; `ImageResult` — картинки `MediaPart`, учёт токенов и цена от провайдера; ни одной картинки — ошибка |
| `OpenAI\ResponsesClient` | Адаптер OpenAI Responses API (`POST /v1/responses`) — API моделей codex и моделей, которые рассуждают всегда: инструменты-функции они принимают здесь. Системные сообщения — `instructions`, беседа — элементами (`message`, `function_call`, `function_call_output`), картинки — `input_image`, `store: false`, усилие — `reasoning.effort` (`max` становится `xhigh`), `max_output_tokens`; неполный ответ — `Length` или `ContentFilter`; кешированные входные токены — отдельной корзиной; событие `response.failed` или `error` — классифицированная ошибка |
| `ModelCatalog\OpenAICatalog` | `fetch(): list<ModelInfo>`: модели ключа (`GET /v1/models`), описанные открытым каталогом OpenRouter (`openai/<id>`); модели, которых там нет (эмбеддинги, речь), не попадают; модель, которая рассуждает всегда, — без инструментов. С `responses: true` — модели Responses API: семейства codex и `-pro` остаются, модель, которая рассуждает всегда, — с инструментами |
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

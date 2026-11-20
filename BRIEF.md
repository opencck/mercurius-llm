# BRIEF — mercurius-llm

Единый асинхронный интерфейс вызова больших языковых моделей на AMPHP v3: сообщения, инструменты, потоковый ответ,
учёт токенов и стоимости, классифицированные ошибки и повторы. Адаптеры: OpenRouter, OpenAI (Chat Completions и
Responses API), Anthropic Messages API и Claude в Amazon Bedrock; каталоги моделей этих провайдеров; генерация картинок
через OpenRouter. Пакет — часть Меркурия (`opencck/mercurius`), подключается сабмодулем в `packages/llm/` и не зависит
от приложения: годится любому PHP-приложению на цикле событий Revolt.

## Назначение и границы

В пакете:

- интерфейс `LlmClient` и неизменяемые типы запроса, ответа, сообщений, инструментов, событий потока и расхода;
- адаптеры провайдеров: тело запроса, разбор ответа и потока, классификация ошибок, таймауты и пределы размера;
- декоратор повторов `RetryingClient`;
- чтение каталогов моделей (контекст, цены, модальности, параметры, индексы) и оценка стоимости по ценам каталога;
- клиент картинок OpenRouter.

В приложении остаётся:

- выбор сети: пакет принимает любой `Amp\Http\Client\HttpClient`, а VLESS или прокси подставляет приложение
  (пакет `connectors`);
- ссылки моделей с приставкой провайдера (`openrouter:`, `openai:`, `codex:`, `anthropic:`, `bedrock:`), профили
  провайдеров, роли моделей, выбор по профилю задачи, каскад, бюджет запуска;
- запись расхода (`llm_calls`), хранение каталога (`model_catalog`), трассы;
- провайдер `chatgpt:` (подписка ChatGPT): `CodexClient`, `CodexCatalog`, `CodexAccount` лежат в
  `src/Infrastructure/Llm/ChatGpt/` приложения, потому что им нужны хранилище секретов и вход владельца.

## Устройство

| Класс / каталог | Что делает |
|-----------------|------------|
| `LlmClient` | `complete(ChatRequest, ?Cancellation): ChatResponse` и `stream(...)`: события потока `StreamEvent` |
| `ChatRequest` | Неизменяемый запрос: модель, сообщения, инструменты, `maxTokens`, `temperature`, `reasoningEffort`, `extra`; методы `with*()` |
| `Message`, `Role`, `MediaPart` | Сообщение (`system()`, `user()` с картинками и видео, `assistant()`, `toolResult()`, метка кеша `cached()`); вложение `image()`/`video()` с `dataUrl()` |
| `ToolDefinition`, `ToolCall` | Инструмент с JSON Schema; вызов модели с исходной строкой аргументов и `arguments()` |
| `ChatResponse`, `FinishReason` | Текст, вызовы инструментов, причина завершения, `Usage`, фактическая модель, id у провайдера, рассуждение; `toMessage()` |
| `Usage` | Токены по корзинам (вход без кеша, выход, чтение и запись кеша, рассуждение) и `costUsd`; `plus()`, `totalTokens()` |
| `StreamEvent`: `TextDelta`, `ReasoningDelta`, `ToolCallDelta`, `StreamEnd` | События потока; `StreamEnd` несёт собранный `ChatResponse` |
| `LlmException`, `ErrorKind` | Ошибка с видом (`auth`, `rate_limited`, `bad_request`, `context_length`, `content_filter`, `server`, `timeout`, `network`, `insufficient_credits`, `unknown`), `retryable()`, `retryAfter`, `status` |
| `RetryingClient` | Декоратор: 3 попытки, экспоненциальная задержка с полным джиттером (1–30 с), `Retry-After` до 60 с, поток не повторяется после первого события; `onRetry` для журнала |
| `ImageRequest`, `ImageResult` | Запрос картинок (число 1–10, соотношение сторон, разрешение) и результат: картинки `MediaPart`, `Usage`, модель |
| `OpenRouter/OpenRouterClient` | Адаптер OpenRouter Chat Completions; заголовки атрибуции `X-Title` и `HTTP-Referer` |
| `OpenRouter/ChatCompletions`, `Dialect` | Общий протокол Chat Completions для OpenRouter и OpenAI; диалект задаёт отличия тела запроса (`@internal`) |
| `OpenRouter/HttpTransport` | Запрос с ключом, таймауты соединения, тишины и передачи, предел тела ответа, разбор JSON (`@internal`) |
| `OpenRouter/SseParser`, `EventStream`, `StreamAccumulator` | Разбор SSE (keep-alive, `[DONE]`, предел события 4 МБ), таймауты первого и следующих событий, сборка ответа из дельт |
| `OpenRouter/PayloadMapper`, `ErrorClassifier`, `Json` | Тело запроса и разбор ответа; классификация HTTP-статусов и ошибок потока; безопасное чтение полей JSON |
| `OpenRouter/ImageClient` | `generate(ImageRequest)`: `POST /images` с `b64_json` |
| `OpenAI/OpenAIClient` | Адаптер OpenAI Chat Completions на общем коде (`Dialect::OpenAI`) |
| `OpenAI/ResponsesClient`, `ResponsesMapper`, `ResponsesAccumulator` | Адаптер OpenAI Responses API (`POST /v1/responses`, `store: false`) для моделей codex, `-pro` и моделей, которые рассуждают всегда |
| `Anthropic/AnthropicClient`, `MessagesMapper`, `MessagesAccumulator` | Адаптер Anthropic Messages API; `AnthropicClient::bedrock()` — тот же API в Bedrock с ключом-токеном и проверкой региона |
| `ModelCatalog/OpenRouterCatalog`, `ModelInfo` | `GET /models` → `ModelInfo` (контекст, цены, модальности, параметры, индексы, `estimateCost()`); `toolReliable()` — модели с надёжным вызовом инструментов |
| `ModelCatalog/OpenAICatalog`, `AnthropicCatalog`, `BedrockCatalog` | Модели ключа провайдера, описанные записями открытого каталога OpenRouter; у Bedrock — модели Claude из каталога OpenRouter в написании Bedrock |

## Решения

- **Один интерфейс на все API.** Ядро Меркурия знает только `LlmClient`; новый провайдер — новый адаптер без правки
  ядра (обязательная абстракция модели).
- **Ошибки типизированы по виду, а не по провайдеру.** Повтор, каскад и запасная модель решаются по `ErrorKind`
  одинаково для всех адаптеров.
- **Повторы — отдельным декоратором.** Адаптеры не повторяют сами; `RetryingClient` можно настроить или не ставить.
- **Поток не повторяется после первого события:** вызывающий уже показал часть ответа, повтор её бы задвоил.
- **Таймауты потока по событиям с данными** (240 с до первого, 120 с между ними), keep-alive не в счёт: молчащий
  провайдер не держит вызов до таймаута передачи.
- **Пределы размера** у тела ответа (32 МБ), события SSE (4 МБ) и тела ошибки (64 КБ): ответ провайдера — недоверенные данные.
- **Секреты не утекают:** ключ — `#[\SensitiveParameter]`, в ошибках нет ключа и тела запроса, сообщение провайдера
  обрезается до 500 символов.
- **Сеть выбирает вызывающий:** клиент принимает любой `HttpClient`, поэтому VLESS и прокси не нужны пакету.
- **Anthropic — на HTTP-клиенте AMPHP, а не на официальном SDK:** SDK блокирует цикл событий.
- **Bedrock — по ключу Bedrock API, без SigV4:** подпись требует AWS SDK; регион проверяется по форме до того, как стать
  именем хоста.
- **Каталоги прямых провайдеров описываются каталогом OpenRouter:** без цен и признаков модель нельзя выбрать по
  профилю, поэтому модели без записи там не попадают в каталог.
- **Стоимость — только от провайдера:** OpenAI и Anthropic её не сообщают, `costUsd` — null, оценку делает
  приложение по `ModelInfo::estimateCost()`.
- **Отличия от hermes отмечены ссылками `ref:`** в коде (слияние ролей Anthropic, классификатор ошибок, метки кеша,
  Responses API).

## Проверки

- `composer check` — PHPUnit, PHPStan (уровень `max`), Psalm (`errorLevel="1"`); юнит-тестам сеть не нужна, HTTP
  подменяется фейковым клиентом.
- `composer test-integration` — группа `integration`: настоящий вызов OpenRouter с `OPENROUTER_API_KEY`; запускается
  на сервере проекта, не в CI.
- CI (`.github/workflows/ci.yml`): одна задача, PHP 8.2 (другая версия — вручную через `workflow_dispatch`), push и
  pull request в `master`, изменения только в `*.md` не запускают, `cancel-in-progress`, кеш Composer и анализаторов,
  10 минут; без сети и секретов — группа `integration` в CI не входит.

## Связь с Меркурием

- [Абстракции, обязательные с начала](../../brief/requirements.md#абстракции-обязательные-с-начала) — зачем единый интерфейс модели.
- [Стек](../../brief/requirements.md#стек) — сеть к модели через VLESS.
- [Как сделано: канал, модель и участники](../../brief/architecture.md#как-сделано-канал-модель-и-участники) — пакет в ядре, повторы, учёт расхода.
- [Провайдеры моделей: OpenRouter и OpenAI](../../brief/models.md#провайдеры-моделей-openrouter-и-openai) — OpenAI, Responses, Anthropic, Bedrock, профиль провайдера.
- [Как сделано: провайдер OpenAI](../../brief/models.md#как-сделано-провайдер-openai) — что найдено ревью адаптера.
- [Codex по подписке ChatGPT](../../brief/models.md#codex-по-подписке-chatgpt) — провайдер `chatgpt:` в приложении.
- [Надёжность инструментов и решения о модели](../../brief/models.md#надёжность-инструментов-и-решения-о-модели) — `toolReliable()`.
- [Выбор моделей под задачу](../../brief/models.md#выбор-моделей-под-задачу) — как приложение пользуется каталогом.
- [Качество кода и CI](../../brief/requirements.md#качество-кода-и-ci) — общие правила проверок.

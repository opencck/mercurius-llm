<?php

declare(strict_types=1);

namespace Mercurius\Llm\Test\Support;

use Amp\Cancellation;
use Mercurius\Llm\ChatRequest;
use Mercurius\Llm\ChatResponse;
use Mercurius\Llm\LlmClient;
use Mercurius\Llm\LlmException;
use Mercurius\Llm\StreamEvent;

/**
 * Scripted client: every call consumes the next outcome — a response, an exception, or a list of stream events
 * optionally ending with an exception.
 */
final class FakeLlmClient implements LlmClient
{
    public int $calls = 0;

    /**
     * @param list<ChatResponse|LlmException|list<StreamEvent|LlmException>> $outcomes
     */
    public function __construct(private array $outcomes)
    {
    }

    #[\Override]
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse
    {
        $outcome = $this->next();
        if ($outcome instanceof LlmException) {
            throw $outcome;
        }
        if (!$outcome instanceof ChatResponse) {
            throw new \LogicException('Expected a response outcome');
        }

        return $outcome;
    }

    /**
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    #[\Override]
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): \Generator
    {
        $outcome = $this->next();
        if ($outcome instanceof LlmException) {
            throw $outcome;
        }
        if (!\is_array($outcome)) {
            throw new \LogicException('Expected a stream outcome');
        }
        foreach ($outcome as $item) {
            if ($item instanceof LlmException) {
                throw $item;
            }
            yield $item;
        }
    }

    /**
     * @return ChatResponse|LlmException|list<StreamEvent|LlmException>
     */
    private function next(): ChatResponse|LlmException|array
    {
        $this->calls++;
        $outcome = \array_shift($this->outcomes);
        if ($outcome === null) {
            throw new \LogicException('No outcome scripted');
        }

        return $outcome;
    }
}

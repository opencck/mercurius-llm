<?php

declare(strict_types=1);

namespace Mercurius\Llm;

use Amp\Cancellation;
use Amp\CancelledException;

/**
 * A chat model behind any provider.
 */
interface LlmClient
{
    /**
     * Sends the request and waits for the complete answer.
     *
     * @throws LlmException
     * @throws CancelledException When the cancellation was requested.
     */
    public function complete(ChatRequest $request, ?Cancellation $cancellation = null): ChatResponse;

    /**
     * Sends the request and yields the answer as it is generated.
     *
     * The request is sent when iteration starts. Deltas ({@see TextDelta}, {@see ReasoningDelta},
     * {@see ToolCallDelta}) are followed by exactly one {@see StreamEnd} carrying the assembled response with tool
     * calls and usage; it is always the last event.
     *
     * The caller must either consume the iterable to the end or cancel the cancellation (or drop the iterator):
     * the underlying connection stays busy until then.
     *
     * @return iterable<int, StreamEvent>
     * @throws LlmException While iterating, including errors reported in the middle of the stream.
     * @throws CancelledException When the cancellation was requested.
     */
    public function stream(ChatRequest $request, ?Cancellation $cancellation = null): iterable;
}

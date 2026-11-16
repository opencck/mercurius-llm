<?php

declare(strict_types=1);

namespace Mercurius\Llm\OpenRouter;

use Mercurius\Llm\ErrorKind;
use Mercurius\Llm\LlmException;

/**
 * Incremental Server-Sent Events parser: bytes in, `data` payloads out.
 *
 * Follows the SSE line rules that matter for chat streams: lines end with LF, CRLF or CR; lines starting with ":"
 * are comments (OpenRouter sends `: OPENROUTER PROCESSING` as keep-alive) and are skipped; several `data:` lines
 * of one event are joined with "\n"; an empty line dispatches the event. Other fields (`event`, `id`, `retry`) are
 * ignored.
 *
 * @internal
 */
final class SseParser
{
    private string $buffer = '';

    /** @var list<string> */
    private array $data = [];

    private int $eventSize = 0;

    public function __construct(private readonly int $maxEventBytes = 4 * 1024 * 1024)
    {
    }

    /**
     * Feeds a chunk of the body and returns the payloads of the events it completed.
     *
     * @return list<string>
     * @throws LlmException When an event exceeds the size limit.
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (true) {
            $length = \strcspn($this->buffer, "\r\n");
            if ($length === \strlen($this->buffer)) {
                break; // no complete line yet
            }
            // A CR at the very end may be the first half of CRLF: wait for the next chunk.
            if ($this->buffer[$length] === "\r" && $length + 1 === \strlen($this->buffer)) {
                break;
            }
            $line = \substr($this->buffer, 0, $length);
            $terminator = \substr($this->buffer, $length, 2) === "\r\n" ? 2 : 1;
            $this->buffer = \substr($this->buffer, $length + $terminator);

            $event = $this->line($line);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        if (\strlen($this->buffer) + $this->eventSize > $this->maxEventBytes) {
            throw new LlmException(ErrorKind::Unknown, 'Stream event exceeds the size limit');
        }

        return $events;
    }

    /**
     * Signals the end of the body and returns the payload of a trailing event without a final empty line.
     */
    public function finish(): ?string
    {
        $event = null;
        if ($this->buffer !== '') {
            $event = $this->line(\rtrim($this->buffer, "\r"));
            $this->buffer = '';
        }

        return $event ?? $this->line('');
    }

    private function line(string $line): ?string
    {
        if ($line === '') {
            if ($this->data === []) {
                return null;
            }
            $event = \implode("\n", $this->data);
            $this->data = [];
            $this->eventSize = 0;

            return $event;
        }
        if ($line[0] === ':') {
            return null;
        }

        $colon = \strpos($line, ':');
        $field = $colon === false ? $line : \substr($line, 0, $colon);
        $value = $colon === false ? '' : \substr($line, $colon + 1);
        if ($value !== '' && $value[0] === ' ') {
            $value = \substr($value, 1);
        }

        if ($field === 'data') {
            $this->eventSize += \strlen($value) + 1;
            if ($this->eventSize > $this->maxEventBytes) {
                throw new LlmException(ErrorKind::Unknown, 'Stream event exceeds the size limit');
            }
            $this->data[] = $value;
        }

        return null;
    }
}

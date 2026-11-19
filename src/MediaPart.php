<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * An image or a video in a message, sent with its text: the provider gets it as a data: URL.
 */
final class MediaPart
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';

    private function __construct(
        public readonly string $kind,
        public readonly string $mime,
        public readonly string $bytes,
    ) {
    }

    public static function image(string $mime, string $bytes): self
    {
        return new self(self::IMAGE, $mime, $bytes);
    }

    public static function video(string $mime, string $bytes): self
    {
        return new self(self::VIDEO, $mime, $bytes);
    }

    public function dataUrl(): string
    {
        return 'data:' . $this->mime . ';base64,' . \base64_encode($this->bytes);
    }
}

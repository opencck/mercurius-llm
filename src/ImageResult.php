<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * The pictures an image model made, with what they cost.
 */
final class ImageResult
{
    /** @var list<MediaPart> */
    public readonly array $images;

    /**
     * @param list<MediaPart> $images at least one
     */
    public function __construct(array $images, public readonly Usage $usage, public readonly string $model)
    {
        $this->images = $images;
    }
}

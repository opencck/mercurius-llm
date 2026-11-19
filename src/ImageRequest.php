<?php

declare(strict_types=1);

namespace Mercurius\Llm;

/**
 * A request for pictures from an image model.
 */
final class ImageRequest
{
    public const MAX_IMAGES = 10;
    public const MAX_PROMPT = 32_000;

    public function __construct(
        public readonly string $model,
        public readonly string $prompt,
        /** Upper bound of pictures; a provider may return fewer. */
        public readonly int $count = 1,
        /** Such as "16:9" or "auto"; null leaves the model's default. */
        public readonly ?string $aspectRatio = null,
        /** A tier such as "2K"; null leaves the model's default. */
        public readonly ?string $resolution = null,
    ) {
        if ($model === '' || \trim($prompt) === '' || \strlen($prompt) > self::MAX_PROMPT) {
            throw new \InvalidArgumentException('An image request needs a model and a prompt of up to 32000 bytes');
        }
        if ($count < 1 || $count > self::MAX_IMAGES) {
            throw new \InvalidArgumentException('An image request asks for 1 to 10 pictures');
        }
        if ($aspectRatio !== null && \preg_match('/^(auto|\d{1,2}:\d{1,2})$/', $aspectRatio) !== 1) {
            throw new \InvalidArgumentException('Aspect ratio is "auto" or like "16:9"');
        }
        if ($resolution !== null && \preg_match('/^[0-9A-Za-z]{1,8}$/', $resolution) !== 1) {
            throw new \InvalidArgumentException('Resolution is a tier like "2K"');
        }
    }
}

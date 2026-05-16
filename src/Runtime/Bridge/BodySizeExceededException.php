<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

/**
 * Exception thrown when the request body exceeds the configured max_body_size.
 *
 * The SymfonyRequestHandler catches this and returns a 413 Payload Too Large
 * response before the Symfony kernel is involved, preventing OOM from oversized
 * uploads.
 */
final class BodySizeExceededException extends \RuntimeException
{
    public function __construct(
        public readonly int $actualSize,
        public readonly int $maxSize,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'Request body size (%d bytes) exceeds the configured maximum (%d bytes)',
                $actualSize,
                $maxSize,
            ),
            413,
            $previous,
        );
    }
}

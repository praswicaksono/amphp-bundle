<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\HttpFoundation;

final class SseEvent
{
    /**
     * @param array<string, mixed> $data Payload (will be JSON-encoded by SseResponse)
     * @param string|null          $event Optional event type (e.g. "message", "update")
     * @param string|null          $id    Optional event ID (for Last-Event-ID tracking)
     * @param int|null             $retry Optional reconnection time in milliseconds
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $event = null,
        public readonly ?string $id = null,
        public readonly ?int $retry = null,
    ) {}
}

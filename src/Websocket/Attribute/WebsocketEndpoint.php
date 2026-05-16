<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Websocket\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class WebsocketEndpoint
{
    /**
     * @param string $path The WebSocket route path (e.g. '/chat', '/ws/notifications')
     * @param string[] $allowedOrigins Optional list of allowed Origin header values
     */
    public function __construct(
        public readonly string $path,
        public readonly array $allowedOrigins = [],
    ) {}
}

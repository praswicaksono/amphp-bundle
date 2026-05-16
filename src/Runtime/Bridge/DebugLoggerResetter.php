<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use PRSW\AmphpBundle\Bridge\Symfony\Log\DebugLogger;

final class DebugLoggerResetter implements PriorityResetInterface
{
    public function __construct(
        private readonly ?DebugLogger $debugLogger = null,
    ) {}

    public function reset(): void
    {
        $this->debugLogger?->clear();
    }

    public function getPriority(): int
    {
        return -255;
    }
}

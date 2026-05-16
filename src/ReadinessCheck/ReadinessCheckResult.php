<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\ReadinessCheck;

final readonly class ReadinessCheckResult
{
    /**
     * @param array<string, string> $info
     */
    public function __construct(
        public ReadinessCheckStatus $status,
        public string $label,
        public string $message = '',
        public array $info = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Metrics;

final readonly class Metric
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $name,
        public float|int $value,
        public string $help = '',
        public string $type = 'gauge',
        public array $labels = [],
    ) {}
}

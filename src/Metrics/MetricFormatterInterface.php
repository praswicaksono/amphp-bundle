<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Metrics;

interface MetricFormatterInterface
{
    /**
     * @param list<Metric> $metrics
     */
    public function format(array $metrics): string;
}

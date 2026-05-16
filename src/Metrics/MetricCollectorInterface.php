<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Metrics;

interface MetricCollectorInterface
{
    /**
     * @return list<Metric>
     */
    public function collect(): array;
}

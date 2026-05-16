<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Metrics;

final readonly class PrometheusFormatter implements MetricFormatterInterface
{
    /**
     * @param list<Metric> $metrics
     */
    public function format(array $metrics): string
    {
        $lines = [];

        foreach ($metrics as $m) {
            if ($m->help !== '') {
                $lines[] = \sprintf('# HELP %s %s', $m->name, $m->help);
            }

            $lines[] = \sprintf('# TYPE %s %s', $m->name, $m->type);

            $labelPart = '';
            if ($m->labels !== []) {
                $pairs = [];
                foreach ($m->labels as $k => $v) {
                    $pairs[] = \sprintf('%s="%s"', $k, $v);
                }
                $labelPart = '{' . \implode(',', $pairs) . '}';
            }

            $lines[] = \sprintf('%s%s %s', $m->name, $labelPart, $m->value);
        }

        return \implode("\n", $lines) . "\n";
    }
}

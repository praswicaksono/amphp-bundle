<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Metrics;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class MetricsController
{
    /**
     * @param MetricCollectorInterface[] $collectors
     */
    public function __construct(
        private MetricFormatterInterface $formatter,
        private iterable $collectors,
    ) {}

    #[Route('/metrics', name: 'amphp_metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        $metrics = [];

        foreach ($this->collectors as $collector) {
            \array_push($metrics, ...$collector->collect());
        }

        return new Response(
            content: $this->formatter->format($metrics),
            status: 200,
            headers: ['content-type' => 'text/plain; version=0.0.4'],
        );
    }
}

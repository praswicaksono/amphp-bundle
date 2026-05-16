<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\ReadinessCheck;

use Psr\Log\LoggerInterface;

final readonly class ReadinessCheckService
{
    /**
     * @param ReadinessCheckInterface[] $checks
     */
    public function __construct(
        /** @var iterable<ReadinessCheckInterface> */
        private iterable $checks,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<ReadinessCheckResult>
     */
    public function runAll(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[] = $check->check();
            } catch (\Throwable $e) {
                $this->logger->warning('Readiness check failed with exception: {message}', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);

                $results[] = new ReadinessCheckResult(
                    status: ReadinessCheckStatus::Unavailable,
                    label: $check::class,
                    message: $e->getMessage(),
                );
            }
        }

        return $results;
    }

    /**
     * @param list<ReadinessCheckResult> $results
     */
    public function aggregate(array $results): ReadinessCheckResult
    {
        $worst = ReadinessCheckStatus::Ok;
        $messages = [];
        $info = [];

        foreach ($results as $result) {
            $info[$result->label] = $result->message;
            $messages[] = \sprintf('%s: %s', $result->label, $result->status->value);

            if ($this->isWorse($result->status, $worst)) {
                $worst = $result->status;
            }
        }

        return new ReadinessCheckResult(
            status: $worst,
            label: 'aggregate',
            message: \implode('; ', $messages),
            info: $info,
        );
    }

    private function isWorse(ReadinessCheckStatus $a, ReadinessCheckStatus $b): bool
    {
        $order = [
            ReadinessCheckStatus::Ok->value => 0,
            ReadinessCheckStatus::Degraded->value => 1,
            ReadinessCheckStatus::Unavailable->value => 2,
        ];

        return ($order[$a->value] ?? 0) > ($order[$b->value] ?? 0);
    }
}

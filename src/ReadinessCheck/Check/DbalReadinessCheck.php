<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\ReadinessCheck\Check;

use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckInterface;
use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckResult;
use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckStatus;
use Psr\Log\LoggerInterface;

final class DbalReadinessCheck implements ReadinessCheckInterface
{
    public function __construct(
        private readonly ?object $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public function check(): ReadinessCheckResult
    {
        if ($this->connection === null) {
            $this->logger->warning('DBAL readiness check skipped: doctrine/dbal not installed');

            return new ReadinessCheckResult(
                status: ReadinessCheckStatus::Ok,
                label: 'dbal',
                message: 'Skipped (doctrine/dbal not installed)',
            );
        }

        try {
            /** @disallow-weak-assertion */
            \assert(\method_exists($this->connection, 'executeQuery'));

            /** @var \Doctrine\DBAL\Result $stmt */
            $stmt = $this->connection->executeQuery('SELECT 1');
            $stmt->fetchOne();

            return new ReadinessCheckResult(
                status: ReadinessCheckStatus::Ok,
                label: 'dbal',
                message: 'Database connection is healthy',
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DBAL readiness check failed: {message}', [
                'message' => $e->getMessage(),
            ]);

            return new ReadinessCheckResult(
                status: ReadinessCheckStatus::Unavailable,
                label: 'dbal',
                message: $e->getMessage(),
            );
        }
    }
}

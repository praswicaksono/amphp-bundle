<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Doctrine\ORM;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EntityManagerResetListener
{
    /** @var array<string, float> Last ping timestamp per connection name */
    private array $lastPingTime = [];

    /**
     * @param int $pingInterval Seconds between connection pings (0 = ping every request)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?LoggerInterface $logger = null,
        private readonly int $pingInterval = 30,
        private readonly ?ContainerInterface $container = null,
    ) {}

    public function onKernelException(): void
    {
        foreach ($this->doctrine->getManagerNames() as $name => $serviceId) {
            try {
                $manager = $this->doctrine->getManager($name);
                if (!$manager->isOpen()) {
                    $this->logger?->info('Resetting closed EntityManager after exception', [
                        'manager' => $name,
                    ]);
                    $this->doctrine->resetManager($name);
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Failed to reset EntityManager after exception', [
                    'manager' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function onKernelTerminate(): void
    {
        // Skip Doctrine EM processing entirely if the EntityManager
        // was never used during this request. This prevents:
        //   - Unnecessary lazy-initialization of the EM + connection
        //   - Unnecessary connection pool pops that compete with
        //     real database queries on other fibers
        //   - Metadata loading overhead for endpoints that only
        //     render Twig templates or serve static-like responses
        if ($this->container !== null) {
            foreach ($this->doctrine->getManagerNames() as $name => $serviceId) {
                if (!$this->container->initialized($serviceId)) {
                    continue;
                }

                try {
                    $manager = $this->doctrine->getManager($name);

                    if ($manager->getUnitOfWork()->size() === 0) {
                        continue;
                    }

                    $connection = $manager->getConnection();

                    $this->pingConnection($connection, $name);

                    // Clear the UnitOfWork identity map to detach all entities.
                    $manager->clear();
                } catch (\Throwable $e) {
                    $this->logger?->warning('Failed to reset EntityManager on terminate', [
                        'manager' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Fiber and memory cleanup is now handled by DatabaseResetter
        // (runtime/priority/) which is called in the SymfonyRequestHandler
        // finally block. This ensures the connection is released AFTER
        // the EntityManager has been cleared and pinged above.
    }

    private function pingConnection(DbalConnection $connection, string $name): void
    {
        if (!$connection->isConnected()) {
            return;
        }

        $now = microtime(true);

        // Skip ping if within the keepalive interval.
        if ($this->pingInterval > 0 && isset($this->lastPingTime[$name])) {
            if (($now - $this->lastPingTime[$name]) < $this->pingInterval) {
                return;
            }
        }

        try {
            $dummySql = $connection->getDatabasePlatform()->getDummySelectSQL();
            $connection->executeQuery($dummySql);
            $this->lastPingTime[$name] = $now;
        } catch (\Throwable $e) {
            $this->logger?->warning('Connection ping failed, closing for reconnect', [
                'connection' => $name,
                'error' => $e->getMessage(),
            ]);
            $connection->close();
        }
    }
}

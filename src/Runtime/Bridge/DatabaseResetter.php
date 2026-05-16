<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bridge;

use PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\AsyncConnection;
use Revolt\EventLoop\FiberLocal;

final class DatabaseResetter implements PriorityResetInterface
{
    /** @var \Fiber|null Current request fiber, captured before handling. */
    private static ?\Fiber $currentRequestFiber = null;

    public static function captureFiber(\Fiber $fiber): void
    {
        self::$currentRequestFiber = $fiber;
    }

    public function reset(): void
    {
        $fiber = self::$currentRequestFiber;
        self::$currentRequestFiber = null;

        // Release any pooled database connection held by this fiber.
        if ($fiber !== null) {
            AsyncConnection::releaseConnectionForFiber($fiber);
        }

        // Clear FiberLocal entries as a second line of defence.
        FiberLocal::clear();
    }

    public function getPriority(): int
    {
        return 100;
    }
}

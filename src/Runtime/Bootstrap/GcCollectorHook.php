<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bootstrap;

use Psr\Container\ContainerInterface;
use Revolt\EventLoop;

final readonly class GcCollectorHook implements AfterBootHookInterface
{
    public function __construct(
        private int $intervalSeconds = 30,
    ) {}

    public function onAfterBoot(ContainerInterface $container): void
    {
        if ($this->intervalSeconds < 1) {
            return;
        }

        if (!gc_enabled()) {
            return;
        }

        gc_collect_cycles();

        EventLoop::repeat($this->intervalSeconds * 1000, static function (): void {
            gc_collect_cycles();
            gc_mem_caches();
        });
    }
}

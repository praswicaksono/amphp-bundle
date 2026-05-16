<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\Profiler\AsyncProfilerStorage;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProfilerIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Skip if profiler is not enabled (no storage service)
        if (!$container->hasDefinition('profiler.storage')) {
            return;
        }

        $container->getDefinition('profiler.storage')->setClass(AsyncProfilerStorage::class);
    }
}

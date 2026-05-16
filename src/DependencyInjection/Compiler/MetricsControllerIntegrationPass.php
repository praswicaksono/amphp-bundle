<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Metrics\MetricCollectorInterface;
use PRSW\AmphpBundle\Metrics\MetricsController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MetricsControllerIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Register the autoconfigure tag for MetricCollectorInterface
        $container->registerForAutoconfiguration(MetricCollectorInterface::class)
            ->addTag('amphp.metric_collector');

        // Scan all services by direct class analysis rather than relying on
        // autoconfigure tag resolution timing (ResolveInstanceofConditionalsPass
        // runs before this pass, so tags haven't been applied).
        $collectorRefs = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            // Resolve the class: getClass() returns null for services
            // defined via resource import.
            $class = $definition->getClass() ?? $id;

            try {
                $reflectionClass = new \ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }

            if ($reflectionClass->implementsInterface(MetricCollectorInterface::class)) {
                $collectorRefs[] = new Reference($id);
            }
        }

        if ($container->hasDefinition('amphp.metrics_controller')) {
            $container->getDefinition('amphp.metrics_controller')
                ->setArgument('$collectors', $collectorRefs);
        }
    }
}

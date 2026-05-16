<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\ReadinessCheck\Check\DbalReadinessCheck;
use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckInterface;
use PRSW\AmphpBundle\ReadinessCheck\ReadinessCheckService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ReadinessCheckIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Register the autoconfigure tag for ReadinessCheckInterface
        $container->registerForAutoconfiguration(ReadinessCheckInterface::class)
            ->addTag('amphp.readiness_check');

        // Conditionally wire DBAL connection to DbalReadinessCheck
        if ($container->hasDefinition('amphp.dbal_readiness_check')) {
            $checkDb = $container->hasParameter('amphp.readiness.check_db')
                && $container->getParameter('amphp.readiness.check_db');

            if ($checkDb && $container->has('doctrine.dbal.default_connection')) {
                $container->getDefinition('amphp.dbal_readiness_check')
                    ->setArgument('$connection', new Reference('doctrine.dbal.default_connection'));
            }
            // If doctrine/dbal not installed — argument stays null
        }

        // Find all tagged readiness checks
        $taggedServices = $container->findTaggedServiceIds('amphp.readiness_check');

        $checkReferences = [];
        foreach ($taggedServices as $id => $tags) {
            $checkReferences[] = new Reference($id);
        }

        if ($container->hasDefinition('amphp.readiness_check_service')) {
            $definition = $container->getDefinition('amphp.readiness_check_service');
            $definition->setArgument('$checks', $checkReferences);
        }
    }
}

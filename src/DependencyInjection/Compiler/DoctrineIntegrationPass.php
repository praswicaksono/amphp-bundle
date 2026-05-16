<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Doctrine\ORM\EntityManagerResetListener;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class DoctrineIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Skip if Doctrine is not installed
        if (!\interface_exists(ManagerRegistry::class)) {
            return;
        }

        // Skip if no doctrine service is registered
        if (!$container->has('doctrine')) {
            return;
        }

        // ---- Wire bundle DBAL config into Doctrine connection driver options ---
        //
        // The bundle defines dbal.max_connections and dbal.idle_timeout in its own
        // config tree (amphp.yaml). These values are stored as container
        // parameters (%amphp.dbal.max_connections%) by AmphpExtension, but
        // they are NOT automatically applied to Doctrine's connection options.
        //
        // Here we override the connection service's driverOptions to apply the
        // bundle-level config. Users can set these in config/packages/amphp.yaml:
        //
        //   amphp:
        //       dbal:
        //           max_connections: 200
        //           idle_timeout: 120
        $defaultConnection = $container->hasParameter('doctrine.default_connection')
            ? $container->getParameter('doctrine.default_connection')
            : 'default';

        $connections = $container->hasParameter('doctrine.connections')
            ? $container->getParameter('doctrine.connections')
            : [\sprintf('doctrine.dbal.%s_connection', $defaultConnection)];

        foreach ($connections as $connectionId) {
            if (!$container->hasDefinition($connectionId)) {
                continue;
            }

            $def = $container->getDefinition($connectionId);
            $args = $def->getArguments();

            if (!isset($args[0]) || !\is_array($args[0])) {
                continue;
            }

            $options = $args[0];

            if ($container->hasParameter('amphp.dbal.max_connections')) {
                $options['driverOptions']['max_connections'] = $container->getParameter('amphp.dbal.max_connections');
            }

            if ($container->hasParameter('amphp.dbal.idle_timeout')) {
                $options['driverOptions']['idle_timeout'] = $container->getParameter('amphp.dbal.idle_timeout');
            }

            $def->setArgument(0, $options);
        }

        // ---- Register the lifecycle listener ---------------------------------
        $listener = $container->register(EntityManagerResetListener::class, EntityManagerResetListener::class);
        $listener->setArguments([
            new Reference('doctrine'),
            new Reference('logger'),
            '%amphp.dbal.ping_interval%',
            new Reference('service_container'),
        ]);
        $listener->setPublic(false);

        // Tag as kernel.exception listener (resets closed EntityManagers)
        $listener->addTag('kernel.event_listener', [
            'event' => 'kernel.exception',
            'method' => 'onKernelException',
            'priority' => 1000,
        ]);

        // Tag as kernel.terminate listener (clears identity map after response)
        $listener->addTag('kernel.event_listener', [
            'event' => 'kernel.terminate',
            'method' => 'onKernelTerminate',
            'priority' => -1000,
        ]);
    }
}

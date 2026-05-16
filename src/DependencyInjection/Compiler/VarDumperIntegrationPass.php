<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\VarDumper\AmpDumpServerConnection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class VarDumperIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!\class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {
            return;
        }

        // DebugBundle handles dumps via the profiler — we don't interfere
        if ($container->hasDefinition('data_collector.dump')) {
            // TODO: When DebugBundle + VAR_DUMPER_SERVER are both active,
            // the DumpListener uses blocking stream_socket_client for the
            // remote connection. A future enhancement could override this.
            return;
        }

        $this->registerHandler($container, $this->getDumpServerHost($container));
    }

    private function registerHandler(ContainerBuilder $container, ?string $dumpServerHost): void
    {
        $listenerId = 'amphp.var_dumper.listener';

        if ($container->has($listenerId)) {
            return;
        }

        $connectionId = null;
        if (null !== $dumpServerHost) {
            $connectionId = 'amphp.var_dumper.connection';
            $container
                ->register($connectionId, AmpDumpServerConnection::class)
                ->setArguments([$dumpServerHost])
                ->setPublic(false);
        }

        $container
            ->register($listenerId, AmpVarDumperRequestListener::class)
            ->setArguments([
                '$connection' => $connectionId ? new Reference($connectionId) : null,
            ])
            ->addTag('kernel.event_listener', [
                'event' => 'kernel.request',
                'method' => 'onKernelRequest',
                'priority' => 1000,
            ]);
    }

    private function getDumpServerHost(ContainerBuilder $container): ?string
    {
        // Try the resolved container parameter first, fall back to raw env
        if ($container->hasParameter('env(VAR_DUMPER_SERVER)')) {
            $host = $container->getParameter('env(VAR_DUMPER_SERVER)');
            if (\is_string($host) && '' !== $host) {
                return $host;
            }
        }

        return $_SERVER['VAR_DUMPER_SERVER'] ?? $_ENV['VAR_DUMPER_SERVER'] ?? null;
    }
}

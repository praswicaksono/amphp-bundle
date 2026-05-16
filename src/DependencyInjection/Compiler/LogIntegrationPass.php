<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\Log\DebugLogger;
use PRSW\AmphpBundle\Bridge\Symfony\Log\LoggerFactory;
use Monolog\Processor\PsrLogMessageProcessor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class LogIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!\class_exists(\Monolog\Logger::class)) {
            return;
        }

        // Register the DebugLogger processor for profiler integration.
        // It implements DebugLoggerInterface, so the LoggerDataCollector
        // will find it via DebugLoggerConfigurator::getDebugLogger().
        if (!$container->has(DebugLogger::class)) {
            $container
                ->register(DebugLogger::class, DebugLogger::class)
                ->setArguments([new Reference('request_stack')])
                ->setPublic(true);
        }

        // Replace the 'logger' service with a factory-created async logger.
        // The original definition is preserved as 'logger.symfony' so
        // LoggerFactory can fall back to it for regular CLI commands
        // (e.g. bin/console) where the AMPHP stream handler should not
        // replace Symfony's standard console/file logging.
        //
        // MonologBundle registers 'logger' as a private alias for
        // 'monolog.logger'. We must handle both cases: direct definition
        // and alias.
        if (!$container->has('logger')) {
            return;
        }

        // Resolve alias -> actual service ID, save original definition
        if ($container->hasAlias('logger')) {
            $alias = $container->getAlias('logger');
            $originalId = (string) $alias;
            $container->removeAlias('logger');

            if ($container->hasDefinition($originalId)) {
                $originalDef = clone $container->getDefinition($originalId);
                $container->setDefinition('logger.symfony', $originalDef);
            }
        } else {
            $originalDef = clone $container->getDefinition('logger');
            $container->setDefinition('logger.symfony', $originalDef);
        }

        // Register a new public definition for 'logger'
        $def = $container->register('logger', \Monolog\Logger::class);
        $def->setFactory([LoggerFactory::class, 'create']);
        $def->setArguments(['app', 'php://stdout', new Reference('logger.symfony')]);
        $def->setPublic(true);

        // Push the PsrLogMessageProcessor (logger-level, applies to all handlers)
        $def->addMethodCall('pushProcessor', [new Reference(PsrLogMessageProcessor::class)]);

        // Register PsrLogMessageProcessor as a service if not already
        if (!$container->has(PsrLogMessageProcessor::class)) {
            $container->register(PsrLogMessageProcessor::class, PsrLogMessageProcessor::class)->setPublic(false);
        }

        // Push the DebugLogger processor so the profiler can collect logs.
        // This must be the LAST processor so it sees the final processed record.
        $def->addMethodCall('pushProcessor', [new Reference(DebugLogger::class)]);
    }
}

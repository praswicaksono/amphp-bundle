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
        if ($container->hasDefinition('logger')) {
            $def = $container->getDefinition('logger');

            // Save the original logger definition for CLI fallback
            $originalDef = clone $def;
            $container->setDefinition('logger.symfony', $originalDef);

            $def->setFactory([LoggerFactory::class, 'create']);
            $def->setArguments(['app', 'php://stdout', new Reference('logger.symfony')]);
            $def->setClass(\Monolog\Logger::class);
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
}

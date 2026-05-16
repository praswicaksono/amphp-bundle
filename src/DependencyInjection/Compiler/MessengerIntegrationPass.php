<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\Messenger\Redis\AmpRedisTransportFactory;
use PRSW\AmphpBundle\Command\AmpConsumeMessagesCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class MessengerIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->registerDoctrineTransport($container);
        $this->registerAmpRedisTransport($container);
        $this->replaceConsumeCommand($container);
    }

    private function registerDoctrineTransport(ContainerBuilder $container): void
    {
        if (!\class_exists(\Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory::class)) {
            return;
        }

        if (!$container->has('doctrine')) {
            return;
        }

        $factoryId = 'messenger.transport.doctrine.factory';

        if ($container->has($factoryId)) {
            // Already registered (e.g., by another bundle), ensure it has the tag
            $def = $container->getDefinition($factoryId);
        } else {
            $def = $container->register(
                $factoryId,
                \Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory::class,
            );
            $def->setArguments([
                new Reference('doctrine'),
            ]);
        }

        $def->addTag('messenger.transport_factory');
    }

    private function registerAmpRedisTransport(ContainerBuilder $container): void
    {
        if (!\class_exists(\Amp\Redis\RedisClient::class)) {
            return;
        }

        if (!\class_exists(\Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceivedStamp::class)) {
            return;
        }

        $factoryId = 'messenger.transport.amphp_redis.factory';

        if ($container->has($factoryId)) {
            $def = $container->getDefinition($factoryId);
        } else {
            $def = $container->register($factoryId, AmpRedisTransportFactory::class);
        }

        $def->addTag('messenger.transport_factory');
    }

    private function replaceConsumeCommand(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('console.command.messenger_consume_messages')) {
            return;
        }

        $def = $container->getDefinition('console.command.messenger_consume_messages');
        $def->setClass(AmpConsumeMessagesCommand::class);
    }
}

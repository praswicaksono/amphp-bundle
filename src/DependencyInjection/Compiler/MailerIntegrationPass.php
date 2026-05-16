<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use Amp\Http\Client\DelegateHttpClient;
use PRSW\AmphpBundle\Bridge\Symfony\Mailer\AsyncEsmtpTransportFactory;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\AmpHttpClient;

final class MailerIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->replaceSmtpFactory($container);
        $this->configureHttpClient($container);
    }

    private function replaceSmtpFactory(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mailer.transport_factory.smtp')) {
            return;
        }

        $def = $container->getDefinition('mailer.transport_factory.smtp');
        $def->setClass(AsyncEsmtpTransportFactory::class);
    }

    private function configureHttpClient(ContainerBuilder $container): void
    {
        if (!\interface_exists(DelegateHttpClient::class)) {
            return;
        }

        if (!\class_exists(AmpHttpClient::class)) {
            return;
        }

        if (!$container->hasDefinition('http_client.transport')) {
            return;
        }

        $def = $container->getDefinition('http_client.transport');

        // HttpClient::create() prefers CurlHttpClient when curl is available,
        // which is blocking. We force AmpHttpClient for non-blocking HTTP I/O.
        $def->setClass(AmpHttpClient::class);
        $def->setFactory(null);

        $args = $def->getArguments();

        // Extract maxHostConnections from the original args[1].
        // The original factory (HttpClient::create) takes (defaultOptions, maxHostConnections, ...).
        // AmpHttpClient takes (defaultOptions, clientConfigurator, maxHostConnections, maxPendingPushes).
        $maxHostConnections = 6;
        if (isset($args[1])) {
            $maxHostConnections = $args[1] instanceof AbstractArgument ? 6 : (int) $args[1];
        }

        $def->setArguments([
            $args[0] ?? [], // defaultOptions
            null, // clientConfigurator (null = default InterceptedHttpClient)
            $maxHostConnections,
            50, // maxPendingPushes
        ]);
    }
}

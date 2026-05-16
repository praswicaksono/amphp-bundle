<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Websocket\Attribute\WebsocketEndpoint;
use Amp\Websocket\Server\WebsocketClientHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Compiler pass that discovers WebSocket endpoints.
 *
 * This pass iterates all service definitions, checks if the class implements
 * WebsocketClientHandler, and looks for the #[WebsocketEndpoint] attribute
 * to extract routing configuration.
 *
 * Services with the attribute are marked as public so worker scripts can
 * retrieve them from the container.
 *
 * Endpoint definitions are stored as a container parameter so worker scripts
 * can access them when wiring up the AMPHP server.
 */
final class WebsocketEndpointPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Register autoconfigure for WebsocketClientHandler for any services
        // that may be registered after this pass runs.
        $container->registerForAutoconfiguration(WebsocketClientHandler::class)
            ->addTag('amphp.websocket.handler');

        // Scan all services by direct class analysis.
        $endpoints = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isAbstract()) {
                continue;
            }

            // Resolve the class: getClass() returns null for services
            // defined via resource import (e.g., App\: resource: '../src/').
            // In that case, the service ID is the fully-qualified class name.
            $class = $definition->getClass() ?? $id;

            try {
                $reflectionClass = new \ReflectionClass($class);
            } catch (\Throwable) {
                // Class may not exist or its dependencies may not be
                // available (e.g., Doctrine form guesser requiring
                // symfony/form). Skip gracefully.
                continue;
            }

            // Skip if the class doesn't implement WebsocketClientHandler
            if (!$reflectionClass->implementsInterface(WebsocketClientHandler::class)) {
                continue;
            }

            // Check for the #[WebsocketEndpoint] attribute
            $attribute = $reflectionClass->getAttributes(WebsocketEndpoint::class);

            if ($attribute === []) {
                continue;
            }

            /** @var WebsocketEndpoint $instance */
            $instance = $attribute[0]->newInstance();

            // Mark as public so worker scripts can retrieve it from the container
            $definition->setPublic(true);

            $endpoints[] = [
                'path' => $instance->path,
                'handler' => $id,
                'allowed_origins' => $instance->allowedOrigins,
            ];
        }

        $container->setParameter('amphp.websocket.endpoints', $endpoints);
    }
}

<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Configures the Symfony error renderer for AMPHP worker context.
 *
 * 1. Forces `kernel.runtime_mode.web` to `true` so the error renderer
 *    factory selects HtmlErrorRenderer (or TwigErrorRenderer when Twig
 *    is available) instead of CliErrorRenderer. AMPHP workers serve
 *    HTTP requests from a CLI process, so web mode is semantically correct.
 *
 * 2. Makes the error_renderer service public so it can be retrieved
 *    from the container in worker scripts for rendering proper error
 *    pages. The container optimizer would otherwise inline it since it
 *    is only referenced by error_controller.
 */
final class ErrorRendererIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Force web runtime mode — AMPHP workers serve HTTP, not CLI
        $container->setParameter('kernel.runtime_mode', [
            'web' => true,
            'worker' => true,
            'cli' => false,
        ]);

        // Make error renderer services public so workers can retrieve them
        $errorRendererIds = [
            'error_renderer',
            'error_renderer.default',
            'error_handler.error_renderer.default',
        ];

        foreach ($errorRendererIds as $id) {
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            } elseif ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}

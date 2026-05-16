<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\Session\AmpFileSessionHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\StrictSessionHandler;

final class SessionIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->replaceNativeFileHandler($container);
    }

    private function replaceNativeFileHandler(ContainerBuilder $container): void
    {
        $savePath = \ini_get('session.save_path') ?: null;

        // Replace session.handler.native_file with our async handler.
        // Used when framework.session.handler_id = 'session.handler.native_file'.
        if ($container->hasDefinition('session.handler.native_file')) {
            $def = $container->getDefinition('session.handler.native_file');
            $def->setClass(AmpFileSessionHandler::class);
            $def->setArguments([$savePath]);
        }

        // Replace session.handler.native (default when handler_id is unset).
        // Original: StrictSessionHandler(\SessionHandler)
        // Problem: StrictSessionHandler rejects handlers implementing
        //          SessionUpdateTimestampHandlerInterface (which ours does).
        // Solution: Replace StrictSessionHandler with AmpFileSessionHandler directly.
        if ($container->hasDefinition('session.handler.native')) {
            $def = $container->getDefinition('session.handler.native');
            $def->setClass(AmpFileSessionHandler::class);
            $def->setArguments([$savePath]);
        }
    }
}

<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\Filesystem\Filesystem;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FilesystemIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('filesystem')) {
            $container->getDefinition('filesystem')->setClass(Filesystem::class);
        }
    }
}

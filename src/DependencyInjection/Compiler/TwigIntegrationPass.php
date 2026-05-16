<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Twig\Loader\FilesystemLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TwigIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!\class_exists(\Twig\Environment::class)) {
            return;
        }

        if ($container->hasDefinition('twig.loader.native_filesystem')) {
            $container->getDefinition('twig.loader.native_filesystem')->setClass(FilesystemLoader::class);
        }

        if ($container->hasDefinition('twig')) {
            $options = $container->getDefinition('twig')->getArgument(1);

            if (\is_array($options)) {
                $options['use_yield'] = true;
                $container->getDefinition('twig')->replaceArgument(1, $options);
            }
        }
    }
}

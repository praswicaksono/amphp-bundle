<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Runtime\Bootstrap\AfterBootHookInterface;
use PRSW\AmphpBundle\Runtime\Bootstrap\TranslatorWarmupHook;
use PRSW\AmphpBundle\Runtime\Bridge\PriorityResetInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BootstrapIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Set up autoconfiguration: any service implementing
        // AfterBootHookInterface gets auto-tagged.
        $container->registerForAutoconfiguration(AfterBootHookInterface::class)->addTag('amphp.after_boot_hook');

        // Set up autoconfiguration for PriorityResetInterface so end users
        // can simply implement the interface and their service is automatically
        // collected by RequestResetter and called after each request.
        $container->registerForAutoconfiguration(PriorityResetInterface::class)
            ->addTag('amphp.resetter');

        // Register the translator warmup hook if symfony/translation exists
        if (\class_exists(\Symfony\Component\Translation\Translator::class)) {
            $container->register(TranslatorWarmupHook::class)->setAutowired(true)->setAutoconfigured(true);

            // Autoconfigure adds the 'amphp.after_boot_hook' tag via the
            // registerForAutoconfiguration call above
        }

        // Store the list of hook service IDs as a container parameter.
        // The runner reads this parameter at runtime and invokes each hook
        // via ContainerInterface::get(). Symfony's ContainerInterface
        // includes getParameter()/hasParameter().
        $hookIds = array_keys($container->findTaggedServiceIds('amphp.after_boot_hook'));
        $container->setParameter('amphp.after_boot_hook.ids', $hookIds);
    }
}

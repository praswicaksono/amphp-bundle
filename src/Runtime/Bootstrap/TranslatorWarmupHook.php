<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bootstrap;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final class TranslatorWarmupHook implements AfterBootHookInterface
{
    public function onAfterBoot(ContainerInterface $container): void
    {
        // Skip if translator is not registered
        if (!$container->has('translator')) {
            return;
        }

        $translator = $container->get('translator');

        // Step 1: Generate cached PHP catalogue files (warmup)
        if ($translator instanceof CacheWarmerInterface) {
            $cacheDir = $container->hasParameter('kernel.cache_dir')
                ? $container->getParameter('kernel.cache_dir') . '/translations'
                : null;

            if (null !== $cacheDir) {
                $translator->warmUp($cacheDir);
            }
        }

        foreach ($this->getConfiguredLocales($container) as $locale) {
            $translator->trans('__amphp_warmup__', [], 'messages', $locale);
        }
    }

    private function getConfiguredLocales(ContainerInterface $container): array
    {
        if ($container->hasParameter('kernel.enabled_locales')) {
            /** @var string[] $locales */
            $locales = $container->getParameter('kernel.enabled_locales');
            if ($locales !== []) {
                return $locales;
            }
        }

        $defaultLocale = $container->hasParameter('kernel.default_locale')
            ? $container->getParameter('kernel.default_locale')
            : 'en';

        return [$defaultLocale];
    }
}

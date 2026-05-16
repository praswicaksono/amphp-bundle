<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Bootstrap;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Psr\Container\ContainerInterface;

final class KernelBootstrapper
{
    public static function bootAndRunHooks(KernelInterface $kernel, ?LoggerInterface $logger = null): void
    {
        $kernel->boot();
        $container = $kernel->getContainer();

        $logger ??= $container->has('logger') ? $container->get('logger') : new \Psr\Log\NullLogger();

        $hookIds = self::resolveHookIds($container);

        foreach ($hookIds as $id) {
            self::invokeHook($container, $id, $logger);
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveHookIds(ContainerInterface $container): array
    {
        if (!$container->hasParameter('amphp.after_boot_hook.ids')) {
            return [];
        }

        /** @var string[] $ids */
        return $container->getParameter('amphp.after_boot_hook.ids');
    }

    private static function invokeHook(
        ContainerInterface $container,
        string $id,
        LoggerInterface $logger,
    ): void {
        if (!$container->has($id)) {
            return;
        }

        $hook = $container->get($id);

        if (!$hook instanceof AfterBootHookInterface) {
            $logger->warning('Skipping after-boot hook "{id}": does not implement AfterBootHookInterface.', [
                'id' => $id,
            ]);

            return;
        }

        try {
            $logger->info('Running after-boot hook "{id}".', ['id' => $id]);
            $hook->onAfterBoot($container);
            $logger->info('After-boot hook "{id}" completed.', ['id' => $id]);
        } catch (\Throwable $e) {
            $logger->error('After-boot hook "{id}" failed: {message}', [
                'id' => $id,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}

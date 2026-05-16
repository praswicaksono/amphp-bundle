<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\DependencyInjection\Compiler;

use PRSW\AmphpBundle\Bridge\Symfony\Cache\AsyncFilesystemAdapter;
use PRSW\AmphpBundle\Bridge\Symfony\Cache\AsyncRedisAdapter;
use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CacheIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->replaceFilesystemAdapter($container);
        $this->replaceSystemAdapter($container);
        $this->registerAsyncRedisAdapter($container);
        $this->patchRedisConnectionServices($container);
    }

    private function replaceFilesystemAdapter(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('cache.adapter.filesystem')) {
            return;
        }

        // Skip if already replaced (idempotent — pass runs twice at different priorities)
        $def = $container->getDefinition('cache.adapter.filesystem');
        if (AsyncFilesystemAdapter::class === $def->getClass()) {
            return;
        }

        $def->setClass(AsyncFilesystemAdapter::class);
    }

    /**
     * Replace cache.adapter.system (PhpFilesAdapter) with AsyncFilesystemAdapter.
     *
     * PhpFilesAdapter uses blocking include() + OPcache for reads. In a long-running
     * AMPHP process, include() blocks the current fiber. Switching to AsyncFilesystemAdapter
     * eliminates this blocking I/O while maintaining the same directory structure.
     *
     * The factory [AbstractAdapter::class, 'createSystemCache'] is removed and the
     * service is changed to instantiate AsyncFilesystemAdapter directly. The logger
     * (previously passed as arg 4 to the factory) is instead injected via setLogger().
     */
    private function replaceSystemAdapter(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('cache.adapter.system')) {
            return;
        }

        // Skip if already replaced (idempotent)
        $def = $container->getDefinition('cache.adapter.system');
        if (AsyncFilesystemAdapter::class === $def->getClass()) {
            return;
        }

        /** @var string $cacheDir */
        $cacheDir = $container->getParameter('kernel.cache_dir');

        $def->setClass(AsyncFilesystemAdapter::class);
        $def->setFactory(null);
        $def->setArguments([
            '', // arg 0: namespace (overridden by CachePoolPass for each child pool)
            0, // arg 1: default_lifetime
            $cacheDir . '/pools/system', // arg 2: directory
            new Reference('cache.default_marshaller'), // arg 3: marshaller
        ]);
        // Remove the 5th arg (logger) that was passed to createSystemCache factory
        // — set it via method call instead
        $def->addMethodCall('setLogger', [new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]);
    }

    private function registerAsyncRedisAdapter(ContainerBuilder $container): void
    {
        if (!\class_exists(\Amp\Redis\RedisClient::class)) {
            return;
        }

        // Skip if already registered (idempotent)
        if ($container->hasDefinition('amphp.cache.adapter.redis')) {
            return;
        }

        $container
            ->register('amphp.cache.adapter.redis', AsyncRedisAdapter::class)
            ->setAbstract(true)
            ->setArguments([
                '', // arg 0: RedisClient (replaced by CachePoolPass via 'provider' tag)
                '', // arg 1: namespace (replaced by CachePoolPass computed namespace)
                0, // arg 2: default_lifetime (replaced if pool config has it)
                new Reference('cache.default_marshaller'),
            ])
            ->addTag('cache.pool', [
                'provider' => 'cache.default_redis_provider',
                'clearer' => 'cache.default_clearer',
                'reset' => 'reset',
            ]);
    }

    /**
     * Patch hidden cache connection services created by CachePoolPass.
     *
     * CachePoolPass::getServiceProvider() creates definitions like:
     *   .cache_connection.<hash>
     *     factory: [AbstractAdapter::class, 'createConnection']
     *     args: ['redis://localhost', ['lazy' => true]]
     *
     * We patch these to use our createConnection() which returns a RedisClient
     * instead of a \Redis object, compatible with AsyncRedisAdapter.
     *
     * This is a no-op if CachePoolPass hasn't run yet (first invocation at
     * priority 100 runs before CachePoolPass).
     */
    private function patchRedisConnectionServices(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!\str_starts_with($id, '.cache_connection.')) {
                continue;
            }

            if (!$this->isAbstractCreateRedisConnection($definition)) {
                continue;
            }

            $definition->setFactory([AsyncRedisAdapter::class, 'createConnection']);
        }
    }

    /**
     * Check if a definition is a Redis connection created by CachePoolPass.
     *
     * Matches: factory=[AbstractAdapter::class, 'createConnection'],
     *          first arg is a redis:/valkey: DSN string.
     */
    private function isAbstractCreateRedisConnection(Definition $definition): bool
    {
        $factory = $definition->getFactory();
        if (!$factory || 2 !== \count($factory)) {
            return false;
        }

        if (AbstractAdapter::class !== $factory[0] || 'createConnection' !== $factory[1]) {
            return false;
        }

        $args = $definition->getArguments();
        $dsn = $args[0] ?? '';
        if (!\is_string($dsn)) {
            return false;
        }

        return (
            \str_starts_with($dsn, 'redis:')
            || \str_starts_with($dsn, 'rediss:')
            || \str_starts_with($dsn, 'valkey:')
            || \str_starts_with($dsn, 'valkeys:')
        );
    }
}

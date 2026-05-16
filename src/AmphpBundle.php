<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle;

use PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\AsyncConnection;
use PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\AsyncMysqlDriver;
use PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\AsyncPostgresDriver;
use PRSW\AmphpBundle\DependencyInjection\AmphpExtension;
use PRSW\AmphpBundle\DependencyInjection\Compiler\BootstrapIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\CacheIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\DoctrineIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\ErrorRendererIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\FilesystemIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\ReadinessCheckIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\LogIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\MetricsControllerIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\MailerIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\ProfilerIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\SessionIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\TwigIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\VarDumperIntegrationPass;
use PRSW\AmphpBundle\DependencyInjection\Compiler\WebsocketEndpointPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class AmphpBundle extends AbstractBundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return $this->extension ??= new AmphpExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        if (\interface_exists(\Doctrine\Persistence\ManagerRegistry::class)) {
            $container->prependExtensionConfig('doctrine', [
                'dbal' => [
                    'driver_schemes' => [
                        'mysql' => AsyncMysqlDriver::class,
                        'postgres' => AsyncPostgresDriver::class,
                        'postgresql' => AsyncPostgresDriver::class,
                    ],
                    'driver_class' => AsyncMysqlDriver::class,
                    'wrapper_class' => AsyncConnection::class,
                    'options' => [
                        'max_connections' => 100,
                        'idle_timeout' => 60,
                    ],
                ],
            ]);
        }

        $container->addCompilerPass(new FilesystemIntegrationPass());

        // Bootstrap hooks: register autoconfigure for AfterBootHookInterface
        // and register the TranslatorWarmupHook if symfony/translation exists.
        $container->addCompilerPass(new BootstrapIntegrationPass());

        $container->addCompilerPass(new LogIntegrationPass(), priority: -64);

        $container->addCompilerPass(new TwigIntegrationPass(), priority: 9);

        $container->addCompilerPass(new ProfilerIntegrationPass(), priority: 8);

        // Cache integration: high priority (before CachePoolPass at 0)
        //   - Replace cache.adapter.filesystem class
        //   - Register amphp.cache.adapter.redis
        $container->addCompilerPass(new CacheIntegrationPass(), priority: 100);

        // Cache integration: low priority (after CachePoolPass at 0)
        //   - Patch hidden .cache_connection.* services to use async factory
        $container->addCompilerPass(new CacheIntegrationPass(), priority: -10);

        $container->addCompilerPass(new DoctrineIntegrationPass(), priority: 100);

        // Error renderer integration: make error_renderer public so workers
        // can retrieve it to render Symfony error pages.
        $container->addCompilerPass(new ErrorRendererIntegrationPass());

        // Mailer integration: replace SMTP transport factory and configure
        // AmpHttpClient for all HTTP-based mailer transports.
        $container->addCompilerPass(new MailerIntegrationPass());

        // Messenger integration: register doctrine + amphp-redis transport
        // factories and replace the consume command with an async-aware version.
        $container->addCompilerPass(new MessengerIntegrationPass());

        // Session integration: replace blocking session handlers with async
        // Amp\File and Amp\Redis based versions.
        $container->addCompilerPass(new SessionIntegrationPass());

        // VarDumper integration: use HtmlDumper in AMPHP web context,
        // async dump server connection via amphp/socket.
        $container->addCompilerPass(new VarDumperIntegrationPass());

        // Readiness check integration: register autoconfigure for
        // ReadinessCheckInterface, inject tagged checks into ReadinessCheckService.
        $container->addCompilerPass(new ReadinessCheckIntegrationPass());

        // Metrics integration: register autoconfigure for
        // MetricCollectorInterface, inject tagged collectors into MetricsController.
        $container->addCompilerPass(new MetricsControllerIntegrationPass());

        // WebSocket integration: discover #[WebsocketEndpoint] attributes on
        // WebsocketClientHandler implementations and register them as endpoints.
        $container->addCompilerPass(new WebsocketEndpointPass());
    }
}

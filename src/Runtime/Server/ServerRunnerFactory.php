<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ServerRunnerFactory
{
    public function create(
        ServerConfig $config,
        HttpKernelInterface $kernel,
        ContainerInterface $container,
        OutputInterface $output,
    ): ServerRunnerInterface {
        if ($config->devMode) {
            return new DevServerRunner(
                kernel: $kernel,
                container: $container,
                logger: $container->get('logger'),
                output: $output,
                config: $config,
            );
        }

        return new ClusterServerRunner(
            output: $output,
            config: $config,
        );
    }
}

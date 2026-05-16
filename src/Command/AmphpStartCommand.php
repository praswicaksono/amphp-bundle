<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Command;

use PRSW\AmphpBundle\Runtime\Server\ServerConfigResolver;
use PRSW\AmphpBundle\Runtime\Server\ServerRunnerFactory;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'amphp:start',
    description: 'Start the AMPHP server (cluster for production, dev mode for development)',
)]
final class AmphpStartCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED,
                'Number of worker processes for cluster mode (default: CPU core count)', null)
            ->addOption('max-requests', 'r', InputOption::VALUE_REQUIRED,
                'Requests handled before restart (0 = never)', null)
            ->addOption('host', null, InputOption::VALUE_REQUIRED,
                'Bind address', null)
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED,
                'Bind port', null)
            ->addOption('shutdown-timeout', 't', InputOption::VALUE_REQUIRED,
                'Shutdown timeout in seconds for graceful drain', '1')
            ->addOption('dev', null, InputOption::VALUE_NONE,
                'Run in development mode (single worker, inline HTTP server)')
            ->addOption('watch', null, InputOption::VALUE_NONE,
                'Watch for file changes and restart automatically (only with --dev)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $devMode = (bool) $input->getOption('dev');
        $watchMode = (bool) $input->getOption('watch');

        if ($watchMode && !$devMode) {
            $output->writeln('<comment>--watch requires --dev. Use --dev --watch for hot reload.</comment>');
            return self::FAILURE;
        }

        if ($devMode && (bool) $input->getOption('workers')) {
            $output->writeln('<comment>--workers is only supported in cluster mode. Ignoring --workers.</comment>');
        }

        /** @var KernelInterface $kernel */
        $kernel = $this->getApplication()->getKernel();
        $container = $kernel->getContainer();

        $config = (new ServerConfigResolver($container, $input))->resolve(
            devMode: $devMode,
            watchMode: $watchMode,
        );

        $this->warmupCache($container, $output);

        $factory = $container->has('amphp.server_runner_factory')
            ? $container->get('amphp.server_runner_factory')
            : new ServerRunnerFactory();

        $runner = $factory->create(
            config: $config,
            kernel: $kernel,
            container: $container,
            output: $output,
        );

        return $runner->run();
    }

    private function warmupCache(ContainerInterface $container, OutputInterface $output): void
    {
        if (!$container->has('cache_warmer')) {
            return;
        }

        $cacheDir = $container->hasParameter('kernel.cache_dir')
            ? (string) $container->getParameter('kernel.cache_dir')
            : null;

        if ($cacheDir === null) {
            return;
        }

        $output->write('<info>Warming up cache...</info> ');

        $warmer = $container->get('cache_warmer');

        if ($warmer instanceof CacheWarmerInterface) {
            $warmer->warmUp($cacheDir);
        }

        $output->writeln('<info>done.</info>');
    }
}

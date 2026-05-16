<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

use Amp\ByteStream;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\ResourceServerSocketFactory;
use Monolog\Processor\PsrLogMessageProcessor;
use PRSW\AmphpBundle\Bridge\Amphp\FsWatchWatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function Amp\trapSignal;

final class DevServerRunner implements ServerRunnerInterface
{
    private bool $shuttingDown = false;

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly ContainerInterface $container,
        private readonly PsrLogger $logger,
        private readonly OutputInterface $output,
        private readonly ServerConfig $config,
    ) {
        // In dev inline mode the container's logger has no output stream
        // (handle is NULL), so all application logs are silently dropped.
        // Push a console handler so dev sees request logs, DB queries, etc.
        $this->pushConsoleLogHandler();
    }

    public function run(): int
    {
        if ($this->config->watchMode) {
            return $this->runWithWatch();
        }

        return $this->runInline();
    }

    public function getServerConfig(): ServerConfig
    {
        return $this->config;
    }

    private function pushConsoleLogHandler(): void
    {
        if (!$this->logger instanceof \Monolog\Logger) {
            return;
        }

        $handler = new StreamHandler(ByteStream\getStdout());
        $handler->setFormatter(new ConsoleFormatter());
        $handler->pushProcessor(new PsrLogMessageProcessor());
        $handler->setLevel(\Monolog\Level::Debug);

        $this->logger->pushHandler($handler);
    }

    private function runInline(): int
    {
        $this->output->writeln(\sprintf(
            '<info>Starting AMPHP dev server on %s:%d</info>',
            $this->config->host, $this->config->port,
        ));

        $server = $this->createAndStartServer();

        $this->output->writeln('<info>Press Ctrl+C to stop the server.</info>');

        trapSignal([\SIGINT, \SIGTERM]);

        $server->stop();
        $this->output->writeln('<info>Server stopped.</info>');

        return 0;
    }

    private function runWithWatch(): int
    {
        $watchDirs = \array_values(\array_filter([
            $this->config->projectDir . '/src',
            $this->config->projectDir . '/templates',
            $this->config->projectDir . '/config',
        ], 'is_dir'));

        if ($watchDirs === []) {
            $this->output->writeln('<error>No watchable directories found (src/, templates/, config/).</error>');
            return 1;
        }

        $this->output->writeln(\sprintf(
            '<info>Starting AMPHP dev server on %s:%d (--watch enabled)</info>',
            $this->config->host, $this->config->port,
        ));
        $this->output->writeln('<info>Watching for file changes in src/, templates/, config/...</info>');

        $restartCount = 0;

        do {
            $server = $this->createAndStartServer();

            $restartRequested = false;

            $fsWatchWatcher = new FsWatchWatcher(
                directories: $watchDirs,
                onChange: function () use ($server, &$restartRequested): void {
                    $restartRequested = true;
                    $server->stop();
                },
            );

            $fsWatchWatcher->start();

            $this->output->writeln('<info>Press Ctrl+C to stop the server.</info>');

            trapSignal([\SIGINT, \SIGTERM]);

            $fsWatchWatcher->stop();
            $server->stop();

            if ($restartRequested) {
                ++$restartCount;
                $this->output->writeln(\sprintf(
                    '<info>File change detected, restarting (restart #%d)...</info>',
                    $restartCount,
                ));

                // Replace the entire process to get a fresh kernel and fresh PHP state.
                // This ensures new/changed PHP files, config YAML, etc. are picked up.
                $this->execSelf();
                // Never reached if pcntl_exec succeeds
            }
        } while ($restartRequested);

        $this->output->writeln('<info>Server stopped.</info>');

        return 0;
    }

    private function createAndStartServer(): SocketHttpServer
    {
        $serverFactory = new HttpServerFactory(
            logger: $this->logger,
            serverSocketFactory: new ResourceServerSocketFactory(),
            config: $this->config,
        );

        $server = $serverFactory->createHttpServer();
        $bindContext = $serverFactory->createBindContext();

        $handlerFactory = new HandlerChainFactory();

        $errorRenderer = $this->tryGetErrorRenderer();

        $resetter = $this->container->get('amphp.request_resetter');

        $handler = $handlerFactory->build(
            config: $this->config,
            server: $server,
            logger: $this->logger,
            kernel: $this->kernel,
            errorRenderer: $errorRenderer,
            resetter: $resetter,
            container: $this->container,
            onShutdownRequested: null,
        );

        $server->expose("{$this->config->host}:{$this->config->port}", $bindContext);
        $server->start($handler, new DefaultErrorHandler());

        return $server;
    }

    private function tryGetErrorRenderer(): ?\Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface
    {
        if (!$this->container->has('error_renderer')) {
            return null;
        }

        try {
            return $this->container->get('error_renderer');
        } catch (\Throwable) {
            return null;
        }
    }

    private function execSelf(): never
    {
        $phpBinary = \PHP_BINARY;
        $argv = $_SERVER['argv'] ?? [];

        // Stop the event loop so Revolt does not prevent pcntl_exec from working
        try {
            \Revolt\EventLoop::stop();
        } catch (\Throwable) {
            // Ignore
        }

        \pcntl_exec($phpBinary, $argv);

        // If pcntl_exec returns, it failed
        $this->output->writeln(\sprintf(
            '<error>Failed to restart: pcntl_exec("%s", ["%s"]) failed. Is ext-pcntl installed?</error>',
            $phpBinary,
            \implode('", "', $argv),
        ));

        exit(1);
    }
}

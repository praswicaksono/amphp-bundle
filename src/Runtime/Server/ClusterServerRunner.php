<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

use Amp\ByteStream;
use Amp\Cluster\Cluster;
use Amp\Cluster\ClusterWatcher;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\TimeoutCancellation;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Revolt\EventLoop;
use Symfony\Component\Console\Output\OutputInterface;

final class ClusterServerRunner implements ServerRunnerInterface
{
    /** Exit code indicating the worker should be restarted (max-requests reached). */
    private const int EXIT_RESTART = 250;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly ServerConfig $config,
    ) {}

    public function run(): int
    {
        $this->propagateEnvOverrides();

        $workerPath = $this->config->projectDir . '/vendor/bin/cluster-worker.php';

        if (!\is_file($workerPath)) {
            $this->output->writeln(\sprintf(
                '<error>Worker script not found at %s. Run "composer install" first.</error>',
                $workerPath,
            ));

            return 1;
        }

        $this->output->writeln(\sprintf(
            '<info>Starting AMPHP cluster with %d worker(s) on %s:%d (max %d requests each)</info>',
            $this->config->workers, $this->config->host, $this->config->port, $this->config->maxRequests,
        ));

        $watcherLogger = $this->createWatcherLogger();
        $watcher = new ClusterWatcher($workerPath, $watcherLogger);

        $this->registerStopSignal($watcher);
        $this->registerRestartSignal($watcher);

        $watcher->start($this->config->workers);

        $metricsServer = $this->startMetricsServer($watcherLogger);

        foreach ($watcher->getMessageIterator() as $message) {
            $data = $message->getData();
            $id = $message->getWorker()->getId();

            if (\is_scalar($data) || $data instanceof \Stringable) {
                $watcherLogger->info(\sprintf('Received message from worker %d: %s', $id, (string) $data));
            } else {
                $watcherLogger->notice(\sprintf(
                    'Received non-printable message from worker %d of type %s',
                    $id,
                    \get_debug_type($data),
                ));
            }
        }

        if ($metricsServer !== null) {
            try { $metricsServer->stop(); } catch (\Throwable) { /**/ }
        }

        $this->output->writeln('<info>Cluster stopped.</info>');

        return 0;
    }

    public function getServerConfig(): ServerConfig
    {
        return $this->config;
    }

    private function propagateEnvOverrides(): void
    {
        // Only CLI-overridable values need to reach the worker processes.
        // Everything else (TLS, timeouts, static files, etc.) is read from
        // the worker's own booted Symfony kernel container.
        \putenv("AMPHP_HOST={$this->config->host}");
        \putenv("AMPHP_PORT={$this->config->port}");
        \putenv("AMPHP_MAX_REQUESTS={$this->config->maxRequests}");
        \putenv("AMPHP_SHUTDOWN_TIMEOUT={$this->config->shutdownTimeout}");
        \putenv("AMPHP_WORKERS={$this->config->workers}");
    }

    private function registerStopSignal(ClusterWatcher $watcher): void
    {
        try {
            $stopHandler = static function (string $watcherId, int $signalNumber) use ($watcher): void {
                EventLoop::cancel($watcherId);
                $watcher->stop(new TimeoutCancellation(5));
            };

            foreach (Cluster::getSignalList() as $signo) {
                EventLoop::unreference(EventLoop::onSignal($signo, $stopHandler));
            }
        } catch (EventLoop\UnsupportedFeatureException) {
            // Signal handling not supported
        }
    }

    private function registerRestartSignal(ClusterWatcher $watcher): void
    {
        try {
            $restartHandler = static function (string $watcherId) use ($watcher): void {
                EventLoop::disable($watcherId);
                $watcher->restart();
                EventLoop::enable($watcherId);
            };

            EventLoop::unreference(EventLoop::onSignal(\defined('SIGUSR1') ? \SIGUSR1 : 10, $restartHandler));
            EventLoop::unreference(EventLoop::onSignal(\defined('SIGHUP') ? \SIGHUP : 1, $restartHandler));
        } catch (EventLoop\UnsupportedFeatureException) {
            // Signal handling not supported
        }
    }

    private function createWatcherLogger(): Logger
    {
        $logHandler = new StreamHandler(ByteStream\getStdout());
        $logHandler->setFormatter(new ConsoleFormatter());
        $logHandler->pushProcessor(new PsrLogMessageProcessor());
        $logHandler->setLevel(\Monolog\Level::Info);

        $logger = new Logger('cluster');
        $logger->pushHandler($logHandler);
        $logger->useLoggingLoopDetection(false);

        return $logger;
    }

    private function startMetricsServer(Logger $watcherLogger): ?SocketHttpServer
    {
        try {
            $metricsServer = new SocketHttpServer(
                logger: $watcherLogger,
                serverSocketFactory: new ResourceServerSocketFactory(),
                clientFactory: new SocketClientFactory($watcherLogger),
            );
            $metricsServer->expose(new InternetAddress('127.0.0.1', 8081));
            $metricsServer->start(new class implements \Amp\Http\Server\RequestHandler {
                public function handleRequest(\Amp\Http\Server\Request $request): \Amp\Http\Server\Response
                {
                    if ($request->getUri()->getPath() === '/metrics') {
                        return new \Amp\Http\Server\Response(
                            status: \Amp\Http\HttpStatus::OK,
                            headers: ['content-type' => 'text/plain; version=0.0.4'],
                            body: "# no metrics\n",
                        );
                    }

                    return new \Amp\Http\Server\Response(
                        status: \Amp\Http\HttpStatus::NOT_FOUND,
                        headers: ['content-type' => 'application/json'],
                        body: \json_encode(['error' => 'Not found'], \JSON_THROW_ON_ERROR),
                    );
                }
            }, new DefaultErrorHandler());

            $watcherLogger->info('Watcher metrics server listening on 127.0.0.1:8081/metrics');

            return $metricsServer;
        } catch (\Throwable $e) {
            $watcherLogger->warning('Failed to start watcher metrics server: {message}', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

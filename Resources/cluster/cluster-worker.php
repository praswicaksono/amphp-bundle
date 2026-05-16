#!/usr/bin/env php
<?php

declare(strict_types=1);

use Amp\Cluster\Cluster;
use Amp\Http\Server\DefaultErrorHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use PRSW\AmphpBundle\Runtime\Bootstrap\KernelBootstrapper;
use PRSW\AmphpBundle\Runtime\Server\HandlerChainFactory;
use PRSW\AmphpBundle\Runtime\Server\HttpServerFactory;
use PRSW\AmphpBundle\Runtime\Server\ServerConfig;

/** @psalm-suppress UnresolvableInclude */
$autoload = require __DIR__ . '/../../../../vendor/autoload.php';

\define('AMPHP_WORKER', 1);

// ---- Performance: disable assertions in production -------------------------
if (!((($tmp = \getenv('APP_DEBUG')) !== false) ? $tmp : '0') || \getenv('APP_DEBUG') === '0') {
    @\ini_set('zend.assertions', '-1');
}

// ---- Load .env file -------------------------------------------------------
$projectDir = \dirname(__DIR__, 4);
if (\class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
    new \Symfony\Component\Dotenv\Dotenv()->bootEnv($projectDir . '/.env');
}

// ---- Resolve CLI-overridable values (env vars set by ClusterServerRunner) --
// Use !== false checks instead of ?: to avoid falsy-value bugs (e.g. '0' is falsy).
$host = (($tmp = \getenv('AMPHP_HOST')) !== false) ? $tmp : '127.0.0.1';
$port = (int) ((($tmp = \getenv('AMPHP_PORT')) !== false) ? $tmp : '8080');
$maxRequests = (int) ((($tmp = \getenv('AMPHP_MAX_REQUESTS')) !== false) ? $tmp : '1000');
$shutdownTimeout = (float) ((($tmp = \getenv('AMPHP_SHUTDOWN_TIMEOUT')) !== false) ? $tmp : '5');

$env = (($tmp = \getenv('APP_ENV')) !== false) ? $tmp : 'prod';
$debug = \getenv('APP_DEBUG') !== false && (\getenv('APP_DEBUG') === '1' || \getenv('APP_DEBUG') === 'true');

// ---- Bootstrap Symfony kernel ---------------------------------------------
/** @var callable(array): \Symfony\Component\HttpKernel\KernelInterface $kernelFactory */
$kernelFactory = require $projectDir . '/public/index.php';
$kernel = $kernelFactory(['APP_ENV' => $env, 'APP_DEBUG' => $debug]);

KernelBootstrapper::bootAndRunHooks($kernel);

$container = $kernel->getContainer();
$logger = $container->get('logger');

try {
    $errorRenderer = $container->get('error_renderer');
} catch (\Throwable) {
    $errorRenderer = null;
}

$resetter = $container->get('amphp.request_resetter');

if (Cluster::isWorker() && $logger instanceof \Monolog\Logger && !$debug) {
    $logger->pushProcessor(function (\Monolog\LogRecord $record): \Monolog\LogRecord {
        if (\array_key_exists('exception', $record->context) && $record->context['exception'] instanceof \Throwable) {
            $e = $record->context['exception'];
            $context = $record->context;
            $context['exception'] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];

            return $record->with(context: $context);
        }

        return $record;
    });
}

$config = buildServerConfig($container, $projectDir, $host, $port, $maxRequests, $shutdownTimeout);

$isCluster = Cluster::isWorker();

$serverFactory = new HttpServerFactory(
    logger: $logger,
    serverSocketFactory: Cluster::getServerSocketFactory(),
    config: $config,
);

$server = $serverFactory->createHttpServer();
$bindContext = $serverFactory->createBindContext();

$server->expose("{$host}:{$port}", $bindContext);

$onShutdownRequested = static function () use ($server): void {
    $server->stop();
    Cluster::shutdown();
};

$handlerFactory = new HandlerChainFactory();
$handler = $handlerFactory->build(
    config: $config,
    server: $server,
    logger: $logger,
    kernel: $kernel,
    errorRenderer: $errorRenderer,
    resetter: $resetter,
    container: $container,
    onShutdownRequested: $onShutdownRequested,
);

$server->start($handler, new DefaultErrorHandler());

// ---- Await termination ---------------------------------------------------
if ($isCluster) {
    Cluster::awaitTermination();

    try {
        $server->stop();
    } catch (\Error) {
        // Server already stopping or stopped
    }
} else {
    \Amp\trapSignal([\SIGINT, \SIGTERM]);
    $server->stop();
}

function buildServerConfig(ContainerInterface $container, string $projectDir, string $host, int $port, int $maxRequests, float $shutdownTimeout): ServerConfig
{
    return new ServerConfig(
        devMode: false,
        watchMode: false,
        projectDir: $projectDir,
        host: $host,
        port: $port,
        maxRequests: $maxRequests,
        workers: (int) ((($tmp = \getenv('AMPHP_WORKERS')) !== false) ? $tmp : '0'),
        shutdownTimeout: $shutdownTimeout,
        tlsEnabled: (bool) $container->getParameter('amphp.tls.enabled'),
        tlsMinVersion: (string) $container->getParameter('amphp.tls.min_version'),
        tlsSecurityLevel: (int) $container->getParameter('amphp.tls.security_level'),
        tlsAlpnProtocols: (array) $container->getParameter('amphp.tls.alpn_protocols'),
        tlsVerifyPeer: (bool) $container->getParameter('amphp.tls.verify_peer'),
        tlsVerifyPeerName: (bool) $container->getParameter('amphp.tls.verify_peer_name'),
        tlsVerifyDepth: (int) $container->getParameter('amphp.tls.verify_depth'),
        tlsCapturePeer: (bool) $container->getParameter('amphp.tls.capture_peer'),
        tlsSniCerts: (array) $container->getParameter('amphp.tls.sni_certs'),
        staticFilesEnabled: (bool) $container->getParameter('amphp.static_files.enabled'),
        staticFilesPublicDir: resolvePublicDir($container, $projectDir),
        staticFilesIndexes: (array) $container->getParameter('amphp.static_files.indexes'),
        staticFilesExpiresPeriod: (int) $container->getParameter('amphp.static_files.expires_period'),
        requestTimeout: (int) $container->getParameter('amphp.request_timeout'),
        headerTimeout: (int) $container->getParameter('amphp.header_timeout'),
        bodyTimeout: (int) $container->getParameter('amphp.body_timeout'),
        maxBodySize: (int) $container->getParameter('amphp.max_body_size'),
        websocketEnabled: (bool) ($container->hasParameter('amphp.websocket.enabled')
            ? $container->getParameter('amphp.websocket.enabled')
            : true),
        websocketEndpoints: (array) ($container->hasParameter('amphp.websocket.endpoints')
            ? $container->getParameter('amphp.websocket.endpoints')
            : []),
        // Optional (nullable) parameters
        tlsCertFile: paramOrNull($container, 'amphp.tls.cert_file'),
        tlsKeyFile: paramOrNull($container, 'amphp.tls.key_file'),
        tlsPassphrase: paramOrNull($container, 'amphp.tls.passphrase'),
        tlsCiphers: paramOrNull($container, 'amphp.tls.ciphers'),
        tlsCaFile: paramOrNull($container, 'amphp.tls.ca_file'),
        tlsCaPath: paramOrNull($container, 'amphp.tls.ca_path'),
    );
}

function paramOrNull(ContainerInterface $container, string $param): ?string
{
    $value = $container->getParameter($param);

    return ($value !== null && $value !== '') ? (string) $value : null;
}

function resolvePublicDir(ContainerInterface $container, string $projectDir): string
{
    $configured = $container->getParameter('amphp.static_files.public_dir');

    return ($configured !== null && $configured !== '') ? (string) $configured : $projectDir . '/public';
}

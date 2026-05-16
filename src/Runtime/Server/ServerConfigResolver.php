<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;

use function Amp\Cluster\countCpuCores;

final readonly class ServerConfigResolver
{
    public function __construct(
        private ContainerInterface $container,
        private InputInterface $input,
    ) {}

    public function resolve(bool $devMode, bool $watchMode): ServerConfig
    {
        $host = $this->resolveHost();
        $port = $this->resolvePort();
        $maxRequests = $this->resolveMaxRequests();
        $workers = $this->resolveWorkers();
        $shutdownTimeout = $this->resolveShutdownTimeout();

        return new ServerConfig(
            devMode: $devMode,
            watchMode: $watchMode,
            projectDir: (string) $this->container->getParameter('kernel.project_dir'),
            host: $host,
            port: $port,
            maxRequests: $maxRequests,
            workers: $workers,
            shutdownTimeout: $shutdownTimeout,
            tlsEnabled: (bool) $this->container->getParameter('amphp.tls.enabled'),
            tlsMinVersion: (string) $this->container->getParameter('amphp.tls.min_version'),
            tlsSecurityLevel: (int) $this->container->getParameter('amphp.tls.security_level'),
            tlsAlpnProtocols: (array) $this->container->getParameter('amphp.tls.alpn_protocols'),
            tlsVerifyPeer: (bool) $this->container->getParameter('amphp.tls.verify_peer'),
            tlsVerifyPeerName: (bool) $this->container->getParameter('amphp.tls.verify_peer_name'),
            tlsVerifyDepth: (int) $this->container->getParameter('amphp.tls.verify_depth'),
            tlsCapturePeer: (bool) $this->container->getParameter('amphp.tls.capture_peer'),
            tlsSniCerts: (array) $this->container->getParameter('amphp.tls.sni_certs'),
            staticFilesEnabled: (bool) $this->container->getParameter('amphp.static_files.enabled'),
            staticFilesPublicDir: $this->resolveStaticFilesPublicDir(),
            staticFilesIndexes: (array) $this->container->getParameter('amphp.static_files.indexes'),
            staticFilesExpiresPeriod: (int) $this->container->getParameter('amphp.static_files.expires_period'),
            requestTimeout: (int) $this->container->getParameter('amphp.request_timeout'),
            headerTimeout: (int) $this->container->getParameter('amphp.header_timeout'),
            bodyTimeout: (int) $this->container->getParameter('amphp.body_timeout'),
            maxBodySize: (int) $this->container->getParameter('amphp.max_body_size'),
            websocketEnabled: (bool) ($this->container->hasParameter('amphp.websocket.enabled')
                ? $this->container->getParameter('amphp.websocket.enabled')
                : true),
            websocketEndpoints: (array) ($this->container->hasParameter('amphp.websocket.endpoints')
                ? $this->container->getParameter('amphp.websocket.endpoints')
                : []),
            // Optional (nullable) parameters
            tlsCertFile: $this->stringOrNull('amphp.tls.cert_file'),
            tlsKeyFile: $this->stringOrNull('amphp.tls.key_file'),
            tlsPassphrase: $this->stringOrNull('amphp.tls.passphrase'),
            tlsCiphers: $this->stringOrNull('amphp.tls.ciphers'),
            tlsCaFile: $this->stringOrNull('amphp.tls.ca_file'),
            tlsCaPath: $this->stringOrNull('amphp.tls.ca_path'),
        );
    }

    private function resolveHost(): string
    {
        $cliValue = $this->input->getOption('host');

        return $cliValue !== null ? (string) $cliValue : (string) $this->container->getParameter('amphp.host');
    }

    private function resolvePort(): int
    {
        $cliValue = $this->input->getOption('port');

        return $cliValue !== null ? (int) $cliValue : $this->container->getParameter('amphp.port');
    }

    private function resolveMaxRequests(): int
    {
        $cliValue = $this->input->getOption('max-requests');

        return $cliValue !== null ? \max(0, (int) $cliValue) : \max(0, $this->container->getParameter('amphp.max_requests'));
    }

    private function resolveWorkers(): int
    {
        $cliValue = $this->input->getOption('workers');

        if ($cliValue !== null) {
            $value = (int) $cliValue;

            return $value >= 1 ? $value : countCpuCores();
        }

        $configValue = $this->container->getParameter('amphp.workers');

        if (\is_int($configValue) && $configValue >= 1) {
            return $configValue;
        }

        return countCpuCores();
    }

    private function resolveShutdownTimeout(): float
    {
        $cliValue = $this->input->getOption('shutdown-timeout');

        return $cliValue !== null ? (float) $cliValue : (float) $this->container->getParameter('amphp.shutdown_timeout');
    }

    private function resolveStaticFilesPublicDir(): string
    {
        $configured = $this->container->getParameter('amphp.static_files.public_dir');

        if ($configured !== null && $configured !== '') {
            return (string) $configured;
        }

        return (string) $this->container->getParameter('kernel.project_dir') . '/public';
    }

    private function stringOrNull(string $param): ?string
    {
        $value = $this->container->getParameter($param);

        return ($value !== null && $value !== '') ? (string) $value : null;
    }
}

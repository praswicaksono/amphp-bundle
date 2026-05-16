<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\ServerSocketFactory;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use Psr\Log\LoggerInterface as PsrLogger;

final class HttpServerFactory
{
    /** @var array<string, int> */
    private const array TLS_VERSION_MAP = [
        'TLSv1.0' => ServerTlsContext::TLSv1_0,
        'TLSv1.1' => ServerTlsContext::TLSv1_1,
        'TLSv1.2' => ServerTlsContext::TLSv1_2,
        'TLSv1.3' => ServerTlsContext::TLSv1_3,
    ];

    public function __construct(
        private readonly PsrLogger $logger,
        private readonly ServerSocketFactory $serverSocketFactory,
        private readonly ServerConfig $config,
    ) {}

    public function createHttpServer(): SocketHttpServer
    {
        $httpDriverFactory = new DefaultHttpDriverFactory(
            logger: $this->logger,
            streamTimeout: $this->config->bodyTimeout,
            connectionTimeout: $this->config->headerTimeout,
            bodySizeLimit: $this->config->maxBodySize,
        );

        return new SocketHttpServer(
            logger: $this->logger,
            serverSocketFactory: $this->serverSocketFactory,
            clientFactory: new SocketClientFactory($this->logger),
            httpDriverFactory: $httpDriverFactory,
        );
    }

    public function createBindContext(): ?BindContext
    {
        if (!$this->config->tlsEnabled) {
            return null;
        }

        $certFile = $this->config->tlsCertFile;
        if ($certFile === null || $certFile === '') {
            throw new \RuntimeException('tls.cert_file must be configured when TLS is enabled');
        }

        $tlsContext = new ServerTlsContext();
        $tlsContext = $tlsContext->withDefaultCertificate(
            new Certificate($certFile, $this->config->tlsKeyFile, $this->config->tlsPassphrase),
        );

        if (isset(self::TLS_VERSION_MAP[$this->config->tlsMinVersion])) {
            $tlsContext = $tlsContext->withMinimumVersion(self::TLS_VERSION_MAP[$this->config->tlsMinVersion]);
        }

        if ($this->config->tlsCiphers !== null && $this->config->tlsCiphers !== '') {
            $tlsContext = $tlsContext->withCiphers($this->config->tlsCiphers);
        }

        if ($this->config->tlsSecurityLevel >= 0 && $this->config->tlsSecurityLevel <= 5) {
            try {
                $tlsContext = $tlsContext->withSecurityLevel($this->config->tlsSecurityLevel);
            } catch (\Throwable $e) {
                $this->logger->critical($e->getMessage());
                throw new $e;
            }
        }

        if ($this->config->tlsAlpnProtocols !== []) {
            $tlsContext = $tlsContext->withApplicationLayerProtocols($this->config->tlsAlpnProtocols);
        }

        if ($this->config->tlsVerifyPeer) {
            $tlsContext = $tlsContext->withPeerVerification();
        }

        if ($this->config->tlsVerifyPeerName) {
            $tlsContext = $tlsContext->withPeerNameVerification();
        }

        $tlsContext = $tlsContext->withVerificationDepth($this->config->tlsVerifyDepth);

        if ($this->config->tlsCaFile !== null && $this->config->tlsCaFile !== '') {
            $tlsContext = $tlsContext->withCaFile($this->config->tlsCaFile);
        }

        if ($this->config->tlsCaPath !== null && $this->config->tlsCaPath !== '') {
            $tlsContext = $tlsContext->withCaPath($this->config->tlsCaPath);
        }

        if ($this->config->tlsCapturePeer) {
            $tlsContext = $tlsContext->withPeerCapturing();
        }

        if ($this->config->tlsSniCerts !== []) {
            $certificates = [];
            foreach ($this->config->tlsSniCerts as $hostname => $certConfig) {
                $certificates[$hostname] = new Certificate(
                    $certConfig['cert_file'],
                    $certConfig['key_file'] ?? null,
                    $certConfig['passphrase'] ?? null,
                );
            }
            if ($certificates !== []) {
                $tlsContext = $tlsContext->withCertificates($certificates);
            }
        }

        return new BindContext()->withTlsContext($tlsContext);
    }
}

<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Runtime\Server;

final readonly class ServerConfig
{
    public function __construct(
        // Required (non-nullable) parameters
        public bool $devMode,
        public bool $watchMode,
        public string $projectDir,
        public string $host,
        public int $port,
        public int $maxRequests,
        public int $workers,
        public float $shutdownTimeout,
        public bool $tlsEnabled,
        public string $tlsMinVersion,
        public int $tlsSecurityLevel,
        public array $tlsAlpnProtocols,
        public bool $tlsVerifyPeer,
        public bool $tlsVerifyPeerName,
        public int $tlsVerifyDepth,
        public bool $tlsCapturePeer,
        public array $tlsSniCerts,
        public bool $staticFilesEnabled,
        public string $staticFilesPublicDir,
        public array $staticFilesIndexes,
        public int $staticFilesExpiresPeriod,
        public int $requestTimeout,
        public int $headerTimeout,
        public int $bodyTimeout,
        public int $maxBodySize,
        public bool $websocketEnabled,
        public array $websocketEndpoints,
        // Optional (nullable) parameters — sorted last
        public ?string $tlsCertFile = null,
        public ?string $tlsKeyFile = null,
        public ?string $tlsPassphrase = null,
        public ?string $tlsCiphers = null,
        public ?string $tlsCaFile = null,
        public ?string $tlsCaPath = null,
    ) {}
}

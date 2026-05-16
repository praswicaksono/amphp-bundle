<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Mailer;

use Amp\ByteStream\BufferedReader;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

final class AmpSocketStream extends AbstractStream
{
    private ?Socket $socket = null;
    private ?BufferedReader $reader = null;

    private string $host = 'localhost';
    private int $port = 465;
    private float $timeout;
    private bool $tls = true;
    private ?string $sourceIp = null;
    private array $streamContextOptions = [];

    /**
     * Own debug buffer. The parent AbstractStream has a private $debug property,
     * so we maintain our own to avoid conflicts.
     */
    private string $debug = '';

    public function setTimeout(float $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function getTimeout(): float
    {
        return $this->timeout ?? (float) \ini_get('default_socket_timeout');
    }

    public function setHost(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function disableTls(): static
    {
        $this->tls = false;

        return $this;
    }

    public function isTLS(): bool
    {
        return $this->tls;
    }

    public function setStreamOptions(array $options): static
    {
        $this->streamContextOptions = $options;

        return $this;
    }

    public function getStreamOptions(): array
    {
        return $this->streamContextOptions;
    }

    public function setSourceIp(string $ip): static
    {
        $this->sourceIp = $ip;

        return $this;
    }

    public function getSourceIp(): ?string
    {
        return $this->sourceIp;
    }

    public function initialize(): void
    {
        $uri = \sprintf('tcp://%s:%d', $this->host, $this->port);

        $connectContext = new ConnectContext()
            ->withConnectTimeout($this->getTimeout())
            ->withoutTlsContext();

        if (null !== $this->sourceIp) {
            $connectContext = $connectContext->withBindTo($this->sourceIp . ':0');
        }

        // Apply SSL context options if provided (e.g., verify_peer, peer_fingerprint)
        if ($this->streamContextOptions) {
            $tlsContext = $this->buildTlsContext();
            if (null !== $tlsContext) {
                $connectContext = $connectContext->withTlsContext($tlsContext);
            }
        }

        try {
            $this->socket = \Amp\Socket\connect($uri, $connectContext);
        } catch (\Throwable $e) {
            throw new TransportException(
                \sprintf('Connection could not be established with host "%s:%d": ', $this->host, $this->port)
                    . $e->getMessage(),
                0,
                $e,
            );
        }

        // If TLS is requested (e.g., smtps://), enable it immediately after connect
        if ($this->tls) {
            try {
                $this->socket->setupTls();
            } catch (\Throwable $e) {
                $this->socket->close();
                $this->socket = null;
                throw new TransportException(
                    \sprintf('TLS handshake failed with host "%s:%d": ', $this->host, $this->port) . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        $this->reader = new BufferedReader($this->socket);
    }

    public function startTLS(): bool
    {
        if (null === $this->socket) {
            throw new TransportException('Cannot start TLS: socket is not connected.');
        }

        try {
            $this->socket->setupTls();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function write(string $bytes, bool $debug = true): void
    {
        if ($debug) {
            $timestamp = new \DateTimeImmutable()->format('Y-m-d\TH:i:s.up');
            foreach (explode("\n", trim($bytes)) as $line) {
                $this->debug .= \sprintf("[%s] > %s\n", $timestamp, $line);
            }
        }

        if (null === $this->socket) {
            throw new TransportException('Unable to write bytes on the wire: socket is not connected.');
        }

        try {
            $this->socket->write($bytes);
        } catch (\Throwable $e) {
            throw new TransportException('Unable to write bytes on the wire: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Returns and clears the debug log.
     *
     * Overridden because the parent's $debug property is private and inaccessible
     * from this subclass. We maintain our own debug buffer instead.
     */
    public function getDebug(): string
    {
        $debug = $this->debug;
        $this->debug = '';

        return $debug;
    }

    public function flush(): void
    {
        // No-op: AMPHP's socket write is non-blocking and unbuffered at this level.
    }

    public function readLine(): string
    {
        if (null === $this->reader) {
            throw new TransportException('Unable to read from connection: stream is not initialized.');
        }

        try {
            $line = $this->reader->readUntil("\n");
        } catch (\Throwable $e) {
            throw new TransportException(
                \sprintf('Connection to "%s" has been closed unexpectedly.', $this->getReadConnectionDescription()),
                0,
                $e,
            );
        }

        $this->debug .= \sprintf('[%s] < %s', new \DateTimeImmutable()->format('Y-m-d\TH:i:s.up'), $line);

        return $line;
    }

    public function terminate(): void
    {
        if (null !== $this->socket) {
            $this->socket->close();
            $this->socket = null;
        }

        $this->reader = null;

        parent::terminate();
    }

    protected function getReadConnectionDescription(): string
    {
        return \sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * Build a ClientTlsContext from the stream context options.
     */
    private function buildTlsContext(): ?ClientTlsContext
    {
        if (!$this->streamContextOptions) {
            return null;
        }

        $tlsContext = new ClientTlsContext($this->host);

        $sslOptions = $this->streamContextOptions['ssl'] ?? [];

        if (false === ($sslOptions['verify_peer'] ?? true)) {
            $tlsContext = $tlsContext->withoutPeerVerification();
        }

        if (false === ($sslOptions['verify_peer_name'] ?? true)) {
            $tlsContext = $tlsContext->withoutPeerNameVerification();
        }

        if (isset($sslOptions['peer_fingerprint'])) {
            $tlsContext = $tlsContext->withoutPeerVerification();
        }

        if (isset($sslOptions['cafile'])) {
            $tlsContext = $tlsContext->withCaFile($sslOptions['cafile']);
        }

        if (isset($sslOptions['capath'])) {
            $tlsContext = $tlsContext->withCaPath($sslOptions['capath']);
        }

        return $tlsContext;
    }
}

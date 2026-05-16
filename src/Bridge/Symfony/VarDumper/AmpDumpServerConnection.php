<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\VarDumper;

use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Dumper\ContextProvider\ContextProviderInterface;

use function Amp\Socket\socketConnector;

final class AmpDumpServerConnection
{
    private string $host;
    private ?Socket $socket = null;

    /**
     * @param ContextProviderInterface[] $contextProviders
     */
    public function __construct(
        string $host,
        private readonly array $contextProviders = [],
    ) {
        if (!str_contains($host, '://')) {
            $host = 'tcp://' . $host;
        }

        $this->host = $host;
    }

    public function getContextProviders(): array
    {
        return $this->contextProviders;
    }

    public function write(Data $data): bool
    {
        try {
            $uri = $this->host;

            // Parse the host URI for amphp/socket connect
            // Symfony uses 'tcp://host:port' or just 'host:port' format
            if (!str_starts_with($uri, 'tcp://')) {
                $uri = 'tcp://' . $uri;
            }

            if (!$this->socket) {
                $this->socket = socketConnector()->connect($uri, new ConnectContext());
            }

            $context = ['timestamp' => \microtime(true)];
            foreach ($this->contextProviders as $name => $provider) {
                $context[$name] = $provider->getContext();
            }
            $context = \array_filter($context);

            $encodedPayload = \base64_encode(\serialize([$data, $context])) . "\n";

            $this->socket->write($encodedPayload);

            return true;
        } catch (\Throwable) {
            // Connection failed or write failed — silently return false
            // so the caller falls back to local dump output.
            $this->socket = null;

            return false;
        }
    }
}

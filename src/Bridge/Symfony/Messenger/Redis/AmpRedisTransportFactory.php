<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Messenger\Redis;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transport factory that creates AMPHP-based Redis transports.
 * Uses amphp/redis instead of ext-redis.
 *
 * Supports DSN: amphp-redis://localhost:6379/messages/group/consumer
 */
final class AmpRedisTransportFactory implements TransportFactoryInterface
{
    public function createTransport(
        #[\SensitiveParameter]
        string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        unset($options['transport_name']);

        return new AmpRedisTransport(AmpRedisConnection::fromDsn($dsn, $options), $serializer);
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'amphp-redis://') || str_starts_with($dsn, 'amphp-rediss://');
    }
}

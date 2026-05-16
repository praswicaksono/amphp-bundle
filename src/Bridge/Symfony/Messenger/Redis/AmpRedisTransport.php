<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Messenger\Redis;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Async Redis transport using amphp/redis.
 *
 * @author Alexander Schranz <alexander@sulu.io>
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class AmpRedisTransport implements
    TransportInterface,
    SetupableTransportInterface,
    MessageCountAwareInterface,
    KeepaliveReceiverInterface
{
    private SerializerInterface $serializer;

    public function __construct(
        private AmpRedisConnection $connection,
        ?SerializerInterface $serializer = null,
    ) {
        $this->serializer = $serializer ?? new PhpSerializer();
    }

    public function get(): iterable
    {
        $message = $this->connection->get();

        if (null === $message) {
            return [];
        }

        if (null === $message['data']) {
            try {
                $this->connection->reject($message['id']);
            } catch (\Symfony\Component\Messenger\Exception\TransportException) {
            }

            return $this->get();
        }

        $redisEnvelope = \json_decode($message['data']['message'] ?? '', true);

        if (null === $redisEnvelope) {
            return [];
        }

        try {
            if (\array_key_exists('body', $redisEnvelope) && \array_key_exists('headers', $redisEnvelope)) {
                $envelope = $this->serializer->decode([
                    'body' => $redisEnvelope['body'],
                    'headers' => $redisEnvelope['headers'],
                ]);
            } else {
                $envelope = $this->serializer->decode($redisEnvelope);
            }
        } catch (\Symfony\Component\Messenger\Exception\MessageDecodingFailedException $exception) {
            $this->connection->reject($message['id']);

            throw $exception;
        }

        return [$envelope->withoutAll(TransportMessageIdStamp::class)->with(
            new \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceivedStamp($message['id']),
            new TransportMessageIdStamp($message['id']),
        )];
    }

    public function ack(Envelope $envelope): void
    {
        $this->connection->ack($this->findRedisReceivedStamp($envelope)->getId());
    }

    public function reject(Envelope $envelope): void
    {
        $this->connection->reject($this->findRedisReceivedStamp($envelope)->getId());
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $this->connection->keepalive($this->findRedisReceivedStamp($envelope)->getId(), $seconds);
    }

    public function send(Envelope $envelope): Envelope
    {
        $encodedMessage = $this->serializer->encode($envelope);

        /** @var \Symfony\Component\Messenger\Stamp\DelayStamp|null $delayStamp */
        $delayStamp = $envelope->last(\Symfony\Component\Messenger\Stamp\DelayStamp::class);
        $delayInMs = null !== $delayStamp ? $delayStamp->getDelay() : 0;

        $id = $this->connection->add($encodedMessage['body'], $encodedMessage['headers'] ?? [], $delayInMs);

        return $envelope->with(new TransportMessageIdStamp($id));
    }

    public function setup(): void
    {
        $this->connection->setup();
    }

    public function getMessageCount(): int
    {
        return $this->connection->getMessageCount();
    }

    private function findRedisReceivedStamp(Envelope $envelope): \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceivedStamp
    {
        /** @var \Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceivedStamp|null $redisReceivedStamp */
        $redisReceivedStamp = $envelope->last(\Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceivedStamp::class);

        if (null === $redisReceivedStamp) {
            throw new \Symfony\Component\Messenger\Exception\LogicException(
                'No RedisReceivedStamp found on the Envelope.',
            );
        }

        return $redisReceivedStamp;
    }
}

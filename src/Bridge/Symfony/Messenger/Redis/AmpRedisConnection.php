<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Messenger\Redis;

use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * @internal
 */
final class AmpRedisConnection
{
    private const DEFAULT_OPTIONS = [
        'stream' => 'messages',
        'group' => 'symfony',
        'consumer' => 'consumer',
        'auto_setup' => true,
        'delete_after_ack' => true,
        'delete_after_reject' => true,
        'stream_max_entries' => 0,
        'redeliver_timeout' => 3600,
        'claim_interval' => 60_000,
        'dbindex' => 0,
        'timeout' => 0,
        'read_timeout' => 0,
    ];

    private \Amp\Redis\RedisClient $redis;
    private string $stream;
    private string $group;
    private string $consumer;
    private string $queue;
    private bool $autoSetup;
    private int $maxEntries;
    private int $redeliverTimeout;
    private float $nextClaim = 0.0;
    private float $claimInterval;
    private bool $deleteAfterAck;
    private bool $deleteAfterReject;
    private ?string $lastPendingMessageId = '0';
    /** @var array<string, true> */
    private array $inflightIds = [];

    public function __construct(object $redis, array $options)
    {
        if (!\class_exists(\Amp\Redis\RedisClient::class)) {
            throw new \RuntimeException('AMPHP Redis messenger transport requires "amphp/redis". '
            . 'Run: composer require amphp/redis');
        }

        if (!$redis instanceof \Amp\Redis\RedisClient) {
            throw new \InvalidArgumentException(\sprintf(
                'Expected instance of %s, got %s.',
                \Amp\Redis\RedisClient::class,
                $redis::class,
            ));
        }

        $options += self::DEFAULT_OPTIONS;

        foreach (['stream', 'group', 'consumer'] as $key) {
            if ('' === $options[$key]) {
                throw new InvalidArgumentException(\sprintf('"%s" should be configured, got an empty string.', $key));
            }
        }

        $this->redis = $redis;
        $this->stream = $options['stream'];
        $this->group = $options['group'];
        $this->consumer = $options['consumer'];
        $this->queue = $this->stream . '__queue';
        $this->autoSetup = $options['auto_setup'];
        $this->maxEntries = $options['stream_max_entries'];
        $this->deleteAfterAck = $options['delete_after_ack'];
        $this->deleteAfterReject = $options['delete_after_reject'];
        $this->redeliverTimeout = $options['redeliver_timeout'] * 1000;
        $this->claimInterval = $options['claim_interval'] / 1000;
    }

    public function get(): ?array
    {
        if ($this->autoSetup) {
            $this->setup();
        }

        $this->handleDelayedMessages();

        if (null !== ($message = $this->getPendingMessage())) {
            return $message;
        }

        if (null === $this->lastPendingMessageId && $this->nextClaim <= \microtime(true)) {
            $this->claimOldPendingMessages();

            if (null !== ($message = $this->getPendingMessage())) {
                return $message;
            }
        }

        return $this->getNewMessage();
    }

    public function ack(string $id): void
    {
        try {
            $acknowledged = $this->redis->execute('XACK', $this->stream, $this->group, $id);
            if ($this->deleteAfterAck) {
                $acknowledged = $this->redis->execute('XDEL', $this->stream, $id);
            }
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        if (!$acknowledged) {
            throw new TransportException(\sprintf('Could not acknowledge redis message "%s".', $id));
        }

        unset($this->inflightIds[$id]);
    }

    public function reject(string $id): void
    {
        try {
            $deleted = $this->redis->execute('XACK', $this->stream, $this->group, $id);
            if ($this->deleteAfterReject) {
                $deleted = $this->redis->execute('XDEL', $this->stream, $id) && $deleted;
            }
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        if (!$deleted) {
            throw new TransportException(\sprintf('Could not delete message "%s" from the redis stream.', $id));
        }

        unset($this->inflightIds[$id]);
    }

    public function add(string $body, array $headers, int $delayInMs = 0): string
    {
        if ($this->autoSetup) {
            $this->setup();
        }

        try {
            if ($delayInMs > 0) {
                $id = \base64_encode(\random_bytes(9));
                $message = \json_encode([
                    'body' => $body,
                    'headers' => $headers,
                    'uniqid' => $id,
                ]);

                if (false === $message) {
                    throw new TransportException(\json_last_error_msg());
                }

                $now = explode(' ', \microtime(), 2);
                $now[0] = \str_pad($delayInMs + \substr($now[0], 2, 3), 3, '0', \STR_PAD_LEFT);
                if (3 < \strlen($now[0])) {
                    $now[1] += \substr($now[0], 0, -3);
                    $now[0] = \substr($now[0], -3);

                    if (\is_float($now[1])) {
                        throw new TransportException("Message delay is too big: {$delayInMs}ms.");
                    }
                }

                $added = $this->rawCommand('ZADD', 'NX', $now[1] . $now[0], $message);
            } else {
                $message = \json_encode([
                    'body' => $body,
                    'headers' => $headers,
                ]);

                if (false === $message) {
                    throw new TransportException(\json_last_error_msg());
                }

                if ($this->maxEntries) {
                    $added = $this->redis->execute(
                        'XADD',
                        $this->stream,
                        'MAXLEN',
                        '~',
                        (string) $this->maxEntries,
                        '*',
                        'message',
                        $message,
                    );
                } else {
                    $added = $this->redis->execute('XADD', $this->stream, '*', 'message', $message);
                }

                $id = $added;
            }
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        if (!$added) {
            throw new TransportException('Could not add a message to the redis stream.');
        }

        return $id;
    }

    public function setup(): void
    {
        try {
            $this->redis->execute('XGROUP', 'CREATE', $this->stream, $this->group, '0', 'MKSTREAM');
        } catch (\Throwable) {
            // group might already exist, ignore
        }

        $this->autoSetup = false;
    }

    public function keepalive(string $id, ?int $seconds = null): void
    {
        if (null !== $seconds && $this->redeliverTimeout < $seconds) {
            throw new TransportException(\sprintf(
                'Redis redeliver_timeout (%ds) cannot be smaller than the keepalive interval (%ds).',
                $this->redeliverTimeout,
                $seconds,
            ));
        }

        try {
            $this->redis->execute('XCLAIM', $this->stream, $this->group, $this->consumer, '0', $id, 'JUSTID');
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    public function getMessageCount(): int
    {
        try {
            $groups = $this->redis->execute('XINFO', 'GROUPS', $this->stream);
            if (!\is_array($groups)) {
                return 0;
            }

            foreach ($groups as $group) {
                if (!\is_array($group)) {
                    continue;
                }
                // XINFO GROUPS returns arrays like [name, groupname, consumers, 0, pending, 0, ...]
                // or associative arrays on newer versions
                $idx = \array_search('name', $group, true);
                if (false !== $idx && isset($group[$idx + 1]) && $group[$idx + 1] === $this->group) {
                    $pendingIdx = \array_search('pending', $group, true);
                    if (false !== $pendingIdx && isset($group[$pendingIdx + 1])) {
                        return (int) $group[$pendingIdx + 1];
                    }

                    // Fallback: count pending via XPENDING
                    break;
                }
            }
        } catch (\Throwable) {
            return 0;
        }

        // Fallback count
        try {
            $pending = $this->redis->execute('XPENDING', $this->stream, $this->group);
            if (\is_array($pending)) {
                return (int) ($pending[0] ?? 0);
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    public function close(): void
    {
        // Amp Redis connections are managed by the ReconnectingRedisLink;
        // no explicit close needed.
    }

    private function handleDelayedMessages(): void
    {
        $now = \microtime();
        $now = \substr($now, 11) . \substr($now, 2, 3);

        $queuedMessageCount = $this->rawCommand('ZCOUNT', '0', $now) ?? 0;

        while ($queuedMessageCount--) {
            if (!($message = $this->rawCommand('ZPOPMIN', 1))) {
                break;
            }

            [$queuedMessage, $expiry] = $message;

            if (\strlen($expiry) === \strlen($now) ? $expiry > $now : \strlen($expiry) < \strlen($now)) {
                if (!$this->rawCommand('ZADD', 'NX', $expiry, $queuedMessage)) {
                    throw new TransportException('Could not add a message to the redis stream.');
                }

                break;
            }

            $decodedQueuedMessage = \json_decode($queuedMessage, true);
            $this->add(
                \array_key_exists('body', $decodedQueuedMessage) ? $decodedQueuedMessage['body'] : $queuedMessage,
                $decodedQueuedMessage['headers'] ?? [],
                0,
            );
        }
    }

    private function getPendingMessage(): ?array
    {
        if (null === $this->lastPendingMessageId) {
            return null;
        }

        while (true) {
            $messages = $this->xReadGroup($this->lastPendingMessageId);

            if (empty($messages[$this->stream])) {
                $this->lastPendingMessageId = null;

                return null;
            }

            /** @var string $key */
            $key = \array_key_first($messages[$this->stream]);
            $this->lastPendingMessageId = $key;

            if (isset($this->inflightIds[$key])) {
                continue;
            }

            $this->inflightIds[$key] = true;

            return [
                'id' => $key,
                'data' => $messages[$this->stream][$key],
            ];
        }
    }

    private function getNewMessage(): ?array
    {
        $messages = $this->xReadGroup('>');

        foreach ($messages[$this->stream] ?? [] as $key => $message) {
            $this->inflightIds[$key] = true;

            return [
                'id' => $key,
                'data' => $message,
            ];
        }

        return null;
    }

    private function xReadGroup(string $messageId): array
    {
        try {
            // XREADGROUP GROUP group consumer COUNT 1 BLOCK 0 STREAMS stream [>|id]
            $result = $this->redis->execute(
                'XREADGROUP',
                'GROUP',
                $this->group,
                $this->consumer,
                'COUNT',
                '1',
                'STREAMS',
                $this->stream,
                $messageId,
            );
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        if (null === $result) {
            return [];
        }

        // Result format: [[stream, [[id, [field, value, ...]], ...]]]
        if (!\is_array($result) || !isset($result[0])) {
            return [];
        }

        $streamData = $result[0];
        if (!\is_array($streamData) || !isset($streamData[1])) {
            return [];
        }

        $messages = $streamData[1];
        if (!\is_array($messages)) {
            return [];
        }

        $normalized = [];
        foreach ($messages as $msg) {
            if (!\is_array($msg) || 2 !== \count($msg)) {
                continue;
            }
            [$id, $fields] = $msg;
            // Flatten field-value pairs into an associative array
            $normalized[$id] = $this->flattenFields($fields);
        }

        return [$this->stream => $normalized];
    }

    private function claimOldPendingMessages(): void
    {
        try {
            $pendingMessages = $this->redis->execute('XPENDING', $this->stream, $this->group, '-', '+', '1');
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        if (!$pendingMessages || !\is_array($pendingMessages) || 0 === \count($pendingMessages)) {
            $this->nextClaim = \microtime(true) + $this->claimInterval;

            return;
        }

        $pendingMessage = $pendingMessages[0];
        // XPENDING returns [id, consumer, idle_ms, delivery_count]
        if (!\is_array($pendingMessage) || 4 !== \count($pendingMessage)) {
            $this->nextClaim = \microtime(true) + $this->claimInterval;

            return;
        }

        [$pendingId, $pendingConsumer, $pendingIdle, $pendingCount] = $pendingMessage;

        if ($pendingConsumer === $this->consumer) {
            $this->nextClaim = \microtime(true) + $this->claimInterval;

            return;
        }

        if ((int) $pendingIdle < $this->redeliverTimeout) {
            $this->nextClaim = \microtime(true) + $this->claimInterval;

            return;
        }

        try {
            $this->redis->execute(
                'XCLAIM',
                $this->stream,
                $this->group,
                $this->consumer,
                (string) $this->redeliverTimeout,
                $pendingId,
                'JUSTID',
            );
            $this->lastPendingMessageId = '0';
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    private function rawCommand(string $command, mixed ...$arguments): mixed
    {
        try {
            return $this->redis->execute($command, $this->queue, ...$arguments);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Flatten Redis stream field-value pairs into an associative array.
     *
     * Redis streams store data as [field1, value1, field2, value2, ...].
     * This converts them to [field1 => value1, field2 => value2, ...].
     */
    private function flattenFields(array $fields): array
    {
        $result = [];
        $count = \count($fields);
        for ($i = 0; ($i + 1) < $count; $i += 2) {
            $result[$fields[$i]] = $fields[$i + 1];
        }

        return $result;
    }

    /**
     * Parse a redis:// or amphp-redis:// DSN into options.
     */
    public static function fromDsn(#[\SensitiveParameter] string $dsn, array $options = []): self
    {
        if (!\class_exists(\Amp\Redis\RedisClient::class)) {
            throw new \RuntimeException('AMPHP Redis messenger transport requires "amphp/redis". '
            . 'Run: composer require amphp/redis');
        }

        if (!\str_starts_with($dsn, 'amphp-redis://') && !\str_starts_with($dsn, 'amphp-rediss://')) {
            // Re-format our custom DSN to a format amphp/redis understands
            // amphp-redis://user:pass@host:port/db?stream=messages&group=symfony&consumer=consumer
            $amphpDsn = \preg_replace('#^amphp-redis(s?)://#', 'redis$1://', $dsn);
            if (null === $amphpDsn) {
                throw new InvalidArgumentException('Invalid AMPHP Redis DSN.');
            }
        } else {
            $amphpDsn = $dsn;
        }

        if (false === ($params = \parse_url($amphpDsn))) {
            throw new InvalidArgumentException('The given AMPHP Redis DSN is invalid.');
        }

        $pathParts = explode('/', \ltrim($params['path'] ?? '', '/'));
        $options['stream'] = $pathParts[0] ?? $options['stream'] ?? self::DEFAULT_OPTIONS['stream'];
        $options['group'] = $pathParts[1] ?? $options['group'] ?? self::DEFAULT_OPTIONS['group'];
        $options['consumer'] = $pathParts[2] ?? $options['consumer'] ?? self::DEFAULT_OPTIONS['consumer'];

        if (isset($params['query'])) {
            \parse_str($params['query'], $query);
            $options = \array_merge($options, $query);
        }

        if (isset($params['host'])) {
            $options['host'] = $params['host'];
            $options['port'] = $params['port'] ?? 6379;
        }

        // Build the AMPHP Redis DSN for createRedisClient
        $host = $options['host'] ?? '127.0.0.1';
        $port = $options['port'] ?? 6379;
        $dbindex = $options['dbindex'] ?? 0;
        $user = $params['user'] ?? '';
        $pass = $params['pass'] ?? '';

        $dsnStr = \sprintf(
            '%s://%s%s%s:%d%s',
            \str_contains($amphpDsn, 'rediss://') ? 'rediss' : 'redis',
            $user || $pass ? \rawurlencode($user) . ($pass ? ':' . \rawurlencode($pass) : '') . '@' : '',
            $user || $pass ? '' : '',
            $host,
            $port,
            $dbindex > 0 ? '/' . $dbindex : '',
        );

        if ($dbindex > 0) {
            $dsnStr .= '/' . $dbindex;
        }

        $redis = \Amp\Redis\createRedisClient($dsnStr);

        return new self($redis, $options);
    }
}

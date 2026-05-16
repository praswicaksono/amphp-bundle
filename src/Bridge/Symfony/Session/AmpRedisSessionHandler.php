<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;

/**
 * @phpstan-type RedisOptions array{prefix?: string, ttl?: int<0, max>|\Closure|null}
 */
final class AmpRedisSessionHandler extends AbstractSessionHandler
{
    private const int LOCK_RETRY_INTERVAL_MS = 50;
    private const int LOCK_TIMEOUT_SECONDS = 3;
    private const int LOCK_TTL_SECONDS = 10;

    private readonly string $prefix;
    private readonly int|\Closure|null $ttl;
    private readonly \Amp\Redis\RedisClient $redis;
    private ?string $lockToken = null;
    private ?string $lockKey = null;

    public function close(): bool
    {
        $this->releaseLock();

        return true;
    }

    /**
     * @param RedisOptions $options
     */
    public function __construct(\Amp\Redis\RedisClient $redis, array $options = [])
    {
        if (!\class_exists(\Amp\Redis\RedisClient::class)) {
            throw new \RuntimeException('Async Redis session handler requires "amphp/redis". '
            . 'Run: composer require amphp/redis');
        }

        if (!$redis instanceof \Amp\Redis\RedisClient) {
            throw new \InvalidArgumentException(\sprintf(
                'Expected instance of %s, got %s.',
                \Amp\Redis\RedisClient::class,
                $redis::class,
            ));
        }

        $this->redis = $redis;

        $diff = \array_diff(\array_keys($options), ['prefix', 'ttl']);
        if ($diff) {
            throw new \InvalidArgumentException(\sprintf('The following options are not supported: "%s".', \implode(
                '", "',
                $diff,
            )));
        }

        $this->prefix = $options['prefix'] ?? 'sf_s';
        $this->ttl = $options['ttl'] ?? null;
    }

    protected function doRead(#[\SensitiveParameter] string $sessionId): string
    {
        $dataKey = $this->prefix . $sessionId;
        $lockKey = $dataKey . ':lock';
        $token = \bin2hex(\random_bytes(16));

        if ($this->acquireLock($lockKey, $token)) {
            $this->lockToken = $token;
            $this->lockKey = $lockKey;
        }

        try {
            $value = $this->redis->get($dataKey);

            return $value ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    protected function doWrite(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $ttl =
            ($this->ttl instanceof \Closure ? ($this->ttl)() : $this->ttl) ?? (int) \ini_get('session.gc_maxlifetime');

        try {
            $this->releaseLock();
            $this->redis->execute('SETEX', $this->prefix . $sessionId, (string) $ttl, $data);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function doDestroy(#[\SensitiveParameter] string $sessionId): bool
    {
        try {
            $this->releaseLock();
            $this->redis->delete($this->prefix . $sessionId);
        } catch (\Throwable) {
            // Key may not exist
        }

        return true;
    }

    public function gc(int $maxlifetime): int|false
    {
        // Redis handles TTL-based expiry automatically
        return 0;
    }

    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $ttl =
            ($this->ttl instanceof \Closure ? ($this->ttl)() : $this->ttl) ?? (int) \ini_get('session.gc_maxlifetime');

        try {
            $this->redis->execute('EXPIRE', $this->prefix . $sessionId, (string) $ttl);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function acquireLock(string $lockKey, #[\SensitiveParameter] string $token): bool
    {
        $deadline = \microtime(true) + self::LOCK_TIMEOUT_SECONDS;

        do {
            try {
                $acquired = $this->redis->execute('SET', $lockKey, $token, 'NX', 'EX', (string) self::LOCK_TTL_SECONDS);
            } catch (\Throwable) {
                return false;
            }

            if ($acquired) {
                return true;
            }

            \Amp\delay(self::LOCK_RETRY_INTERVAL_MS / 1000);
        } while (\microtime(true) < $deadline);

        return false;
    }

    private function releaseLock(): void
    {
        if ($this->lockKey === null || $this->lockToken === null) {
            return;
        }

        try {
            $script = <<<'LUA'
                if redis.call('GET', KEYS[1]) == ARGV[1] then
                    redis.call('DEL', KEYS[1])
                    return 1
                end
                return 0
                LUA;

            $this->redis->execute('EVAL', $script, '1', $this->lockKey, $this->lockToken);
        } catch (\Throwable) {
            // Best-effort release
        } finally {
            $this->lockToken = null;
            $this->lockKey = null;
        }
    }
}

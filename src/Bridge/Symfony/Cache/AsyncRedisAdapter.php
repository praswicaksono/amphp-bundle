<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Symfony\Cache;

use Symfony\Component\Cache\Adapter\AbstractAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

final class AsyncRedisAdapter extends AbstractAdapter
{
    private \Amp\Redis\RedisClient $redis;
    private MarshallerInterface $marshaller;

    public function __construct(
        \Amp\Redis\RedisClient $redis,
        string $namespace = '',
        int $defaultLifetime = 0,
        ?MarshallerInterface $marshaller = null,
    ) {
        parent::__construct($namespace, $defaultLifetime);

        if (\preg_match('#[^-+_.A-Za-z0-9]#', $namespace, $match)) {
            throw new InvalidArgumentException(\sprintf(
                'RedisAdapter namespace contains "%s" but only characters in [-+_.A-Za-z0-9] are allowed.',
                $match[0],
            ));
        }

        $this->redis = $redis;
        $this->marshaller = $marshaller ?? new DefaultMarshaller();
    }

    public static function createConnection(
        #[\SensitiveParameter]
        string $dsn,
        array $options = [],
    ): \Amp\Redis\RedisClient {
        if (!\class_exists(\Amp\Redis\RedisClient::class)) {
            throw new \RuntimeException('Async Redis cache requires "amphp/redis". '
            . 'Run: composer require amphp/redis');
        }

        match (true) {
            \str_starts_with($dsn, 'redis:'),
            \str_starts_with($dsn, 'valkeys:'),
            \str_starts_with($dsn, 'rediss:'),
            \str_starts_with($dsn, 'valkey:'),
                => true,
            default => throw new InvalidArgumentException(\sprintf(
                'Invalid Redis DSN: it does not start with "redis[s]:" nor "valkey[s]:". Got: "%s".',
                $dsn,
            )),
        };

        // Parse query string from DSN
        $query = [];
        if (false !== ($qPos = \strpos($dsn, '?'))) {
            \parse_str(\substr($dsn, $qPos + 1), $query);
        }

        // Parse timeout from options or query
        $timeout = (float) ($options['timeout'] ?? $query['timeout'] ?? 5.0);

        // Create RedisConfig from URI
        $config = \Amp\Redis\RedisConfig::fromUri($dsn, $timeout);

        // Apply dbindex from options or query
        $dbindex = $options['dbindex'] ?? $query['dbindex'] ?? null;
        if (null !== $dbindex) {
            $config = $config->withDatabase((int) $dbindex);
        }

        // Apply password from options if not in DSN
        if (isset($options['auth'])) {
            $config = $config->withPassword($options['auth']);
        }

        return \Amp\Redis\createRedisClient($config);
    }

    protected function doFetch(array $ids): iterable
    {
        if (!$ids) {
            return [];
        }

        // Use MGET equivalent for batch fetch
        $values = $this->redis->getMultiple(...$ids);

        if (!\is_array($values) || \count($values) !== \count($ids)) {
            return [];
        }

        $result = [];
        foreach ($values as $i => $v) {
            if (null === $v) {
                continue;
            }

            $id = $ids[$i];
            $result[$id] = $this->marshaller->unmarshall($v);
        }

        return $result;
    }

    protected function doHave(string $id): bool
    {
        return $this->redis->has($id);
    }

    protected function doClear(string $namespace): bool
    {
        if ('' === $namespace) {
            $this->redis->flushDatabase();

            return true;
        }

        // Scan for matching keys and delete them
        $prefix = $namespace . static::NS_SEPARATOR;
        $keys = [];

        foreach ($this->redis->scan($prefix . '*') as $key) {
            $keys[] = $key;
        }

        if ($keys) {
            $this->redis->delete(...$keys);
        }

        return true;
    }

    protected function doDelete(array $ids): bool
    {
        if (!$ids) {
            return true;
        }

        $this->redis->delete(...$ids);

        return true;
    }

    protected function doSave(array $values, int $lifetime): array|bool
    {
        if (!($values = $this->marshaller->marshall($values, $failed))) {
            return $failed;
        }

        foreach ($values as $id => $value) {
            try {
                if (0 >= $lifetime) {
                    $this->redis->set($id, $value);
                } else {
                    $this->redis->set($id, $value, new \Amp\Redis\Command\Option\SetOptions()->withTtl($lifetime));
                }
            } catch (\Throwable) {
                $failed[] = $id;
            }
        }

        return $failed;
    }

    public function __serialize(): array
    {
        throw new \BadMethodCallException('Cannot serialize ' . __CLASS__);
    }

    public function __unserialize(array $data): void
    {
        throw new \BadMethodCallException('Cannot unserialize ' . __CLASS__);
    }
}

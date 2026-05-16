<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver;

use Doctrine\DBAL\Driver\AbstractPostgreSQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Revolt\EventLoop;

final class AsyncPostgresDriver extends AbstractPostgreSQLDriver
{
    private bool $init = false;

    /**
     * Extract connection from the pool.
     * @var \Closure(): \Amp\Postgres\PostgresConnection
     */
    private \Closure $pop;

    /**
     * Return the extracted connection back to the pool.
     * @var \Closure(\Amp\Postgres\PostgresConnection): void
     */
    private \Closure $push;

    /**
     * Key: Connection, Value: callback for release connection callback.
     * @var \WeakMap<\Amp\Postgres\PostgresConnection, \Closure(): void>
     */
    private static \WeakMap $releaseCallback;

    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        if (!$this->init) {
            $this->init($params);
            $this->init = true;
        }

        $push = $this->push;
        $postgresConnection = ($this->pop)();
        $releaseConnection = static function () use ($push, $postgresConnection): void {
            $push($postgresConnection);
        };
        self::$releaseCallback->offsetSet($postgresConnection, $releaseConnection);

        return new Connection($postgresConnection);
    }

    private function init(#[\SensitiveParameter] array $params): void
    {
        if (!\class_exists(\Amp\Postgres\PostgresConnectionPool::class)) {
            throw new \RuntimeException('AMPHP async PostgreSQL driver requires "amphp/postgres". '
            . 'Run: composer require amphp/postgres');
        }

        $pool = new \Amp\Postgres\PostgresConnectionPool(
            config: new \Amp\Postgres\PostgresConfig(
                host: $params['host'] ?? '',
                port: $params['port'] ?? \Amp\Postgres\PostgresConfig::DEFAULT_PORT,
                user: $params['user'] ?? null,
                password: $params['password'] ?? null,
                database: $params['dbname'] ?? null,
            ),
            maxConnections: $params['driverOptions']['max_connections']
            ?? \Amp\Sql\Common\SqlCommonConnectionPool::DEFAULT_MAX_CONNECTIONS,
            idleTimeout: $params['driverOptions']['idle_timeout']
            ?? \Amp\Sql\Common\SqlCommonConnectionPool::DEFAULT_IDLE_TIMEOUT,
            connector: new \Amp\Postgres\SocketPostgresConnector(),
        );

        $this->pop = $this->pop(...)->bindTo($pool, $pool);

        $this->push = (function (\Amp\Postgres\PostgresConnection $connection): void {
            /** @psalm-suppress UndefinedMethod */
            $this->push($connection);
        })->bindTo($pool, $pool);

        /** @psalm-suppress PropertyTypeCoercion */
        self::$releaseCallback ??= new \WeakMap();
    }

    /**
     * @internal
     */
    /**
     * @param bool $defer When true, the push is deferred to the next
     *     event loop tick (safe for destructor contexts).
     *
     * @internal
     */
    public static function releaseConnection(
        \Amp\Postgres\PostgresConnection $postgresConnection,
        bool $defer = true,
    ): void {
        /** @psalm-suppress PossiblyNullArgument */
        $release = self::$releaseCallback->offsetGet($postgresConnection);
        self::$releaseCallback->offsetUnset($postgresConnection);

        if ($defer) {
            EventLoop::defer($release);
        } else {
            $release();
        }
    }
}

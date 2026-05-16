<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Revolt\EventLoop;

final class AsyncMysqlDriver extends AbstractMySQLDriver
{
    private bool $init = false;

    /**
     * Extract connection from the pool.
     * @var \Closure(): \Amp\Mysql\MysqlConnection
     */
    private \Closure $pop;

    /**
     * Return the extracted connection back to the pool.
     * @var \Closure(\Amp\Mysql\MysqlConnection): void
     */
    private \Closure $push;

    /** @var \Closure(): int */
    private \Closure $poolConnectionCount;

    /** @var \Closure(): int */
    private \Closure $poolIdleCount;

    /** @var \Closure(): int */
    private \Closure $poolLimit;

    /**
     * Key: Connection, Value: callback for release connection callback.
     * @var \WeakMap<\Amp\Mysql\MysqlConnection, \Closure(): void>
     */
    private static \WeakMap $releaseCallback;

    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        if (!$this->init) {
            $this->init($params);
            $this->init = true;
        }

        $push = $this->push;
        $mysqlConnection = ($this->pop)();
        $releaseConnection = static function () use ($push, $mysqlConnection): void {
            $push($mysqlConnection);
        };
        self::$releaseCallback->offsetSet($mysqlConnection, $releaseConnection);

        return new Connection($mysqlConnection);
    }

    private function init(#[\SensitiveParameter] array $params): void
    {
        if (!\class_exists(\Amp\Mysql\MysqlConnectionPool::class)) {
            throw new \RuntimeException('AMPHP async MySQL driver requires "amphp/mysql". '
            . 'Run: composer require amphp/mysql');
        }

        $pool = new \Amp\Mysql\MysqlConnectionPool(
            config: new \Amp\Mysql\MysqlConfig(
                host: $params['host'] ?? '',
                port: $params['port'] ?? \Amp\Mysql\MysqlConfig::DEFAULT_PORT,
                user: $params['user'] ?? null,
                password: $params['password'] ?? null,
                database: $params['dbname'] ?? null,
                charset: $params['charset'] ?? \Amp\Mysql\MysqlConfig::DEFAULT_CHARSET,
            ),
            maxConnections: $params['driverOptions']['max_connections']
            ?? \Amp\Sql\Common\SqlCommonConnectionPool::DEFAULT_MAX_CONNECTIONS,
            idleTimeout: $params['driverOptions']['idle_timeout']
            ?? \Amp\Sql\Common\SqlCommonConnectionPool::DEFAULT_IDLE_TIMEOUT,
            connector: new \Amp\Mysql\SocketMysqlConnector(),
        );

        /** @phpstan-ignore Closure.assignUsedReturnValue */
        $this->pop = (function (): \Amp\Mysql\MysqlConnection {
            /** @psalm-suppress UndefinedMethod */
            return $this->pop();
        })->bindTo($pool, $pool);

        $this->push = (function (\Amp\Mysql\MysqlConnection $connection): void {
            /** @psalm-suppress UndefinedMethod */
            $this->push($connection);
        })->bindTo($pool, $pool);

        $this->poolConnectionCount = (function (): int {
            return $this->getConnectionCount();
        })->bindTo($pool, $pool);

        $this->poolIdleCount = (function (): int {
            return $this->getIdleConnectionCount();
        })->bindTo($pool, $pool);

        $this->poolLimit = (function (): int {
            return $this->getConnectionLimit();
        })->bindTo($pool, $pool);

        /** @psalm-suppress PropertyTypeCoercion */
        self::$releaseCallback ??= new \WeakMap();
    }

    public function getPoolConnectionCount(): int
    {
        if (!$this->init) {
            return 0;
        }

        return ($this->poolConnectionCount)();
    }

    public function getPoolIdleCount(): int
    {
        if (!$this->init) {
            return 0;
        }

        return ($this->poolIdleCount)();
    }

    public function getPoolLimit(): int
    {
        if (!$this->init) {
            return 0;
        }

        return ($this->poolLimit)();
    }

    /**
     * Return a connection to the pool.
     *
     * @param bool $defer When true, the push is deferred to the next
     *     event loop tick (safe for destructor contexts). When false,
     *     it is pushed immediately (use from explicit cleanup paths
     *     such as SymfonyRequestHandler's finally block).
     *
     * @internal
     */
    public static function releaseConnection(
        \Amp\Mysql\MysqlConnection $mysqlConnection,
        bool $defer = true,
    ): void {
        /** @psalm-suppress PossiblyNullArgument */
        $release = self::$releaseCallback->offsetGet($mysqlConnection);
        self::$releaseCallback->offsetUnset($mysqlConnection);

        if ($defer) {
            EventLoop::defer($release);
        } else {
            $release();
        }
    }
}

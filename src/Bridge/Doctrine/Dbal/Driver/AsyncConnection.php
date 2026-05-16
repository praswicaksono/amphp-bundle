<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver;

use Doctrine\DBAL\Cache\CacheException;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\TransactionIsolationLevel;
use Revolt\EventLoop\FiberLocal;

/**
 * Async DBAL connection wrapper that provides per-fiber connection isolation.
 *
 * Each fiber gets its own cloned connection from the pool, ensuring that
 * concurrent request handling does not share MySQL connections.
 *
 * NOTE: This class does NOT override the parent's __construct() or getParams()
 * methods (both marked @internal by Doctrine). Instead, it uses lazy
 * initialization in getOrCreateConnection().
 */
final class AsyncConnection extends DbalConnection
{
    private ?FiberLocal $fiberLocal = null;
    private ?DbalConnection $baseConnection = null;
    private bool $databasePlatformCached = false;

    /**
     * Tracks connection wrappers per Fiber, so they can be released even
     * when the fiber is cancelled and \Fiber::getCurrent() is unavailable.
     *
     * @var \WeakMap<\Fiber, object>
     */
    private static ?\WeakMap $fiberConnections = null;

    /**
     * Release the connection wrapper associated with a given Fiber.
     *
     * This is called from SymfonyRequestHandler's finally block using the
     * Fiber reference captured at the start of the request, ensuring the
     * connection is returned to the pool even when the fiber is cancelled
     * (e.g. client disconnect).
     */
    public static function releaseConnectionForFiber(\Fiber $fiber): void
    {
        $map = self::$fiberConnections;
        if ($map === null || !isset($map[$fiber])) {
            return;
        }

        $wrapper = $map[$fiber];
        unset($map[$fiber]);

        // Release synchronously (defer: false) because this is called
        // from an explicit cleanup context (finally block), not from a
        // destructor. Deferring would delay the push to the next event
        // loop tick, causing subsequent requests to wait unnecessarily.
        try {
            // Only release if the connection was actually established.
            // If the fiber was cancelled while waiting in the pool's
            // pop(), isConnected() is false and there is nothing to
            // release. Calling getNativeConnection() in that case
            // would trigger a NEW connect() attempt, making things worse.
            if (!$wrapper->connection->isConnected()) {
                return;
            }

            $connection = $wrapper->connection->getNativeConnection();

            if ($connection instanceof \Amp\Mysql\MysqlConnection) {
                AsyncMysqlDriver::releaseConnection($connection, defer: false);
                return;
            }

            if (
                \class_exists(\Amp\Postgres\PostgresConnection::class)
                && $connection instanceof \Amp\Postgres\PostgresConnection
            ) {
                AsyncPostgresDriver::releaseConnection($connection, defer: false);
            }
        } catch (\Throwable) {
            // Gracefully handle cleanup failures during shutdown.
        }
    }

    private function getFiberLocal(): FiberLocal
    {
        return $this->fiberLocal ??= new FiberLocal(static fn() => null);
    }

    private function getOrCreateConnection(): DbalConnection
    {
        $fiberLocal = $this->getFiberLocal();
        $wrapper = $fiberLocal->get();

        if ($wrapper === null) {
            if ($this->baseConnection === null) {
                // Use parent's private $params via its public (non-internal-for-calls) getter.
                // This is called at runtime and does not trigger DebugClassLoader checks.
                $params = \method_exists(parent::class, 'getParams') ? $this->getParams() : [];

                $this->baseConnection = new DbalConnection($params, $this->driver, $this->_config);
            }

            $wrapper = new class(clone $this->baseConnection) {
                public function __construct(
                    public readonly DbalConnection $connection,
                ) {}

                public function __destruct()
                {
                    try {
                        // Only release the connection if it was actually established.
                        // Calling getNativeConnection() on an unconnected connection
                        // triggers AsyncMysqlDriver::connect() which creates a new
                        // MysqlConnectionPool — and the pool constructor calls
                        // EventLoop::repeat(). During PHP shutdown the UV loop
                        // handle may already be freed, causing
                        //   "uv_update_time(): passed UVLoop handle is already closed"
                        if (!$this->connection->isConnected()) {
                            return;
                        }

                        $connection = $this->connection->getNativeConnection();

                        if ($connection instanceof \Amp\Mysql\MysqlConnection) {
                            AsyncMysqlDriver::releaseConnection($connection, defer: false);

                            return;
                        }

                        if (
                            \class_exists(\Amp\Postgres\PostgresConnection::class)
                            && $connection instanceof \Amp\Postgres\PostgresConnection
                        ) {
                            AsyncPostgresDriver::releaseConnection($connection, defer: false);
                        }
                    } catch (\Throwable) {
                        // Gracefully handle cleanup failures during shutdown.
                    }
                }
            };

            $fiberLocal->set($wrapper);

            // Also track in the per-Fiber WeakMap for guaranteed cleanup
            // when the request fiber is cancelled. Guard against null:
            // \Fiber::getCurrent() returns null outside a fiber context
            // (e.g. during cache:clear or metadata warmup).
            $currentFiber = \Fiber::getCurrent();
            if ($currentFiber !== null) {
                self::$fiberConnections ??= new \WeakMap();
                self::$fiberConnections[$currentFiber] = $wrapper;
            }
        }

        return $wrapper->connection;
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function getDatabase(): ?string
    {
        return $this->getOrCreateConnection()->getDatabase();
    }

    public function getDriver(): Driver
    {
        return $this->driver;
    }

    public function getConfiguration(): Configuration
    {
        return $this->_config;
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     * @psalm-suppress UndefinedThisPropertyAssignment, PossiblyNullFunctionCall
     */
    public function getDatabasePlatform(): AbstractPlatform
    {
        $databasePlatform = $this->getOrCreateConnection()->getDatabasePlatform();

        if (!$this->databasePlatformCached) {
            (function () use ($databasePlatform) {
                $this->platform = $databasePlatform;
            })->bindTo($this->baseConnection, $this->baseConnection)();

            $this->databasePlatformCached = true;
        }

        return $databasePlatform;
    }

    public function createExpressionBuilder(): ExpressionBuilder
    {
        return $this->getOrCreateConnection()->createExpressionBuilder();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    protected function connect(): DriverConnection
    {
        return $this->getOrCreateConnection()->connect();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function getServerVersion(): string
    {
        return $this->getOrCreateConnection()->connect()->getServerVersion();
    }

    public function isAutoCommit(): bool
    {
        return $this->getOrCreateConnection()->isAutoCommit();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function setAutoCommit(bool $autoCommit): void
    {
        $this->getOrCreateConnection()->setAutoCommit($autoCommit);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchAssociative(string $query, array $params = [], array $types = []): array|false
    {
        return $this->getOrCreateConnection()->fetchAssociative($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchNumeric(string $query, array $params = [], array $types = []): array|false
    {
        return $this->getOrCreateConnection()->fetchNumeric($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        return $this->getOrCreateConnection()->fetchOne($query, $params, $types);
    }

    public function isConnected(): bool
    {
        return $this->getOrCreateConnection()->isConnected();
    }

    public function isTransactionActive(): bool
    {
        return $this->getOrCreateConnection()->isTransactionActive();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function delete(string $table, array $criteria = [], array $types = []): int|string
    {
        return $this->getOrCreateConnection()->delete($table, $criteria, $types);
    }

    public function close(): void
    {
        $this->getOrCreateConnection()->close();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function setTransactionIsolation(TransactionIsolationLevel $level): void
    {
        $this->getOrCreateConnection()->setTransactionIsolation($level);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function getTransactionIsolation(): TransactionIsolationLevel
    {
        return $this->getOrCreateConnection()->getTransactionIsolation();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function update(string $table, array $data, array $criteria = [], array $types = []): int|string
    {
        return $this->getOrCreateConnection()->update($table, $data, $criteria, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function insert(string $table, array $data, array $types = []): int|string
    {
        return $this->getOrCreateConnection()->insert($table, $data, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     * @deprecated
     */
    public function quoteIdentifier(string $identifier): string
    {
        return $this->getOrCreateConnection()->quoteIdentifier($identifier);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function quoteSingleIdentifier(string $identifier): string
    {
        return $this->getOrCreateConnection()->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function quote(string $value): string
    {
        return $this->getOrCreateConnection()->quote($value);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchAllNumeric(string $query, array $params = [], array $types = []): array
    {
        return $this->getOrCreateConnection()->fetchAllNumeric($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        return $this->getOrCreateConnection()->fetchAllAssociative($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchAllKeyValue(string $query, array $params = [], array $types = []): array
    {
        return $this->getOrCreateConnection()->fetchAllKeyValue($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchAllAssociativeIndexed(string $query, array $params = [], array $types = []): array
    {
        return $this->getOrCreateConnection()->fetchAllAssociativeIndexed($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        return $this->getOrCreateConnection()->fetchFirstColumn($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function iterateNumeric(string $query, array $params = [], array $types = []): \Traversable
    {
        return $this->getOrCreateConnection()->iterateNumeric($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function iterateAssociative(string $query, array $params = [], array $types = []): \Traversable
    {
        return $this->getOrCreateConnection()->iterateAssociative($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function iterateKeyValue(string $query, array $params = [], array $types = []): \Traversable
    {
        return $this->getOrCreateConnection()->iterateKeyValue($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function iterateAssociativeIndexed(string $query, array $params = [], array $types = []): \Traversable
    {
        return $this->getOrCreateConnection()->iterateAssociativeIndexed($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function iterateColumn(string $query, array $params = [], array $types = []): \Traversable
    {
        return $this->getOrCreateConnection()->iterateColumn($query, $params, $types);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function prepare(string $sql): Statement
    {
        return $this->getOrCreateConnection()->prepare($sql);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?QueryCacheProfile $qcp = null,
    ): Result {
        return $this->getOrCreateConnection()->executeQuery($sql, $params, $types, $qcp);
    }

    /**
     * @throws CacheException
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function executeCacheQuery(string $sql, array $params, array $types, QueryCacheProfile $qcp): Result
    {
        return $this->getOrCreateConnection()->executeCacheQuery($sql, $params, $types, $qcp);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return $this->getOrCreateConnection()->executeStatement($sql, $params, $types);
    }

    public function getTransactionNestingLevel(): int
    {
        return $this->getOrCreateConnection()->getTransactionNestingLevel();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function lastInsertId(): int|string
    {
        return $this->getOrCreateConnection()->lastInsertId();
    }

    /**
     * @throws \Throwable
     */
    public function transactional(\Closure $func): mixed
    {
        return $this->getOrCreateConnection()->transactional($func);
    }

    /**
     * @deprecated
     */
    public function setNestTransactionsWithSavepoints(bool $nestTransactionsWithSavepoints): void
    {
        $this->getOrCreateConnection()->setNestTransactionsWithSavepoints($nestTransactionsWithSavepoints);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     * @deprecated
     */
    public function getNestTransactionsWithSavepoints(): bool
    {
        return $this->getOrCreateConnection()->getNestTransactionsWithSavepoints();
    }

    protected function _getNestedTransactionSavePointName(): string
    {
        return $this->getOrCreateConnection()->_getNestedTransactionSavePointName();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function beginTransaction(): void
    {
        $this->getOrCreateConnection()->beginTransaction();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function commit(): void
    {
        $this->getOrCreateConnection()->commit();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function rollBack(): void
    {
        $this->getOrCreateConnection()->rollBack();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function createSavepoint(string $savepoint): void
    {
        $this->getOrCreateConnection()->createSavepoint($savepoint);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function releaseSavepoint(string $savepoint): void
    {
        $this->getOrCreateConnection()->releaseSavepoint($savepoint);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function rollbackSavepoint(string $savepoint): void
    {
        $this->getOrCreateConnection()->rollbackSavepoint($savepoint);
    }

    /**
     * @return resource|object
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function getNativeConnection(): mixed
    {
        return $this->getOrCreateConnection()->getNativeConnection();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function createSchemaManager(): AbstractSchemaManager
    {
        return $this->getOrCreateConnection()->createSchemaManager();
    }

    /**
     * @throws ConnectionException
     */
    public function setRollbackOnly(): void
    {
        $this->getOrCreateConnection()->setRollbackOnly();
    }

    /**
     * @throws ConnectionException
     */
    public function isRollbackOnly(): bool
    {
        return $this->getOrCreateConnection()->isRollbackOnly();
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function convertToDatabaseValue(mixed $value, string $type): mixed
    {
        return $this->getOrCreateConnection()->convertToDatabaseValue($value, $type);
    }

    /**
     * @throws \PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver\Exception
     */
    public function convertToPHPValue(mixed $value, string $type): mixed
    {
        return $this->getOrCreateConnection()->convertToPHPValue($value, $type);
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->getOrCreateConnection()->createQueryBuilder();
    }
}

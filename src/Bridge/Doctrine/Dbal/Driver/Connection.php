<?php

declare(strict_types=1);

namespace PRSW\AmphpBundle\Bridge\Doctrine\Dbal\Driver;

use Amp\Sql\SqlConnection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception\NoIdentityValue;

final readonly class Connection implements DriverConnection
{
    public function __construct(
        private SqlConnection $connection,
    ) {}

    public function prepare(string $sql): Statement
    {
        /** @psalm-suppress InvalidArgument */
        return new Statement($this->connection->prepare($sql));
    }

    /**
     * @throws Exception
     */
    public function query(string $sql): Result
    {
        try {
            return new Result($this->connection->query($sql));
        } catch (\Throwable $e) {
            throw new Exception($e);
        }
    }

    public function quote(string $value): string
    {
        // Standard MySQL escaping mapping
        $search = ["\\", "\0", "\n", "\r", "'", '"', "\x1a"];
        $replace = ["\\\\", "\\0", "\\n", "\\r", "\'", "\\\"", "\\Z"];

        return "'" . \str_replace($search, $replace, $value) . "'";
    }

    /**
     * @throws Exception
     */
    public function exec(string $sql): int
    {
        try {
            return $this->connection->query($sql)->getRowCount() ?? 0;
        } catch (\Throwable $e) {
            throw new Exception($e);
        }
    }

    /**
     * @throws NoIdentityValue
     * @throws Exception
     */
    public function lastInsertId(): int|string
    {
        $id = $this->query('SELECT LAST_INSERT_ID()')->fetchOne();

        if ($id === 0 || $id === '0' || $id === null) {
            /** @psalm-suppress InternalClass, InternalMethod */
            throw NoIdentityValue::new();
        }

        return $id;
    }

    public function beginTransaction(): void
    {
        $this->exec('START TRANSACTION');
    }

    public function commit(): void
    {
        $this->exec('COMMIT');
    }

    public function rollBack(): void
    {
        $this->exec('ROLLBACK');
    }

    public function getNativeConnection(): SqlConnection
    {
        return $this->connection;
    }

    public function getServerVersion(): string
    {
        try {
            return $this->query('SELECT VERSION()')->fetchOne();
        } catch (Exception) {
            return '';
        }
    }
}

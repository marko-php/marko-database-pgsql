<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Introspection;

use Closure;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Database\Connection\TransactionInterface;
use RuntimeException;

/**
 * Records every statement, query and transaction call in order, answering queries and statements through
 * closures, so the expression default probe can be asserted without a PostgreSQL server.
 */
class ProbeRecordingConnection implements ConnectionInterface, TransactionInterface
{
    /**
     * @var list<string> Each executed statement or query, and BEGIN/ROLLBACK/COMMIT for transaction calls
     */
    public array $log = [];

    /**
     * @var list<array<mixed>> The bindings of each query, in order
     */
    public array $queryBindings = [];

    private int $level = 0;

    /**
     * @param Closure(string, array<mixed>): array<array<string, mixed>> $onQuery
     * @param Closure(string): void|null $onExecute Throws to make a statement fail
     */
    public function __construct(
        private readonly Closure $onQuery,
        private readonly ?Closure $onExecute = null,
    ) {}

    public function connect(): void {}

    public function disconnect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function query(
        string $sql,
        array $bindings = [],
    ): array {
        $this->log[] = $sql;
        $this->queryBindings[] = $bindings;

        return ($this->onQuery)($sql, $bindings);
    }

    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        $this->log[] = $sql;

        if ($this->onExecute !== null) {
            ($this->onExecute)($sql);
        }

        return 0;
    }

    public function prepare(
        string $sql,
    ): StatementInterface {
        throw new RuntimeException('Not implemented');
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function driverName(): string
    {
        return 'pgsql';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function quoteIdentifier(
        string $identifier,
    ): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function beginTransaction(): void
    {
        $this->log[] = 'BEGIN';
        $this->level++;
    }

    public function commit(): void
    {
        $this->log[] = 'COMMIT';
        $this->level--;
    }

    public function rollback(): void
    {
        $this->log[] = 'ROLLBACK';
        $this->level--;
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    public function transactionLevel(): int
    {
        return $this->level;
    }

    public function transaction(
        callable $callback,
        int $attempts = 1,
        int|Closure|null $backoff = null,
    ): mixed {
        return $callback();
    }

    public function afterCommit(
        callable $callback,
    ): void {}

    public function afterRollback(
        callable $callback,
    ): void {}
}

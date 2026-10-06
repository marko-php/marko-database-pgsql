<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Exceptions\LockTimeoutException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Query\PgSqlQueryBuilder;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use RuntimeException;

/*
 * Savepoints, after-commit callbacks, row locks and upsert against a real
 * PostgreSQL server. Uses the same MARKO_TEST_PGSQL_* variables as
 * SharedConnectionTransactionTest and skips when they are unset. Creates and
 * drops the primitives_items table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

/**
 * @return list<string>
 */
function pgsqlPrimitiveNames(
    PgSqlConnection $connection,
): array {
    return array_column($connection->query('SELECT name FROM primitives_items ORDER BY id'), 'name');
}

function pgsqlInsertItem(
    PgSqlConnection $connection,
    int $id,
    string $name,
): void {
    $connection->execute('INSERT INTO primitives_items (id, name) VALUES (?, ?)', [$id, $name]);
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(
            IntegrationDatabase::SKIP_REASON,
        );
    }

    $this->connection = new PgSqlConnection($config);
    $this->contender = new PgSqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS primitives_items');
    $this->connection->execute(
        'CREATE TABLE primitives_items (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL, '
        . 'email VARCHAR(255) UNIQUE, visits INT NOT NULL DEFAULT 0)',
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->contender->disconnect();
        $this->connection->reset();
        $this->connection->execute('DROP TABLE IF EXISTS primitives_items');
        $this->connection->disconnect();
    }
});

describe('PostgreSQL nested transactions', function (): void {
    it('commits nested transaction() calls together', function (): void {
        $this->connection->transaction(function (): void {
            pgsqlInsertItem($this->connection, 1, 'outer');
            $this->connection->transaction(fn () => pgsqlInsertItem($this->connection, 2, 'inner'));
        });

        expect(pgsqlPrimitiveNames($this->contender))->toBe(['outer', 'inner']);
    });

    it('rolls back only the inner work when an inner failure is caught', function (): void {
        $this->connection->transaction(function (): void {
            pgsqlInsertItem($this->connection, 1, 'outer');

            try {
                $this->connection->transaction(function (): void {
                    pgsqlInsertItem($this->connection, 2, 'inner');
                    // A real SQL error aborts the PostgreSQL transaction; the
                    // savepoint rollback must make it usable again.
                    pgsqlInsertItem($this->connection, 1, 'duplicate key');
                });
            } catch (UniqueConstraintViolationException) {
                // Handled: only the savepoint is rolled back.
            }

            pgsqlInsertItem($this->connection, 3, 'after inner failure');
        });

        expect(pgsqlPrimitiveNames($this->contender))->toBe(['outer', 'after inner failure']);
    });

    it('rolls back everything when the outer transaction fails', function (): void {
        $run = fn () => $this->connection->transaction(function (): void {
            pgsqlInsertItem($this->connection, 1, 'outer');
            $this->connection->transaction(fn () => pgsqlInsertItem($this->connection, 2, 'inner'));

            throw new RuntimeException('Outer failure');
        });

        expect($run)->toThrow(RuntimeException::class, 'Outer failure')
            ->and(pgsqlPrimitiveNames($this->contender))->toBe([])
            ->and($this->connection->transactionLevel())->toBe(0);
    });

    it('runs after-commit callbacks after the outermost commit, when the data is visible', function (): void {
        $seenByCallback = null;

        $this->connection->transaction(function () use (&$seenByCallback): void {
            pgsqlInsertItem($this->connection, 1, 'outer');
            $this->connection->transaction(function () use (&$seenByCallback): void {
                $this->connection->afterCommit(function () use (&$seenByCallback): void {
                    $seenByCallback = pgsqlPrimitiveNames($this->contender);
                });
            });
        });

        expect($seenByCallback)->toBe(['outer']);
    });

    it('does not run after-commit callbacks when the transaction rolls back', function (): void {
        $ran = false;

        try {
            $this->connection->transaction(function () use (&$ran): void {
                $this->connection->afterCommit(function () use (&$ran): void {
                    $ran = true;
                });

                throw new RuntimeException('Rollback');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        expect($ran)->toBeFalse();
    });
});

describe('PostgreSQL row locks', function (): void {
    it('lets a second connection skip a row locked with lockForUpdate', function (): void {
        pgsqlInsertItem($this->connection, 1, 'first');
        pgsqlInsertItem($this->connection, 2, 'second');

        $this->connection->beginTransaction();
        $locked = new PgSqlQueryBuilder($this->connection)
            ->table('primitives_items')
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->get();

        $visible = $this->contender->transaction(
            fn (): array => new PgSqlQueryBuilder($this->contender)
                ->table('primitives_items')
                ->orderBy('id')
                ->lockForUpdate()
                ->skipLocked()
                ->get(),
        );
        $this->connection->rollback();

        expect(array_column($locked, 'name'))->toBe(['first'])
            ->and(array_column($visible, 'name'))->toBe(['second']);
    });

    it('makes a second connection fail fast with noWait on a locked row', function (): void {
        pgsqlInsertItem($this->connection, 1, 'first');

        $this->connection->beginTransaction();
        new PgSqlQueryBuilder($this->connection)
            ->table('primitives_items')
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->get();

        $contend = fn () => $this->contender->transaction(
            fn (): array => new PgSqlQueryBuilder($this->contender)
                ->table('primitives_items')
                ->where('id', '=', 1)
                ->lockForUpdate()
                ->noWait()
                ->get(),
        );

        expect($contend)->toThrow(LockTimeoutException::class, 'could not obtain lock');

        $this->connection->rollback();
    });

    it('lets a second connection share-lock a row that is share-locked', function (): void {
        pgsqlInsertItem($this->connection, 1, 'first');

        $this->connection->beginTransaction();
        new PgSqlQueryBuilder($this->connection)->table('primitives_items')->sharedLock()->get();

        $shared = $this->contender->transaction(
            fn (): array => new PgSqlQueryBuilder($this->contender)
                ->table('primitives_items')
                ->sharedLock()
                ->noWait()
                ->get(),
        );
        $this->connection->rollback();

        expect(array_column($shared, 'name'))->toBe(['first']);
    });
});

describe('PostgreSQL upsert', function (): void {
    it('inserts new rows', function (): void {
        new PgSqlQueryBuilder($this->connection)->table('primitives_items')->upsert(
            [
                ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'visits' => 1],
                ['id' => 2, 'name' => 'Alan', 'email' => 'alan@example.com', 'visits' => 1],
            ],
            ['email'],
        );

        expect(pgsqlPrimitiveNames($this->contender))->toBe(['Ada', 'Alan']);
    });

    it('updates existing rows', function (): void {
        $this->connection->execute(
            "INSERT INTO primitives_items (id, name, email, visits) VALUES (1, 'Ada', 'ada@example.com', 1)",
        );

        new PgSqlQueryBuilder($this->connection)->table('primitives_items')->upsert(
            [['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'visits' => 2]],
            ['email'],
            ['name', 'visits'],
        );

        expect($this->contender->query('SELECT name, visits FROM primitives_items'))
            ->toBe([['name' => 'Ada Lovelace', 'visits' => 2]]);
    });

    it('inserts and updates in one mixed batch', function (): void {
        $this->connection->execute(
            "INSERT INTO primitives_items (id, name, email, visits) VALUES (1, 'Ada', 'ada@example.com', 1)",
        );

        new PgSqlQueryBuilder($this->connection)->table('primitives_items')->upsert(
            [
                ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'visits' => 2],
                ['id' => 2, 'name' => 'Alan', 'email' => 'alan@example.com', 'visits' => 1],
            ],
            ['email'],
            ['name', 'visits'],
        );

        expect($this->contender->query('SELECT name, visits FROM primitives_items ORDER BY id'))
            ->toBe([['name' => 'Ada Lovelace', 'visits' => 2], ['name' => 'Alan', 'visits' => 1]]);
    });

    it('leaves existing rows untouched when the update list is empty', function (): void {
        $this->connection->execute(
            "INSERT INTO primitives_items (id, name, email, visits) VALUES (1, 'Ada', 'ada@example.com', 1)",
        );

        new PgSqlQueryBuilder($this->connection)->table('primitives_items')->upsert(
            [['id' => 1, 'name' => 'Changed', 'email' => 'ada@example.com', 'visits' => 9]],
            ['email'],
            [],
        );

        expect(pgsqlPrimitiveNames($this->contender))->toBe(['Ada']);
    });

    it('rejects a batch that hits the same conflict key twice', function (): void {
        $upsert = fn () => new PgSqlQueryBuilder($this->connection)->table('primitives_items')->upsert(
            [
                ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'visits' => 1],
                ['id' => 2, 'name' => 'Ada again', 'email' => 'ada@example.com', 'visits' => 1],
            ],
            ['email'],
        );

        expect($upsert)->toThrow(QueryException::class, 'cannot affect row a second time');
    });
});

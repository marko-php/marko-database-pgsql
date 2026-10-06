<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Exceptions\DeadlockException;
use Marko\Database\Exceptions\LockTimeoutException;
use Marko\Database\Exceptions\SerializationFailureException;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Query\PgSqlQueryBuilder;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use RuntimeException;

/*
 * Deadlocks, lock timeouts and serialization failures against a real
 * PostgreSQL server. Uses the MARKO_TEST_PGSQL_* variables and skips when
 * they are unset. Creates and drops the concurrency_items table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

/**
 * Poll until $condition holds, failing after $timeoutSeconds.
 */
function pgsqlWaitUntil(
    callable $condition,
    float $timeoutSeconds = 10.0,
): void {
    $deadline = microtime(true) + $timeoutSeconds;

    while (!$condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the contender session');
        }

        usleep(20_000);
    }
}

/**
 * Deadlock this session against a second process: this session locks row 1,
 * the contender locks row 2 and then waits for row 1, and this session then
 * asks for row 2. PostgreSQL aborts one of the two.
 *
 * @return array{mine: string, theirs: string}
 */
function pgsqlRunDeadlock(
    PgSqlConnection $connection,
    PgSqlConnection $observer,
): array {
    $connection->beginTransaction();
    $connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 1');

    $process = proc_open(
        [
            PHP_BINARY,
            dirname(__DIR__) . '/Fixtures/Concurrency/deadlock-contender.php',
            'UPDATE concurrency_items SET n = n + 1 WHERE id = 2',
            'UPDATE concurrency_items SET n = n + 1 WHERE id = 1',
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the contender process');
    }

    $locked = trim((string) fgets($pipes[1]));

    if ($locked !== 'locked') {
        throw new RuntimeException('Contender failed: ' . $locked . stream_get_contents($pipes[2]));
    }

    fwrite($pipes[0], "go\n");
    fflush($pipes[0]);

    pgsqlWaitUntil(fn (): bool => (int) $observer->query(
        "SELECT COUNT(*) AS waiting FROM pg_stat_activity WHERE wait_event_type = 'Lock' "
        . 'AND datname = current_database()',
    )[0]['waiting'] > 0);

    try {
        $connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 2');
        $connection->commit();
        $mine = 'committed';
    } catch (DeadlockException) {
        $connection->rollback();
        $mine = DeadlockException::class;
    }

    $theirs = trim((string) stream_get_contents($pipes[1]));
    $errors = (string) stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    proc_close($process);

    if ($errors !== '') {
        throw new RuntimeException("Contender failed: $errors");
    }

    return ['mine' => $mine, 'theirs' => $theirs];
}

/**
 * Begin a SERIALIZABLE transaction and read both rows, so later writes by
 * other sessions conflict with this snapshot.
 */
function pgsqlBeginSerializableRead(
    PgSqlConnection $connection,
): void {
    $connection->beginTransaction();
    $connection->execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $connection->query('SELECT SUM(n) AS total FROM concurrency_items');
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
    $this->connection->execute('DROP TABLE IF EXISTS concurrency_items');
    $this->connection->execute('CREATE TABLE concurrency_items (id INT PRIMARY KEY, n INT NOT NULL)');
    $this->connection->execute('INSERT INTO concurrency_items (id, n) VALUES (1, 0), (2, 0)');
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->contender->reset();
        $this->contender->disconnect();
        $this->connection->reset();
        $this->connection->execute('DROP TABLE IF EXISTS concurrency_items');
        $this->connection->disconnect();
    }
});

describe('PostgreSQL concurrency errors', function (): void {
    it('raises DeadlockException in one of two deadlocked sessions', function (): void {
        $outcome = pgsqlRunDeadlock($this->connection, $this->contender);

        expect([$outcome['mine'], $outcome['theirs']])->toEqualCanonicalizing([
            DeadlockException::class,
            'committed',
        ]);
    });

    it('raises LockTimeoutException when noWait meets a locked row', function (): void {
        $this->connection->beginTransaction();
        $this->connection->execute('UPDATE concurrency_items SET n = 1 WHERE id = 1');

        $this->contender->beginTransaction();
        $contend = fn () => new PgSqlQueryBuilder($this->contender)
            ->table('concurrency_items')
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->noWait()
            ->get();

        expect($contend)->toThrow(LockTimeoutException::class, 'could not obtain lock');

        $this->contender->rollback();
        $this->connection->rollback();
    });

    it('raises LockTimeoutException when lock_timeout expires', function (): void {
        $this->connection->beginTransaction();
        $this->connection->execute('UPDATE concurrency_items SET n = 1 WHERE id = 1');

        $this->contender->beginTransaction();
        $this->contender->execute("SET LOCAL lock_timeout = '100ms'");

        expect(fn () => $this->contender->execute('UPDATE concurrency_items SET n = 2 WHERE id = 1'))
            ->toThrow(LockTimeoutException::class, 'lock timeout');

        $this->contender->rollback();
        $this->connection->rollback();
    });

    it('raises SerializationFailureException for a concurrent update under SERIALIZABLE', function (): void {
        pgsqlBeginSerializableRead($this->connection);
        $this->contender->execute('UPDATE concurrency_items SET n = 10 WHERE id = 1');

        expect(fn () => $this->connection->execute('UPDATE concurrency_items SET n = 20 WHERE id = 1'))
            ->toThrow(SerializationFailureException::class, 'could not serialize access');

        $this->connection->rollback();
    });

    it('raises SerializationFailureException at COMMIT for a write skew under SERIALIZABLE', function (): void {
        pgsqlBeginSerializableRead($this->connection);
        pgsqlBeginSerializableRead($this->contender);
        $this->connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 1');
        $this->contender->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 2');
        $this->connection->commit();

        $failure = null;

        try {
            $this->contender->commit();
        } catch (SerializationFailureException $e) {
            $failure = $e;
        }

        expect($failure)->toBeInstanceOf(SerializationFailureException::class)
            ->and($failure?->sql())->toBe('COMMIT')
            ->and($this->contender->transactionLevel())->toBe(0);
    });

    it('retries a serialization failure and commits on the next attempt', function (): void {
        $attempts = 0;

        $total = $this->connection->transaction(function () use (&$attempts): int {
            $attempts++;
            $this->connection->execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $this->connection->query('SELECT SUM(n) AS total FROM concurrency_items');

            if ($attempts === 1) {
                $this->contender->execute('UPDATE concurrency_items SET n = 10 WHERE id = 1');
            }

            $this->connection->execute('UPDATE concurrency_items SET n = n + 1 WHERE id = 1');

            return (int) $this->connection->query('SELECT n FROM concurrency_items WHERE id = 1')[0]['n'];
        }, attempts: 2);

        expect($attempts)->toBe(2)
            ->and($total)->toBe(11);
    });
});

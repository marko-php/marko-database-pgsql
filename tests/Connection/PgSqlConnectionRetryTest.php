<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Connection;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Exceptions\DeadlockException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Exceptions\SerializationFailureException;
use Marko\Database\Exceptions\TransactionConflictException;
use Marko\Database\Exceptions\TransactionException;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Tests\Fixtures\Retry\ScriptedPdo;
use PDO;
use PDOException;
use RuntimeException;

function makeRetryPgSqlConnection(
    ScriptedPdo $pdo,
): PgSqlConnection {
    $config = DatabaseConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'test',
        'username' => 'test',
        'password' => 'test',
    ]);

    return new class ($config, $pdo) extends PgSqlConnection
    {
        public function __construct(
            DatabaseConfig $config,
            private readonly ScriptedPdo $scriptedPdo,
        ) {
            parent::__construct($config);
        }

        protected function createPdo(
            string $dsn,
            string $username,
            string $password,
            array $options,
        ): PDO {
            return $this->scriptedPdo;
        }
    };
}

function pgsqlDeadlock(): DeadlockException
{
    return DeadlockException::fromDriverError(
        new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'),
        'UPDATE items SET name = $1',
        [],
    );
}

function pgsqlCommitFailure(
    string $sqlState,
    string $serverMessage,
): PDOException {
    $exception = new PDOException("SQLSTATE[$sqlState]: Error: 7 $serverMessage");
    $exception->errorInfo = [$sqlState, 7, $serverMessage];

    return $exception;
}

describe('PgSqlConnection::transaction() retries', function (): void {
    it('retries the outermost transaction on a conflict and returns the successful result', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryPgSqlConnection($pdo);
        $calls = 0;

        $result = $connection->transaction(function () use ($connection, &$calls): string {
            $calls++;
            $connection->execute('INSERT INTO items (name) VALUES (?)', ["attempt $calls"]);

            if ($calls < 3) {
                throw pgsqlDeadlock();
            }

            return 'done';
        }, attempts: 3);

        expect($result)->toBe('done')
            ->and($calls)->toBe(3)
            ->and($pdo->statements)->toBe(['BEGIN', 'ROLLBACK', 'BEGIN', 'ROLLBACK', 'BEGIN', 'COMMIT'])
            ->and(array_column($connection->query('SELECT name FROM items'), 'name'))->toBe(['attempt 3']);
    });

    it('gives up after the given number of attempts and rethrows the last conflict', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $thrown = [];
        $caught = null;

        try {
            $connection->transaction(function () use (&$thrown): void {
                $thrown[] = $conflict = pgsqlDeadlock();

                throw $conflict;
            }, attempts: 3);
        } catch (DeadlockException $e) {
            $caught = $e;
        }

        expect($thrown)->toHaveCount(3)
            ->and($caught)->toBe($thrown[2])
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('does not retry on exceptions that are not transaction conflicts', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $calls = 0;

        $run = function () use ($connection, &$calls): void {
            $connection->transaction(function () use (&$calls): void {
                $calls++;

                throw new RuntimeException('Not a conflict');
            }, attempts: 3);
        };

        expect($run)->toThrow(RuntimeException::class, 'Not a conflict')
            ->and($calls)->toBe(1);
    });

    it('does not retry when attempts is one', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $calls = 0;

        $run = function () use ($connection, &$calls): void {
            $connection->transaction(function () use (&$calls): void {
                $calls++;

                throw pgsqlDeadlock();
            });
        };

        expect($run)->toThrow(DeadlockException::class)
            ->and($calls)->toBe(1);
    });

    it('discards after-commit callbacks registered in a failed attempt', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $calls = 0;
        $log = [];

        $connection->transaction(function () use ($connection, &$calls, &$log): void {
            $calls++;
            $attempt = $calls;
            $connection->afterCommit(function () use (&$log, $attempt): void {
                $log[] = "committed attempt $attempt";
            });

            if ($attempt === 1) {
                throw pgsqlDeadlock();
            }
        }, attempts: 2);

        expect($log)->toBe(['committed attempt 2']);
    });

    it('runs the after-rollback callbacks of each failed attempt', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $calls = 0;
        $log = [];

        $connection->transaction(function () use ($connection, &$calls, &$log): void {
            $calls++;
            $attempt = $calls;
            $connection->afterRollback(function () use (&$log, $attempt): void {
                $log[] = "rolled back attempt $attempt";
            });

            if ($attempt < 3) {
                throw pgsqlDeadlock();
            }
        }, attempts: 3);

        expect($log)->toBe(['rolled back attempt 1', 'rolled back attempt 2']);
    });

    it('starts each retry from transaction level zero', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $levels = [];

        $connection->transaction(function () use ($connection, &$levels): void {
            $levels[] = $connection->transactionLevel();

            if (count($levels) < 3) {
                throw pgsqlDeadlock();
            }
        }, attempts: 3);

        expect($levels)->toBe([1, 1, 1])
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('never retries a nested transaction by itself', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryPgSqlConnection($pdo);
        $innerCalls = 0;

        $run = function () use ($connection, &$innerCalls): void {
            $connection->transaction(function () use ($connection, &$innerCalls): void {
                $connection->transaction(function () use (&$innerCalls): void {
                    $innerCalls++;

                    throw pgsqlDeadlock();
                }, attempts: 3);
            });
        };

        expect($run)->toThrow(DeadlockException::class)
            ->and($innerCalls)->toBe(1)
            ->and($pdo->statements)->toBe([
                'BEGIN',
                'SAVEPOINT marko_sp_1',
                'ROLLBACK TO SAVEPOINT marko_sp_1',
                'ROLLBACK',
            ]);
    });

    it('retries the outermost transaction when a nested transaction hits a conflict', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $outerCalls = 0;
        $innerCalls = 0;

        $connection->transaction(function () use ($connection, &$outerCalls, &$innerCalls): void {
            $outerCalls++;
            $connection->transaction(function () use (&$innerCalls): void {
                $innerCalls++;

                if ($innerCalls === 1) {
                    throw pgsqlDeadlock();
                }
            });
        }, attempts: 2);

        expect($outerCalls)->toBe(2)
            ->and($innerCalls)->toBe(2);
    });

    it('surfaces the conflict when the savepoint rollback after it fails', function (): void {
        $pdo = new ScriptedPdo();
        $pdo->execFailures['ROLLBACK TO SAVEPOINT'] = new PDOException('savepoint "marko_sp_1" does not exist');
        $connection = makeRetryPgSqlConnection($pdo);
        $outerCalls = 0;

        $connection->transaction(function () use ($connection, &$outerCalls): void {
            $outerCalls++;
            $connection->transaction(function () use ($outerCalls): void {
                if ($outerCalls === 1) {
                    throw pgsqlDeadlock();
                }
            });
        }, attempts: 2);

        expect($outerCalls)->toBe(2)
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('retries when the COMMIT statement reports a conflict', function (): void {
        $pdo = new ScriptedPdo();
        $pdo->commitFailures[] = pgsqlCommitFailure(
            '40001',
            'ERROR:  could not serialize access due to read/write dependencies among transactions',
        );
        $connection = makeRetryPgSqlConnection($pdo);
        $calls = 0;

        $result = $connection->transaction(function () use (&$calls): int {
            return ++$calls;
        }, attempts: 2);

        expect($result)->toBe(2)
            ->and($pdo->statements)->toBe(['BEGIN', 'COMMIT', 'ROLLBACK', 'BEGIN', 'COMMIT']);
    });

    it('does not retry when an after-commit callback throws a conflict', function (): void {
        $connection = makeRetryPgSqlConnection(new ScriptedPdo());
        $calls = 0;

        $run = function () use ($connection, &$calls): void {
            $connection->transaction(function () use ($connection, &$calls): void {
                $calls++;
                $connection->afterCommit(function (): void {
                    throw pgsqlDeadlock();
                });
            }, attempts: 3);
        };

        expect($run)->toThrow(DeadlockException::class)
            ->and($calls)->toBe(1);
    });

    it('rejects an attempts value below one, even in a nested call, before opening a level', function (): void {
        $pdo = new ScriptedPdo();
        $connection = makeRetryPgSqlConnection($pdo);
        $levelAfterNested = null;

        expect(fn () => $connection->transaction(fn () => null, attempts: 0))
            ->toThrow(TransactionException::class, 'at least 1 attempt')
            ->and($pdo->statements)->toBeEmpty();

        $connection->transaction(function () use ($connection, &$levelAfterNested): void {
            try {
                $connection->transaction(fn () => null, attempts: -1);
            } catch (TransactionException) {
                $levelAfterNested = $connection->transactionLevel();
            }
        });

        expect($levelAfterNested)->toBe(1)
            ->and($pdo->statements)->toBe(['BEGIN', 'COMMIT']);
    });

    it('translates a failed COMMIT into a typed exception carrying the COMMIT statement', function (): void {
        $pdo = new ScriptedPdo();
        $pdo->commitFailures[] = pgsqlCommitFailure('40001', 'ERROR:  could not serialize access');
        $pdo->commitFailures[] = pgsqlCommitFailure('08006', 'server closed the connection unexpectedly');
        $connection = makeRetryPgSqlConnection($pdo);

        $connection->beginTransaction();
        $conflict = null;

        try {
            $connection->commit();
        } catch (SerializationFailureException $e) {
            $conflict = $e;
        }

        $connection->beginTransaction();

        expect($conflict?->sql())->toBe('COMMIT')
            ->and($conflict)->toBeInstanceOf(TransactionConflictException::class)
            ->and($conflict?->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and(fn () => $connection->commit())->toThrow(QueryException::class, 'server closed the connection')
            ->and($connection->transactionLevel())->toBe(0);
    });
});

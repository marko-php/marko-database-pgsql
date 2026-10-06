<?php

declare(strict_types=1);

use Marko\Database\Exceptions\CheckConstraintViolationException;
use Marko\Database\Exceptions\ConstraintViolationException;
use Marko\Database\Exceptions\DeadlockException;
use Marko\Database\Exceptions\ForeignKeyConstraintViolationException;
use Marko\Database\Exceptions\LockTimeoutException;
use Marko\Database\Exceptions\NotNullConstraintViolationException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Exceptions\SerializationFailureException;
use Marko\Database\Exceptions\TransactionConflictException;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\PgSql\Connection\PgSqlExceptionTranslator;

/**
 * Builds a PDOException shaped like the ones pdo_pgsql throws: the SQLSTATE
 * in errorInfo[0] and the exception code, driver code 7, and the server
 * message (with its DETAIL line) in errorInfo[2].
 */
function pgsqlDriverError(
    string $sqlState,
    string $serverMessage,
): PDOException {
    $exception = new PDOException("SQLSTATE[$sqlState]: Integrity constraint violation: 7 $serverMessage");
    $exception->errorInfo = [$sqlState, 7, $serverMessage];
    (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, $sqlState);

    return $exception;
}

describe('PgSqlExceptionTranslator', function (): void {
    it('translates SQLSTATE 23505 into a unique violation with the constraint name', function (): void {
        $pdoException = pgsqlDriverError(
            '23505',
            "ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\n"
            . 'DETAIL:  Key (email)=(taken@example.com) already exists.',
        );

        $exception = new PgSqlExceptionTranslator()->translate(
            $pdoException,
            'INSERT INTO users (email) VALUES ($1)',
            ['taken@example.com'],
        );

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('users_email_unique')
            ->and($exception->table())->toBe('users')
            ->and($exception->column())->toBe('email')
            ->and($exception->getPrevious())->toBe($pdoException)
            ->and($exception->getMessage())->toBe("Unique constraint 'users_email_unique' violated on table 'users'")
            ->and($exception->getMessage())->not->toContain('taken@example.com');
    });

    it('translates SQLSTATE 23503 into a foreign key violation with constraint and table', function (): void {
        $onDelete = pgsqlDriverError(
            '23503',
            'ERROR:  update or delete on table "users" violates foreign key constraint "posts_user_id_fkey" '
            . "on table \"posts\"\nDETAIL:  Key (id)=(1) is still referenced from table \"posts\".",
        );
        $onInsert = pgsqlDriverError(
            '23503',
            'ERROR:  insert or update on table "posts" violates foreign key constraint "posts_user_id_fkey"'
            . "\nDETAIL:  Key (user_id)=(99) is not present in table \"users\".",
        );
        $translator = new PgSqlExceptionTranslator();

        $deleted = $translator->translate($onDelete, 'DELETE FROM users WHERE id = $1', [1]);
        $inserted = $translator->translate($onInsert, 'INSERT INTO posts (user_id) VALUES ($1)', [99]);

        expect($deleted)->toBeInstanceOf(ForeignKeyConstraintViolationException::class)
            ->and($deleted->constraintName())->toBe('posts_user_id_fkey')
            ->and($deleted->table())->toBe('posts')
            ->and($inserted)->toBeInstanceOf(ForeignKeyConstraintViolationException::class)
            ->and($inserted->constraintName())->toBe('posts_user_id_fkey')
            ->and($inserted->table())->toBe('posts');
    });

    it('translates SQLSTATE 23502 into a not-null violation with column and table', function (): void {
        $pdoException = pgsqlDriverError(
            '23502',
            "ERROR:  null value in column \"email\" of relation \"users\" violates not-null constraint\n"
            . 'DETAIL:  Failing row contains (1, null, secret-name).',
        );

        $exception = new PgSqlExceptionTranslator()->translate(
            $pdoException,
            'INSERT INTO users (email, name) VALUES ($1, $2)',
            [null, 'secret-name'],
        );

        expect($exception)->toBeInstanceOf(NotNullConstraintViolationException::class)
            ->and($exception->column())->toBe('email')
            ->and($exception->table())->toBe('users')
            ->and($exception->getMessage())->not->toContain('secret-name');
    });

    it('reads the table from the SQL when an older server omits the relation', function (): void {
        $pdoException = pgsqlDriverError('23502', 'ERROR:  null value in column "email" violates not-null constraint');

        $exception = new PgSqlExceptionTranslator()->translate(
            $pdoException,
            'UPDATE "users" SET email = $1 WHERE id = $2',
            [null, 1],
        );

        expect($exception)->toBeInstanceOf(NotNullConstraintViolationException::class)
            ->and($exception->column())->toBe('email')
            ->and($exception->table())->toBe('users');
    });

    it('translates SQLSTATE 23514 into a check violation with the constraint name', function (): void {
        $pdoException = pgsqlDriverError(
            '23514',
            "ERROR:  new row for relation \"products\" violates check constraint \"products_price_check\"\n"
            . 'DETAIL:  Failing row contains (1, -5).',
        );

        $exception = new PgSqlExceptionTranslator()->translate(
            $pdoException,
            'INSERT INTO products (price) VALUES ($1)',
            [-5],
        );

        expect($exception)->toBeInstanceOf(CheckConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('products_price_check')
            ->and($exception->table())->toBe('products');
    });

    it('falls back to QueryException for any other SQLSTATE', function (): void {
        $pdoException = pgsqlDriverError('42P01', 'ERROR:  relation "missing" does not exist');

        $exception = new PgSqlExceptionTranslator()->translate($pdoException, 'SELECT * FROM missing', []);

        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception)->not->toBeInstanceOf(ConstraintViolationException::class)
            ->and($exception)->not->toBeInstanceOf(TransactionConflictException::class)
            ->and($exception)->not->toBeInstanceOf(LockTimeoutException::class)
            ->and($exception->sqlState())->toBe('42P01')
            ->and($exception->getPrevious())->toBe($pdoException);
    });

    it('reads the SQLSTATE from the exception code when errorInfo is missing', function (): void {
        $pdoException = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "t_key"',
        );
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdoException, '23505');

        $exception = new PgSqlExceptionTranslator()->translate($pdoException, 'INSERT INTO t (a) VALUES ($1)', [1]);

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('t_key');
    });

    it('translates SQLSTATE 40P01 into DeadlockException', function (): void {
        $pdoException = pgsqlDriverError(
            '40P01',
            "ERROR:  deadlock detected\nDETAIL:  Process 1 waits for ShareLock on transaction 2; blocked by process 3.",
        );

        $exception = new PgSqlExceptionTranslator()->translate($pdoException, 'UPDATE accounts SET n = $1', [1]);

        expect($exception)->toBeInstanceOf(DeadlockException::class)
            ->and($exception->sqlState())->toBe('40P01')
            ->and($exception->getPrevious())->toBe($pdoException);
    });

    it('translates SQLSTATE 40001 into SerializationFailureException', function (): void {
        $pdoException = pgsqlDriverError('40001', 'ERROR:  could not serialize access due to concurrent update');

        $exception = new PgSqlExceptionTranslator()->translate($pdoException, 'UPDATE accounts SET n = $1', [1]);

        expect($exception)->toBeInstanceOf(SerializationFailureException::class)
            ->and($exception->sqlState())->toBe('40001');
    });

    it('translates SQLSTATE 55P03 into LockTimeoutException', function (): void {
        $pdoException = pgsqlDriverError('55P03', 'ERROR:  could not obtain lock on row in relation "jobs"');

        $exception = new PgSqlExceptionTranslator()->translate(
            $pdoException,
            'SELECT * FROM "jobs" WHERE "id" = $1 FOR UPDATE NOWAIT',
            [1],
        );

        expect($exception)->toBeInstanceOf(LockTimeoutException::class)
            ->and($exception->sqlState())->toBe('55P03');
    });
});

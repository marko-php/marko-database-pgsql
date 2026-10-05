<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Exceptions\CheckConstraintViolationException;
use Marko\Database\Exceptions\ForeignKeyConstraintViolationException;
use Marko\Database\Exceptions\NotNullConstraintViolationException;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Tests\Fixtures\Constraint\ConstraintPost;
use Marko\Database\PgSql\Tests\Fixtures\Constraint\ConstraintPostRepository;
use Marko\Database\PgSql\Tests\Fixtures\Constraint\ConstraintUser;
use Marko\Database\PgSql\Tests\Fixtures\Constraint\ConstraintUserRepository;
use Marko\Database\PgSql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use PDOException;
use Throwable;

/**
 * Runs against a real PostgreSQL server. Set MARKO_TEST_PGSQL_HOST (and
 * optionally MARKO_TEST_PGSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * constraint_users and constraint_posts tables.
 */
function pgsqlConstraintConfig(): ?DatabaseConfig
{
    $host = getenv('MARKO_TEST_PGSQL_HOST');

    if ($host === false || $host === '') {
        return null;
    }

    return SharedConnectionContainer::config(
        host: $host,
        port: (int) (getenv('MARKO_TEST_PGSQL_PORT') ?: 5432),
        database: getenv('MARKO_TEST_PGSQL_DATABASE') ?: 'marko_test',
        username: getenv('MARKO_TEST_PGSQL_USERNAME') ?: 'postgres',
        password: getenv('MARKO_TEST_PGSQL_PASSWORD') ?: '',
    );
}

/**
 * Run the callback and return what it threw, or null.
 */
function pgsqlConstraintCatch(
    callable $callback,
): ?Throwable {
    try {
        $callback();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

const PGSQL_CONSTRAINT_SKIP_REASON = 'Set MARKO_TEST_PGSQL_HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run against a real PostgreSQL server';

beforeEach(function (): void {
    $config = pgsqlConstraintConfig();

    if ($config === null) {
        return;
    }

    $this->schema = new PgSqlConnection($config);
    $this->schema->execute('DROP TABLE IF EXISTS constraint_posts, constraint_users');
    $this->schema->execute(
        'CREATE TABLE constraint_users (id SERIAL PRIMARY KEY, email VARCHAR(255) NOT NULL, '
        . 'CONSTRAINT constraint_users_email_unique UNIQUE (email))',
    );
    $this->schema->execute(
        'CREATE TABLE constraint_posts (id SERIAL PRIMARY KEY, user_id INTEGER NOT NULL, '
        . 'CONSTRAINT constraint_posts_user_id_foreign FOREIGN KEY (user_id) REFERENCES constraint_users (id), '
        . 'CONSTRAINT constraint_posts_user_id_positive CHECK (user_id > 0))',
    );

    $this->container = SharedConnectionContainer::build($config);
    $this->users = $this->container->get(ConstraintUserRepository::class);
    $this->posts = $this->container->get(ConstraintPostRepository::class);
});

afterEach(function (): void {
    if (isset($this->schema)) {
        $this->schema->execute('DROP TABLE IF EXISTS constraint_posts, constraint_users');
        $this->schema->disconnect();
    }
});

function pgsqlConstraintUser(
    string $email,
): ConstraintUser {
    $user = new ConstraintUser();
    $user->email = $email;

    return $user;
}

describe('PostgreSQL constraint violations', function (): void {
    it('throws a unique violation naming the constraint when saving a duplicate', function (): void {
        $this->users->save(pgsqlConstraintUser('taken@example.com'));

        $exception = pgsqlConstraintCatch(fn () => $this->users->save(pgsqlConstraintUser('taken@example.com')));

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_users_email_unique')
            ->and($exception->table())->toBe('constraint_users')
            ->and($exception->column())->toBe('email')
            ->and($exception->sqlState())->toBe('23505');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('throws a foreign key violation when deleting a referenced row', function (): void {
        $user = pgsqlConstraintUser('author@example.com');
        $this->users->save($user);
        $post = new ConstraintPost();
        $post->userId = (int) $user->id;
        $this->posts->save($post);

        $exception = pgsqlConstraintCatch(fn () => $this->users->delete($user));

        expect($exception)->toBeInstanceOf(ForeignKeyConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_posts_user_id_foreign')
            ->and($exception->table())->toBe('constraint_posts');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('throws a foreign key violation when inserting a row with a missing parent', function (): void {
        $post = new ConstraintPost();
        $post->userId = 999999;

        $exception = pgsqlConstraintCatch(fn () => $this->posts->save($post));

        expect($exception)->toBeInstanceOf(ForeignKeyConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_posts_user_id_foreign')
            ->and($exception->table())->toBe('constraint_posts');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('throws a not-null violation naming the column for a raw insert', function (): void {
        $exception = pgsqlConstraintCatch(
            fn () => $this->schema->execute('INSERT INTO constraint_users (email) VALUES (?)', [null]),
        );

        expect($exception)->toBeInstanceOf(NotNullConstraintViolationException::class)
            ->and($exception->column())->toBe('email')
            ->and($exception->table())->toBe('constraint_users');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('throws a check violation naming the constraint', function (): void {
        $post = new ConstraintPost();
        $post->userId = -1;

        $exception = pgsqlConstraintCatch(fn () => $this->posts->save($post));

        expect($exception)->toBeInstanceOf(CheckConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_posts_user_id_positive')
            ->and($exception->table())->toBe('constraint_posts');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('keeps the original PDOException as the previous exception', function (): void {
        $this->users->save(pgsqlConstraintUser('taken@example.com'));

        $exception = pgsqlConstraintCatch(fn () => $this->users->save(pgsqlConstraintUser('taken@example.com')));

        expect($exception?->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and($exception?->getPrevious()?->getCode())->toBe('23505');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('does not leak the duplicate value into the message', function (): void {
        $this->users->save(pgsqlConstraintUser('private@example.com'));

        $exception = pgsqlConstraintCatch(fn () => $this->users->save(pgsqlConstraintUser('private@example.com')));

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->getMessage())->not->toContain('private@example.com')
            ->and($exception->getContext())->not->toContain('private@example.com')
            ->and($exception->getPrevious()?->getMessage())->toContain('private@example.com');
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');

    it('rethrows the typed violation from insertBatch with the PDOException as previous', function (): void {
        $this->users->save(pgsqlConstraintUser('taken@example.com'));

        $exception = pgsqlConstraintCatch(fn () => $this->users->insertBatch([
            pgsqlConstraintUser('fresh@example.com'),
            pgsqlConstraintUser('taken@example.com'),
        ]));

        expect($exception)->toBeInstanceOf(UniqueConstraintViolationException::class)
            ->and($exception->constraintName())->toBe('constraint_users_email_unique')
            ->and($exception->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and((int) $this->schema->query('SELECT COUNT(*) AS total FROM constraint_users')[0]['total'])->toBe(1);
    })->skip(fn (): bool => pgsqlConstraintConfig() === null, PGSQL_CONSTRAINT_SKIP_REASON)->group('integration');
});

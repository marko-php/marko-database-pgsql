<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Introspection;

use Closure;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Introspection\ExpressionDefaultMatcherInterface;
use Marko\Database\PgSql\Exceptions\ConnectionException;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\Schema\Expression;
use PDOException;

/**
 * A connection whose real events.expires_at column is a timestamp storing $storedDefault, and whose probe
 * column stores $probeDefault.
 */
function probeConnection(
    string $storedDefault,
    string $probeDefault,
    ?Closure $onExecute = null,
): ProbeRecordingConnection {
    return new ProbeRecordingConnection(
        fn (string $sql, array $bindings): array => [[
            'column_type' => 'timestamp without time zone',
            'column_default' => $bindings[0] === 'pg_temp.marko_default_probe' ? $probeDefault : $storedDefault,
        ]],
        $onExecute,
    );
}

describe('PgSqlIntrospector expression default matching', function (): void {
    it('implements ExpressionDefaultMatcherInterface', function (): void {
        expect(new PgSqlIntrospector(probeConnection('', '')))->toBeInstanceOf(
            ExpressionDefaultMatcherInterface::class,
        );
    });

    it('reports a match when the probe stores the same default text as the column', function (): void {
        $connection = probeConnection("(now() + '1 day'::interval)", "(now() + '1 day'::interval)");

        $matches = new PgSqlIntrospector($connection)
            ->matchesStoredDefault('events', 'expires_at', new Expression("now() + interval '1 day'"));

        expect($matches)->toBeTrue()
            ->and($connection->log)->toContain(
                'CREATE TEMP TABLE "marko_default_probe" ("probe" timestamp without time zone DEFAULT '
                . "now() + interval '1 day')",
            );
    });

    it('reports no match when the probe stores a different default text', function (): void {
        $connection = probeConnection("(now() + '1 day'::interval)", "(now() + '2 days'::interval)");

        expect(
            new PgSqlIntrospector($connection)
                ->matchesStoredDefault('events', 'expires_at', new Expression("now() + interval '2 days'")),
        )->toBeFalse();
    });

    it('rolls back the probe transaction', function (): void {
        $connection = probeConnection("(now() + '1 day'::interval)", "(now() + '1 day'::interval)");

        new PgSqlIntrospector($connection)
            ->matchesStoredDefault('events', 'expires_at', new Expression("now() + interval '1 day'"));

        $statements = array_values(array_filter(
            $connection->log,
            fn (string $entry): bool => in_array($entry, ['BEGIN', 'ROLLBACK', 'COMMIT'], true)
                || str_starts_with($entry, 'CREATE'),
        ));

        expect($statements[0])->toBe('BEGIN')
            ->and($statements[1])->toStartWith('CREATE TEMP TABLE')
            ->and($statements[2])->toBe('ROLLBACK')
            ->and($statements)->toHaveCount(3)
            ->and($connection->transactionLevel())->toBe(0);
    });

    it('reads the probe column from the session\'s temporary schema', function (): void {
        $connection = probeConnection("(now() + '1 day'::interval)", "(now() + '1 day'::interval)");

        new PgSqlIntrospector($connection, 'app')
            ->matchesStoredDefault('events', 'expires_at', new Expression("now() + interval '1 day'"));

        expect($connection->queryBindings)->toBe([
            ['"app"."events"', 'expires_at'],
            ['pg_temp.marko_default_probe', 'probe'],
        ]);
    });

    it(
        'throws a MigrationException naming the column and expression when PostgreSQL rejects the expression',
        function (): void {
            $connection = probeConnection(
                "(now() + '1 day'::interval)",
                '',
                function (string $sql): void {
                    if (str_starts_with($sql, 'CREATE')) {
                        throw new QueryException('Query failed: syntax error at or near "intervall"', $sql);
                    }
                },
            );

            expect(
                fn () => new PgSqlIntrospector($connection)
                    ->matchesStoredDefault('events', 'expires_at', new Expression("now() + intervall '1 day'")),
            )->toThrow(
                MigrationException::class,
                "The database rejected the default expression \"now() + intervall '1 day'\" of column "
                . "'events.expires_at'",
            )->and($connection->log)->toContain('ROLLBACK')
                ->and($connection->transactionLevel())->toBe(0);
        },
    );

    it('lets a connection failure propagate without reporting a rejected expression', function (): void {
        $connection = probeConnection(
            "(now() + '1 day'::interval)",
            '',
            function (string $sql): void {
                throw ConnectionException::connectionFailed(
                    'localhost',
                    5432,
                    'marko',
                    new PDOException('connection lost'),
                );
            },
        );

        expect(
            fn () => new PgSqlIntrospector($connection)
                ->matchesStoredDefault('events', 'expires_at', new Expression("now() + interval '1 day'")),
        )->toThrow(ConnectionException::class);
    });
});

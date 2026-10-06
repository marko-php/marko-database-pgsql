<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Diff\DiffCalculator;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\Table;

/*
 * Runs against a real PostgreSQL server. Set MARKO_TEST_PGSQL_HOST (and
 * optionally MARKO_TEST_PGSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * modify_column_posts table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

/**
 * The modify_column_posts table as an entity would declare it.
 */
function pgsqlModifyColumnTable(
    Column ...$columns,
): Table {
    return new Table(
        name: 'modify_column_posts',
        columns: [
            new Column(name: 'id', type: 'integer', primaryKey: true, autoIncrement: true),
            ...$columns,
        ],
    );
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS modify_column_posts');
    $this->generator = new PgSqlGenerator();
    $this->calculator = new DiffCalculator();
    $this->introspector = new PgSqlIntrospector($this->connection);

    $this->original = pgsqlModifyColumnTable(
        new Column(name: 'status', type: 'varchar', length: 20, default: 'draft'),
        new Column(name: 'title', type: 'varchar', length: 255, nullable: true),
    );
    $this->connection->execute($this->generator->generateCreateTable($this->original));

    $this->diffAgainst = fn (Table $entityTable) => $this->calculator->calculate(
        ['modify_column_posts' => $entityTable],
        ['modify_column_posts' => $this->introspector->getTable('modify_column_posts')],
    );

    $this->run = function (array $statements): void {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    };
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS modify_column_posts');
        $this->connection->disconnect();
    }
});

describe('PostgreSQL column modification migrations', function (): void {
    it('yields an empty diff after applying a generated default change', function (): void {
        $entityTable = pgsqlModifyColumnTable(
            new Column(name: 'status', type: 'varchar', length: 20, default: 'live'),
            new Column(name: 'title', type: 'varchar', length: 255, nullable: true),
        );

        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));

        expect($diff->isEmpty())->toBeFalse()
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue()
            ->and($this->introspector->getTable('modify_column_posts')->columns[1]->default)->toBe('live');
    });

    it('yields an empty diff after applying a generated nullability change', function (): void {
        $entityTable = pgsqlModifyColumnTable(
            new Column(name: 'status', type: 'varchar', length: 20, default: 'draft'),
            new Column(name: 'title', type: 'varchar', length: 255),
        );

        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));

        expect($diff->isEmpty())->toBeFalse()
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue()
            ->and($this->introspector->getTable('modify_column_posts')->columns[2]->nullable)->toBeFalse();
    });

    it('keeps the database default when the entity declares none and only nullability changes', function (): void {
        $entityTable = pgsqlModifyColumnTable(
            new Column(name: 'status', type: 'varchar', length: 20, nullable: true),
            new Column(name: 'title', type: 'varchar', length: 255, nullable: true),
        );

        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));
        $status = $this->introspector->getTable('modify_column_posts')->columns[1];

        expect($diff->isEmpty())->toBeFalse()
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue()
            ->and($status->nullable)->toBeTrue()
            ->and($status->default)->toBe('draft');
    });

    it('restores the original columns when the down migration runs', function (): void {
        $entityTable = pgsqlModifyColumnTable(
            new Column(name: 'status', type: 'text', nullable: true, default: 'live'),
            new Column(name: 'title', type: 'varchar', length: 255),
        );

        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));
        ($this->run)($this->generator->generateDown($diff));

        expect(($this->diffAgainst)($this->original)->isEmpty())->toBeTrue();
    });
});

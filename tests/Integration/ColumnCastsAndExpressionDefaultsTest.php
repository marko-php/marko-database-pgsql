<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Diff\DiffCalculator;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\Expression;
use Marko\Database\Schema\Literal;
use Marko\Database\Schema\Table;

/*
 * Type-change casts and expression defaults against a real PostgreSQL server.
 * Set MARKO_TEST_PGSQL_HOST (and optionally MARKO_TEST_PGSQL_PORT, _DATABASE,
 * _USERNAME, _PASSWORD) to enable; the tests skip otherwise. The tests create
 * and drop the cast_default_items table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

/**
 * The cast_default_items table as an entity would declare it.
 */
function pgsqlCastDefaultTable(
    Column $id,
    Column ...$columns,
): Table {
    return new Table(name: 'cast_default_items', columns: [$id, ...$columns]);
}

function pgsqlSerialId(
    string $type = 'integer',
): Column {
    return new Column(name: 'id', type: $type, primaryKey: true, autoIncrement: true);
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    $this->connection->execute('DROP TABLE IF EXISTS cast_default_items');
    $this->generator = new PgSqlGenerator();
    $this->calculator = new DiffCalculator();
    $this->introspector = new PgSqlIntrospector($this->connection);

    $this->create = function (Table $table): void {
        $this->connection->execute($this->generator->generateCreateTable($table));
    };

    $this->diffAgainst = fn (Table $entityTable) => $this->calculator->calculate(
        ['cast_default_items' => $entityTable],
        ['cast_default_items' => $this->introspector->getTable('cast_default_items')],
    );

    $this->run = function (array $statements): void {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    };
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS cast_default_items');
        $this->connection->disconnect();
    }
});

describe('PostgreSQL type-change casts', function (): void {
    it('applies and rolls back a varchar to integer change on a populated table', function (): void {
        $original = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'quantity', type: 'varchar', length: 20),
        );
        ($this->create)($original);
        $this->connection->execute("INSERT INTO cast_default_items (quantity) VALUES ('7'), ('42')");

        $entityTable = pgsqlCastDefaultTable(pgsqlSerialId(), new Column(name: 'quantity', type: 'integer'));
        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));

        $up = $this->connection->query('SELECT quantity FROM cast_default_items ORDER BY id');
        $upDiffIsEmpty = ($this->diffAgainst)($entityTable)->isEmpty();

        ($this->run)($this->generator->generateDown($diff));

        expect(array_column($up, 'quantity'))->toBe([7, 42])
            ->and($upDiffIsEmpty)->toBeTrue()
            ->and(
                array_column(
                    $this->connection->query('SELECT quantity FROM cast_default_items ORDER BY id'),
                    'quantity',
                ),
            )
            ->toBe(['7', '42'])
            ->and(($this->diffAgainst)($original)->isEmpty())->toBeTrue();
    });

    it('changes the type of a column whose default cannot be cast implicitly', function (): void {
        $original = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'quantity', type: 'varchar', length: 20, default: '0'),
        );
        ($this->create)($original);
        $this->connection->execute("INSERT INTO cast_default_items (quantity) VALUES ('3')");

        $entityTable = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'quantity', type: 'integer', default: 5),
        );
        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));
        $this->connection->execute('INSERT INTO cast_default_items DEFAULT VALUES');

        $up = array_column(
            $this->connection->query('SELECT quantity FROM cast_default_items ORDER BY id'),
            'quantity',
        );
        $upDiffIsEmpty = ($this->diffAgainst)($entityTable)->isEmpty();

        ($this->run)($this->generator->generateDown($diff));

        expect($up)->toBe([3, 5])
            ->and($upDiffIsEmpty)->toBeTrue()
            ->and(($this->diffAgainst)($original)->isEmpty())->toBeTrue();
    });

    it('widens an auto-increment integer key to bigint and keeps the sequence default', function (): void {
        $original = pgsqlCastDefaultTable(pgsqlSerialId(), new Column(name: 'label', type: 'varchar', length: 20));
        ($this->create)($original);
        $this->connection->execute("INSERT INTO cast_default_items (label) VALUES ('first')");

        $entityTable = pgsqlCastDefaultTable(
            pgsqlSerialId('bigint'),
            new Column(name: 'label', type: 'varchar', length: 20),
        );
        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));
        $this->connection->execute("INSERT INTO cast_default_items (label) VALUES ('second')");
        $id = $this->introspector->getTable('cast_default_items')->columns[0];

        expect($diff->isEmpty())->toBeFalse()
            ->and($id->type)->toBe('bigint')
            ->and($id->autoIncrement)->toBeTrue()
            ->and(array_column($this->connection->query('SELECT id FROM cast_default_items ORDER BY id'), 'id'))
            ->toBe([1, 2])
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });
});

describe('PostgreSQL expression defaults', function (): void {
    it('creates a uuid primary key defaulting to gen_random_uuid() and diffs clean', function (): void {
        $entityTable = pgsqlCastDefaultTable(
            new Column(name: 'id', type: 'uuid', primaryKey: true, default: 'gen_random_uuid()'),
            new Column(name: 'label', type: 'varchar', length: 20),
            new Column(name: 'created_at', type: 'timestamp', default: new Expression('CURRENT_TIMESTAMP')),
        );
        ($this->create)($entityTable);
        $this->connection->execute("INSERT INTO cast_default_items (label) VALUES ('first')");

        $row = $this->connection->query('SELECT id, created_at FROM cast_default_items')[0];

        expect($row['id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/')
            ->and($row['created_at'])->not->toBeNull()
            ->and($this->introspector->getTable('cast_default_items')->columns[0]->default)
            ->toEqual(new Expression('gen_random_uuid()'))
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('stores a function-looking literal default as a string', function (): void {
        $entityTable = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'label', type: 'varchar', length: 20, default: new Literal('now()')),
        );
        ($this->create)($entityTable);
        $this->connection->execute('INSERT INTO cast_default_items DEFAULT VALUES');

        expect($this->connection->query('SELECT label FROM cast_default_items')[0]['label'])->toBe('now()')
            ->and($this->introspector->getTable('cast_default_items')->columns[1]->default)
            ->toEqual(new Literal('now()'))
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('restores an expression default unquoted in a down migration', function (): void {
        $original = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'ref', type: 'uuid', default: new Expression('gen_random_uuid()')),
        );
        ($this->create)($original);

        $entityTable = pgsqlCastDefaultTable(pgsqlSerialId(), new Column(name: 'ref', type: 'uuid', nullable: true));
        $diff = ($this->diffAgainst)($entityTable);
        ($this->run)($this->generator->generateUp($diff));
        ($this->run)($this->generator->generateDown($diff));
        $this->connection->execute('DELETE FROM cast_default_items');
        $this->connection->execute('INSERT INTO cast_default_items DEFAULT VALUES');

        expect($this->connection->query('SELECT ref FROM cast_default_items')[0]['ref'])->toBeString()
            ->and(($this->diffAgainst)($original)->isEmpty())->toBeTrue();
    });
});

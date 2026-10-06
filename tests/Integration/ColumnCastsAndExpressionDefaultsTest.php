<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Core\Path\ProjectPaths;
use Marko\Database\Diff\DiffCalculator;
use Marko\Database\Diff\ExpressionDefaultCanonicalizer;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Migration\MigrationGenerator;
use Marko\Database\Migration\MigrationRepository;
use Marko\Database\Migration\Migrator;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\Expression;
use Marko\Database\Schema\Literal;
use Marko\Database\Schema\Table;
use Marko\Testing\Fake\FakeClock;

/*
 * Type-change casts, auto-increment sequence type changes and expression
 * defaults against a real PostgreSQL server. Set MARKO_TEST_PGSQL_HOST (and
 * optionally MARKO_TEST_PGSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to
 * enable; the tests skip otherwise. The tests create and drop the
 * cast_default_items table, the cast_default_items_loose_seq sequence and the
 * migrations table.
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

    // As db:diff does: expression defaults PostgreSQL respells are settled before the diff
    $this->diffAgainst = function (Table $entityTable) {
        $databaseSchema = ['cast_default_items' => $this->introspector->getTable('cast_default_items')];

        return $this->calculator->calculate(
            new ExpressionDefaultCanonicalizer($this->introspector)->canonicalize(
                ['cast_default_items' => $entityTable],
                $databaseSchema,
            ),
            $databaseSchema,
        );
    };

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

describe('PostgreSQL auto-increment sequence type changes', function (): void {
    beforeEach(function (): void {
        $this->label = new Column(name: 'label', type: 'varchar', length: 20);

        // The data type and maximum of the sequence that feeds cast_default_items.id
        $this->sequence = function (): array {
            $row = $this->connection->query(
                "SELECT s.data_type::text AS data_type, s.max_value::text AS max_value FROM pg_sequences s
                    WHERE format('%I.%I', s.schemaname, s.sequencename)::regclass
                        = pg_get_serial_sequence('cast_default_items', 'id')::regclass",
            )[0];

            return [$row['data_type'], $row['max_value']];
        };

        $this->setSequence = function (string $value): void {
            $this->connection->execute(
                "SELECT setval(pg_get_serial_sequence('cast_default_items', 'id'), $value)",
            );
        };

        $this->insertLabel = function (string $label): int {
            return (int) $this->connection->query(
                "INSERT INTO cast_default_items (label) VALUES ('$label') RETURNING id",
            )[0]['id'];
        };

        $this->idType = fn (): string => $this->introspector->getTable('cast_default_items')->columns[0]->type;

        // What an interrupted earlier run may have left behind
        $this->dropLeftovers = function (): void {
            $this->connection->execute('DROP TABLE IF EXISTS "CastDefaultItems"');
            $this->connection->execute('DROP TABLE IF EXISTS migrations');
            $this->connection->execute('DROP SEQUENCE IF EXISTS cast_default_items_loose_seq');
        };
        ($this->dropLeftovers)();
    });

    afterEach(function (): void {
        if (isset($this->connection)) {
            $this->connection->execute('DROP TABLE IF EXISTS cast_default_items');
            ($this->dropLeftovers)();
        }
    });

    it('widens the sequence with an integer key so ids pass 2147483647', function (): void {
        ($this->create)(pgsqlCastDefaultTable(pgsqlSerialId(), $this->label));
        ($this->insertLabel)('first');
        $entityTable = pgsqlCastDefaultTable(pgsqlSerialId('bigint'), $this->label);

        ($this->run)($this->generator->generateUp(($this->diffAgainst)($entityTable)));
        ($this->setSequence)('2147483647');

        expect(($this->insertLabel)('next'))->toBe(2147483648)
            ->and(($this->sequence)())->toBe(['bigint', '9223372036854775807'])
            ->and(($this->idType)())->toBe('bigint')
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('narrows the sequence back to integer in the down migration', function (): void {
        $original = pgsqlCastDefaultTable(pgsqlSerialId(), $this->label);
        ($this->create)($original);
        ($this->insertLabel)('first');
        $diff = ($this->diffAgainst)(pgsqlCastDefaultTable(pgsqlSerialId('bigint'), $this->label));

        ($this->run)($this->generator->generateUp($diff));
        ($this->run)($this->generator->generateDown($diff));

        expect(($this->sequence)())->toBe(['integer', '2147483647'])
            ->and(($this->insertLabel)('second'))->toBe(2)
            ->and(($this->diffAgainst)($original)->isEmpty())->toBeTrue();
    });

    it(
        'fails the down migration loudly when the sequence value does not fit and leaves the column unchanged',
        function (): void {
            ($this->create)(pgsqlCastDefaultTable(pgsqlSerialId(), $this->label));
            $diff = ($this->diffAgainst)(pgsqlCastDefaultTable(pgsqlSerialId('bigint'), $this->label));
            ($this->run)($this->generator->generateUp($diff));
            ($this->setSequence)('3000000000');

            expect(fn () => ($this->run)($this->generator->generateDown($diff)))
                ->toThrow(QueryException::class, 'cannot be greater than MAXVALUE (2147483647)')
                ->and(($this->idType)())->toBe('bigint')
                ->and(($this->sequence)())->toBe(['bigint', '9223372036854775807']);
        },
    );

    it('widens the sequence of a smallint key changed to integer', function (): void {
        $original = pgsqlCastDefaultTable(pgsqlSerialId('smallint'), $this->label);
        ($this->create)($original);
        $diff = ($this->diffAgainst)(pgsqlCastDefaultTable(pgsqlSerialId(), $this->label));

        ($this->run)($this->generator->generateUp($diff));
        ($this->setSequence)('32767');
        $id = ($this->insertLabel)('next');
        $sequence = ($this->sequence)();
        $this->connection->execute('DELETE FROM cast_default_items');
        ($this->setSequence)('1');
        ($this->run)($this->generator->generateDown($diff));

        expect($id)->toBe(32768)
            ->and($sequence)->toBe(['integer', '2147483647'])
            ->and(($this->sequence)())->toBe(['smallint', '32767'])
            ->and(($this->diffAgainst)($original)->isEmpty())->toBeTrue();
    });

    it('fails naming the table and column when the key\'s sequence is not owned by it', function (): void {
        $this->connection->execute('CREATE SEQUENCE cast_default_items_loose_seq AS integer');
        $this->connection->execute(
            "CREATE TABLE cast_default_items (id integer PRIMARY KEY DEFAULT nextval('cast_default_items_loose_seq'),"
            . ' label varchar(20))',
        );
        $diff = ($this->diffAgainst)(pgsqlCastDefaultTable(pgsqlSerialId('bigint'), $this->label));

        expect(fn () => ($this->run)($this->generator->generateUp($diff)))
            ->toThrow(
                QueryException::class,
                'Column "id" of table "cast_default_items" is auto-increment, but no sequence is owned by it',
            )
            ->and(($this->idType)())->toBe('integer');
    });

    it('widens an identity key and its sequence', function (): void {
        $this->connection->execute(
            'CREATE TABLE cast_default_items (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,'
            . ' label varchar(20))',
        );
        $entityTable = pgsqlCastDefaultTable(pgsqlSerialId('bigint'), $this->label);
        $diff = ($this->diffAgainst)($entityTable);

        ($this->run)($this->generator->generateUp($diff));
        ($this->setSequence)('2147483647');
        $id = ($this->insertLabel)('next');
        $sequence = ($this->sequence)();
        $upDiffIsEmpty = ($this->diffAgainst)($entityTable)->isEmpty();
        $this->connection->execute('DELETE FROM cast_default_items');
        ($this->setSequence)('1');
        ($this->run)($this->generator->generateDown($diff));

        expect($id)->toBe(2147483648)
            ->and($sequence)->toBe(['bigint', '9223372036854775807'])
            ->and($upDiffIsEmpty)->toBeTrue()
            ->and(($this->sequence)())->toBe(['integer', '2147483647']);
    });

    it('finds the sequence of a mixed-case table', function (): void {
        $original = new Table(name: 'CastDefaultItems', columns: [pgsqlSerialId(), $this->label]);
        $entityTable = new Table(name: 'CastDefaultItems', columns: [pgsqlSerialId('bigint'), $this->label]);
        ($this->create)($original);
        $diff = $this->calculator->calculate(
            ['CastDefaultItems' => $entityTable],
            ['CastDefaultItems' => $this->introspector->getTable('CastDefaultItems')],
        );

        ($this->run)($this->generator->generateUp($diff));

        expect(
            $this->connection->query(
                "SELECT data_type::text AS data_type FROM pg_sequences
                    WHERE format('%I.%I', schemaname, sequencename)::regclass
                        = pg_get_serial_sequence('\"CastDefaultItems\"', 'id')::regclass",
            )[0]['data_type'],
        )->toBe('bigint');
    });

    it('runs a generated migration file that widens and narrows the sequence', function (): void {
        $original = pgsqlCastDefaultTable(pgsqlSerialId(), $this->label);
        ($this->create)($original);
        $projectPath = sys_get_temp_dir() . '/marko-pgsql-sequence-' . bin2hex(random_bytes(6));
        mkdir("$projectPath/database/migrations", recursive: true);
        $paths = new ProjectPaths($projectPath);
        $migrator = new Migrator($this->connection, new MigrationRepository(), $paths);

        try {
            $files = new MigrationGenerator($this->generator, $paths, new FakeClock())->generate(
                ($this->diffAgainst)(pgsqlCastDefaultTable(pgsqlSerialId('bigint'), $this->label)),
            );
            $migrator->migrate();
            ($this->setSequence)('2147483647');
            $id = ($this->insertLabel)('next');
            $upSequence = ($this->sequence)();
            $this->connection->execute('DELETE FROM cast_default_items');
            ($this->setSequence)('1');
            $migrator->rollback();

            expect(file_get_contents($files[0]))->toContain("DO \$\$\n")
                ->and($id)->toBe(2147483648)
                ->and($upSequence)->toBe(['bigint', '9223372036854775807'])
                ->and(($this->sequence)())->toBe(['integer', '2147483647'])
                ->and(($this->diffAgainst)($original)->isEmpty())->toBeTrue();
        } finally {
            array_map(unlink(...), glob("$projectPath/database/migrations/*.php") ?: []);
            rmdir("$projectPath/database/migrations");
            rmdir("$projectPath/database");
            rmdir($projectPath);
        }
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

    it('diffs an interval arithmetic expression default as empty right after creation', function (): void {
        $entityTable = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'expires_at', type: 'timestamp', default: new Expression("now() + interval '1 day'")),
            new Column(name: 'label', type: 'text', default: new Expression("'a' || 'b'")),
        );
        ($this->create)($entityTable);

        expect($this->introspector->getTable('cast_default_items')->columns[1]->default)
            ->toEqual(new Expression("(now() + '1 day'::interval)"))
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('still diffs a changed interval expression and sets the new default', function (): void {
        ($this->create)(pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'expires_at', type: 'timestamp', default: new Expression("now() + interval '1 day'")),
        ));

        $entityTable = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'expires_at', type: 'timestamp', default: new Expression("now() + interval '7 days'")),
        );
        $diff = ($this->diffAgainst)($entityTable);
        $statements = $this->generator->generateUp($diff);
        ($this->run)($statements);

        expect($statements)->toContain(
            "ALTER TABLE \"cast_default_items\" ALTER COLUMN \"expires_at\" SET DEFAULT now() + interval '7 days'",
        )
            ->and($this->introspector->getTable('cast_default_items')->columns[1]->default)
            ->toEqual(new Expression("(now() + '7 days'::interval)"))
            ->and(($this->diffAgainst)($entityTable)->isEmpty())->toBeTrue();
    });

    it('fails at diff time for an expression PostgreSQL rejects', function (): void {
        ($this->create)(pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'expires_at', type: 'timestamp', default: new Expression("now() + interval '1 day'")),
        ));

        $entityTable = pgsqlCastDefaultTable(
            pgsqlSerialId(),
            new Column(name: 'expires_at', type: 'timestamp', default: new Expression("now() + 'tomorrow'")),
        );

        expect(fn () => ($this->diffAgainst)($entityTable))->toThrow(
            MigrationException::class,
            "The database rejected the default expression \"now() + 'tomorrow'\" of column "
            . "'cast_default_items.expires_at'",
        );
    });

    it(
        'leaves no probe table behind and keeps the connection usable after a rejected expression',
        function (): void {
            ($this->create)(pgsqlCastDefaultTable(
                pgsqlSerialId(),
                new Column(name: 'expires_at', type: 'timestamp', default: new Expression("now() + interval '1 day'")),
            ));
            $rejected = pgsqlCastDefaultTable(
                pgsqlSerialId(),
                new Column(name: 'expires_at', type: 'timestamp', default: new Expression('now() +')),
            );

            try {
                ($this->diffAgainst)($rejected);
            } catch (MigrationException) {
                // Expected: the next statements must still run on the same connection
            }

            $probeTables = $this->connection->query(
                "SELECT relname FROM pg_class WHERE relname = 'marko_default_probe'",
            );

            expect($probeTables)->toBe([])
                ->and($this->connection->inTransaction())->toBeFalse()
                ->and($this->connection->query('SELECT 1 AS one')[0]['one'])->toBe(1);
        },
    );
});

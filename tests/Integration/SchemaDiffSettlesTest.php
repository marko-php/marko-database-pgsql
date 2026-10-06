<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Diff\DiffCalculator;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Entity\Entity;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\Exceptions\UniqueConstraintViolationException;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use Marko\Database\Schema\IdentifierName;
use Marko\Database\Schema\Table as SchemaTable;

/*
 * Schema diffs that must settle against a real PostgreSQL server: uniqueness added to or removed from an existing
 * column, including the UNIQUE constraint an inline UNIQUE creates. Entities go through EntityMetadataFactory and
 * SchemaBuilder, as db:migrate builds them. The tests create and drop the settle_* tables.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With MARKO_INTEGRATION_REQUIRED set (CI), a missing host
 * fails instead of skipping. Part of the integration-services group.
 */

/**
 * The schema table an entity declares.
 */
function pgsqlSettleSchema(
    object $entity,
): SchemaTable {
    return new SchemaBuilder()->build(new EntityMetadataFactory()->parse($entity::class));
}

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    $this->dropTables = function (): void {
        $tables = [
            'settle_customer_subscription_events',
            'settle_members',
            'settle_teams',
            'settle_users',
            'settle_pivots',
            'settle_tokens',
        ];

        foreach ($tables as $table) {
            $this->connection->execute("DROP TABLE IF EXISTS $table CASCADE");
        }
    };
    ($this->dropTables)();

    $this->generator = new PgSqlGenerator();
    $this->introspector = new PgSqlIntrospector($this->connection);

    $this->diffAgainst = function (SchemaTable $entityTable): SchemaDiff {
        $databaseTable = $this->introspector->getTable($entityTable->name);

        return new DiffCalculator()->calculate(
            [$entityTable->name => $entityTable],
            $databaseTable !== null ? [$entityTable->name => $databaseTable] : [],
        );
    };

    $this->run = function (array $statements): void {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    };

    $this->create = function (SchemaTable $entityTable): void {
        ($this->run)($this->generator->generateUp(($this->diffAgainst)($entityTable)));
    };

    $this->plainUsers = pgsqlSettleSchema(new #[Table('settle_users')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column(length: 191)]
        public string $email;
    });

    $this->uniqueUsers = pgsqlSettleSchema(new #[Table('settle_users')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column(length: 191, unique: true)]
        public string $email;
    });

    // Every name derived from this table and column is over 63 bytes before shortening
    $this->longBody = 'settle_customer_subscription_events_external_billing_reference_id';

    $this->plainEvents = pgsqlSettleSchema(new #[Table('settle_customer_subscription_events')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column]
        public int $externalBillingReferenceId;
    });

    $this->linkedEvents = pgsqlSettleSchema(new #[Table('settle_customer_subscription_events')] class () extends Entity
    {
        #[Column(primaryKey: true, autoIncrement: true)]
        public int $id;

        #[Column(unique: true, references: 'settle_users.id')]
        public int $externalBillingReferenceId;
    });

    $this->referencedEvents = pgsqlSettleSchema(
        new #[Table('settle_customer_subscription_events')]
        class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(references: 'settle_users.id')]
            public int $externalBillingReferenceId;
        },
    );
});

afterEach(function (): void {
    if (isset($this->connection)) {
        ($this->dropTables)();
        $this->connection->disconnect();
    }
});

describe('PostgreSQL schema diffs that settle', function (): void {
    it('diffs a table created with a unique column as empty', function (): void {
        ($this->create)($this->uniqueUsers);

        expect(($this->diffAgainst)($this->uniqueUsers)->isEmpty())->toBeTrue();
    });

    it('adds the unique index when a column becomes unique and the diff is then empty', function (): void {
        ($this->create)($this->plainUsers);
        $diff = ($this->diffAgainst)($this->uniqueUsers);
        ($this->run)($this->generator->generateUp($diff));

        expect($diff->isEmpty())->toBeFalse()
            ->and(($this->diffAgainst)($this->uniqueUsers)->isEmpty())->toBeTrue()
            ->and(fn () => $this->connection->execute(
                "INSERT INTO settle_users (email) VALUES ('a@example.com'), ('a@example.com')",
            ))->toThrow(UniqueConstraintViolationException::class);
    });

    it('drops the unique index when a column stops being unique and the diff is then empty', function (): void {
        ($this->create)($this->uniqueUsers);
        ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->plainUsers)));
        $this->connection->execute("INSERT INTO settle_users (email) VALUES ('a@example.com'), ('a@example.com')");

        expect(($this->diffAgainst)($this->plainUsers)->isEmpty())->toBeTrue();
    });

    it('restores uniqueness in down', function (): void {
        ($this->create)($this->uniqueUsers);
        $removal = ($this->diffAgainst)($this->plainUsers);
        ($this->run)($this->generator->generateUp($removal));
        ($this->run)($this->generator->generateDown($removal));

        expect(($this->diffAgainst)($this->uniqueUsers)->isEmpty())->toBeTrue()
            ->and(fn () => $this->connection->execute(
                "INSERT INTO settle_users (email) VALUES ('a@example.com'), ('a@example.com')",
            ))->toThrow(UniqueConstraintViolationException::class);
    });

    it('removes an added unique index in down', function (): void {
        ($this->create)($this->plainUsers);
        $addition = ($this->diffAgainst)($this->uniqueUsers);
        ($this->run)($this->generator->generateUp($addition));
        ($this->run)($this->generator->generateDown($addition));

        expect(($this->diffAgainst)($this->plainUsers)->isEmpty())->toBeTrue();
    });

    it('drops uniqueness from a foreign key column, migrates down, and the diff is then empty', function (): void {
        ($this->create)(pgsqlSettleSchema(new #[Table('settle_teams')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;
        }));

        $uniqueMembers = pgsqlSettleSchema(new #[Table('settle_members')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(unique: true, references: 'settle_teams.id')]
            public int $teamId;
        });

        $plainMembers = pgsqlSettleSchema(new #[Table('settle_members')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(references: 'settle_teams.id')]
            public int $teamId;
        });

        ($this->create)($uniqueMembers);
        $removal = ($this->diffAgainst)($plainMembers);
        ($this->run)($this->generator->generateUp($removal));
        $settledAfterUp = ($this->diffAgainst)($plainMembers)->isEmpty();
        ($this->run)($this->generator->generateDown($removal));

        expect($removal->isEmpty())->toBeFalse()
            ->and($settledAfterUp)->toBeTrue()
            ->and(($this->diffAgainst)($uniqueMembers)->isEmpty())->toBeTrue();
    });

    it(
        'adds a unique index and foreign key with over-long derived names and the diff is then empty',
        function (): void {
            ($this->create)($this->plainUsers);
            ($this->create)($this->plainEvents);
            ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->linkedEvents)));
            $table = $this->introspector->getTable('settle_customer_subscription_events');

            // PostgreSQL would silently truncate an over-long name, so check the names it holds, not just the diff
            expect(($this->diffAgainst)($this->linkedEvents)->isEmpty())->toBeTrue()
                ->and(array_column($table->indexes, 'name'))
                ->toContain(IdentifierName::derive($this->longBody, suffix: '_unique'))
                ->and(array_column($table->foreignKeys, 'name'))
                ->toContain(IdentifierName::derive($this->longBody, prefix: 'fk_'));
        },
    );

    it(
        'adds the over-long derived replacement index when a foreign key column stops being unique',
        function (): void {
            ($this->create)($this->plainUsers);
            ($this->create)($this->plainEvents);
            ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->linkedEvents)));
            ($this->run)($this->generator->generateUp(($this->diffAgainst)($this->referencedEvents)));
            $table = $this->introspector->getTable('settle_customer_subscription_events');

            expect(($this->diffAgainst)($this->referencedEvents)->isEmpty())->toBeTrue()
                ->and(array_column($table->indexes, 'name'))
                ->toContain(IdentifierName::derive($this->longBody, suffix: '_index'));
        },
    );
});

describe('PostgreSQL primary key columns added to existing tables', function (): void {
    beforeEach(function (): void {
        // A key-less pivot with rows, as tables created by hand before an entity owned them can be
        $this->connection->execute('CREATE TABLE settle_pivots (user_id INTEGER NOT NULL, role_id INTEGER NOT NULL)');
        $this->connection->execute('INSERT INTO settle_pivots (user_id, role_id) VALUES (1, 10), (2, 20)');

        $this->keyedPivots = pgsqlSettleSchema(new #[Table('settle_pivots')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column]
            public int $userId;

            #[Column]
            public int $roleId;
        });

        $this->primaryKeyColumns = fn (string $table): array => array_column($this->connection->query(
            'SELECT a.attname FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid '
            . "AND a.attnum = ANY (c.conkey) WHERE c.conrelid = '$table'::regclass AND c.contype = 'p'",
        ), 'attname');
        $this->columnOf = fn (string $table, string $name) => array_find(
            $this->introspector->getTable($table)->columns,
            fn ($column): bool => $column->name === $name,
        );
    });

    it('adds a serial primary key column to a table with rows and the diff is then empty', function (): void {
        $statements = $this->generator->generateUp(($this->diffAgainst)($this->keyedPivots));
        ($this->run)($statements);

        expect($statements)->toHaveCount(1)
            ->and($statements[0])->toContain('ADD PRIMARY KEY ("id")')
            ->and(($this->primaryKeyColumns)('settle_pivots'))->toBe(['id'])
            ->and(($this->diffAgainst)($this->keyedPivots)->isEmpty())->toBeTrue()
            ->and(array_column(
                $this->connection->query('SELECT id, user_id FROM settle_pivots ORDER BY user_id'),
                'id',
            ))->toEqual([1, 2]);
    });

    it(
        'adds a uuid primary key column with a gen_random_uuid() default to a table with rows and the diff is then '
        . 'empty',
        function (): void {
            $this->connection->execute('CREATE TABLE settle_tokens (owner_id UUID NOT NULL)');
            $this->connection->execute(
                'INSERT INTO settle_tokens (owner_id) VALUES (gen_random_uuid()), (gen_random_uuid())',
            );
            $keyedTokens = pgsqlSettleSchema(new #[Table('settle_tokens')] class () extends Entity
            {
                #[Column(type: 'uuid', primaryKey: true, default: 'gen_random_uuid()')]
                public string $id;

                #[Column(type: 'uuid')]
                public string $ownerId;
            });

            ($this->run)($this->generator->generateUp(($this->diffAgainst)($keyedTokens)));
            $ids = array_column($this->connection->query('SELECT id FROM settle_tokens'), 'id');

            expect(($this->diffAgainst)($keyedTokens)->isEmpty())->toBeTrue()
                ->and(($this->primaryKeyColumns)('settle_tokens'))->toBe(['id'])
                ->and(array_unique($ids))->toHaveCount(2);
        },
    );

    it(
        'adds a non-auto-increment primary key column without a default to an empty table and the diff is then empty',
        function (): void {
            $this->connection->execute('CREATE TABLE settle_tokens (owner_id UUID NOT NULL)');
            $keyedTokens = pgsqlSettleSchema(new #[Table('settle_tokens')] class () extends Entity
            {
                #[Column(type: 'uuid', primaryKey: true)]
                public string $id;

                #[Column(type: 'uuid')]
                public string $ownerId;
            });

            ($this->run)($this->generator->generateUp(($this->diffAgainst)($keyedTokens)));

            expect(($this->diffAgainst)($keyedTokens)->isEmpty())->toBeTrue()
                ->and(($this->primaryKeyColumns)('settle_tokens'))->toBe(['id']);
        },
    );

    it(
        'fails loudly adding a primary key column without a default to a table with rows and leaves the table '
        . 'unchanged',
        function (): void {
            $originalTable = $this->introspector->getTable('settle_pivots');
            $codedPivots = pgsqlSettleSchema(new #[Table('settle_pivots')] class () extends Entity
            {
                #[Column(length: 20, primaryKey: true)]
                public string $code;

                #[Column]
                public int $userId;

                #[Column]
                public int $roleId;
            });
            $statements = $this->generator->generateUp(($this->diffAgainst)($codedPivots));

            // The existing rows hold NULL in the new column, which a primary key refuses
            expect(fn () => ($this->run)($statements))->toThrow(QueryException::class)
                ->and($this->introspector->getTable('settle_pivots'))->toEqual($originalTable);
        },
    );

    it('refuses to add a primary key column to a table that already has a primary key', function (): void {
        ($this->create)($this->plainUsers);
        $compositeUsers = pgsqlSettleSchema(new #[Table('settle_users')] class () extends Entity
        {
            #[Column(primaryKey: true, autoIncrement: true)]
            public int $id;

            #[Column(length: 20, primaryKey: true)]
            public string $tenant;

            #[Column(length: 191)]
            public string $email;
        });

        expect(fn () => $this->generator->generateUp(($this->diffAgainst)($compositeUsers)))->toThrow(
            MigrationException::class,
            "Cannot add primary key column 'tenant' to table 'settle_users', which already has a primary key on 'id'",
        );
    });

    it('drops the added primary key column in down and the table matches the original', function (): void {
        $originalTable = $this->introspector->getTable('settle_pivots');
        $addition = ($this->diffAgainst)($this->keyedPivots);
        ($this->run)($this->generator->generateUp($addition));
        ($this->run)($this->generator->generateDown($addition));

        expect($this->introspector->getTable('settle_pivots'))->toEqual($originalTable)
            ->and(($this->primaryKeyColumns)('settle_pivots'))->toBe([])
            ->and($this->connection->query('SELECT user_id, role_id FROM settle_pivots ORDER BY user_id'))
            ->toEqual([['user_id' => 1, 'role_id' => 10], ['user_id' => 2, 'role_id' => 20]]);
    });
});

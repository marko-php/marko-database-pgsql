<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Sql;

use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Diff\TableDiff;
use Marko\Database\Exceptions\MigrationException;
use Marko\Database\Schema\Column;
use Marko\Database\Schema\ForeignKey;
use Marko\Database\Schema\Index;
use Marko\Database\Schema\IndexType;
use Marko\Database\Schema\Table;

/**
 * PostgreSQL-specific SQL generator for schema migrations.
 *
 * Generates DDL statements using PostgreSQL syntax including:
 * - SERIAL/BIGSERIAL for auto-increment
 * - Double quotes for identifier quoting
 * - PostgreSQL-specific data types (JSONB, BYTEA, etc.)
 */
class PgSqlGenerator implements SqlGeneratorInterface
{
    /**
     * Type mapping from abstract Column types to PostgreSQL data types.
     *
     * @var array<string, string>
     */
    private const array TYPE_MAP = [
        'integer' => 'INTEGER',
        'int' => 'INTEGER',
        'bigint' => 'BIGINT',
        'smallint' => 'SMALLINT',
        'tinyint' => 'SMALLINT',
        'string' => 'VARCHAR',
        'text' => 'TEXT',
        'boolean' => 'BOOLEAN',
        'bool' => 'BOOLEAN',
        'datetime' => 'TIMESTAMP',
        'timestamp' => 'TIMESTAMP',
        'date' => 'DATE',
        'time' => 'TIME',
        'decimal' => 'DECIMAL(10,2)',
        'float' => 'REAL',
        'double' => 'DOUBLE PRECISION',
        'json' => 'JSONB',
        'uuid' => 'UUID',
        'binary' => 'BYTEA',
        'blob' => 'BYTEA',
        'enum' => 'VARCHAR',
    ];

    /**
     * Default values that are SQL expressions rather than string literals (matched case-insensitively).
     *
     * @var list<string>
     */
    private const array SQL_EXPRESSIONS = [
        'CURRENT_TIMESTAMP',
        'CURRENT_DATE',
        'CURRENT_TIME',
        'NOW()',
    ];

    public function generateUp(
        SchemaDiff $diff,
    ): array {
        $statements = [];

        // Create new tables (with their indexes and foreign keys)
        foreach ($diff->tablesToCreate as $table) {
            $statements[] = $this->generateCreateTable($table);

            foreach ($table->indexes as $index) {
                $statements[] = $this->generateAddIndex($table->name, $index);
            }

            foreach ($table->foreignKeys as $foreignKey) {
                $statements[] = $this->generateAddForeignKey($table->name, $foreignKey);
            }
        }

        // Drop tables
        foreach ($diff->tablesToDrop as $table) {
            $statements[] = $this->generateDropTable($table->name);
        }

        // Alter existing tables
        foreach ($diff->tablesToAlter as $tableDiff) {
            $statements = [...$statements, ...$this->generateTableAlterations($tableDiff)];
        }

        return $statements;
    }

    public function generateDown(
        SchemaDiff $diff,
    ): array {
        $statements = [];

        // Reverse table creations (drop them)
        foreach ($diff->tablesToCreate as $table) {
            $statements[] = $this->generateDropTable($table->name);
        }

        // Reverse table drops (recreate them)
        foreach ($diff->tablesToDrop as $table) {
            $statements[] = $this->generateCreateTable($table);
        }

        // Reverse table alterations
        foreach ($diff->tablesToAlter as $tableDiff) {
            $statements = [...$statements, ...$this->generateReverseTableAlterations($tableDiff)];
        }

        return $statements;
    }

    public function generateCreateTable(
        Table $table,
    ): string {
        $columns = [];

        foreach ($table->columns as $column) {
            $columns[] = $this->generateColumnDefinition($column);
        }

        $columnsSql = implode(",\n    ", $columns);

        return "CREATE TABLE \"$table->name\" (\n    $columnsSql\n)";
    }

    public function generateDropTable(
        string $tableName,
    ): string {
        return "DROP TABLE \"$tableName\"";
    }

    public function generateAddColumn(
        string $table,
        Column $column,
    ): string {
        $definition = $this->generateColumnDefinition($column, forAlter: true);

        return "ALTER TABLE \"$table\" ADD COLUMN $definition";
    }

    public function generateDropColumn(
        string $table,
        string $columnName,
    ): string {
        return "ALTER TABLE \"$table\" DROP COLUMN \"$columnName\"";
    }

    /**
     * @throws MigrationException When nothing PostgreSQL can alter in place differs, or the primary key or
     *                            auto-increment changes
     */
    public function generateModifyColumn(
        string $table,
        Column $column,
        Column $oldColumn,
    ): string {
        return $this->generateModifyColumnIfChanged($table, $column, $oldColumn)
            ?? throw MigrationException::nothingToModify($table, $column->name, 'PostgreSQL');
    }

    public function generateAddIndex(
        string $table,
        Index $index,
    ): string {
        $unique = $index->type === IndexType::Unique ? 'UNIQUE ' : '';
        $columns = $this->quoteIdentifiers($index->columns);
        $columnsSql = implode(', ', $columns);

        $whereSql = $index->where !== null ? " WHERE $index->where" : '';

        return "CREATE {$unique}INDEX \"$index->name\" ON \"$table\" ($columnsSql)$whereSql";
    }

    public function generateDropIndex(
        string $table,
        string $indexName,
    ): string {
        // PostgreSQL indexes are not table-scoped, so we don't need the table name
        return "DROP INDEX \"$indexName\"";
    }

    public function generateAddForeignKey(
        string $table,
        ForeignKey $foreignKey,
    ): string {
        $columns = $this->quoteIdentifiers($foreignKey->columns);
        $columnsSql = implode(', ', $columns);

        $referencedColumns = $this->quoteIdentifiers($foreignKey->referencedColumns);
        $referencedColumnsSql = implode(', ', $referencedColumns);

        $sql = "ALTER TABLE \"$table\" ADD CONSTRAINT \"$foreignKey->name\" ";
        $sql .= "FOREIGN KEY ($columnsSql) ";
        $sql .= "REFERENCES \"$foreignKey->referencedTable\" ($referencedColumnsSql)";

        if ($foreignKey->onDelete !== null) {
            $sql .= " ON DELETE $foreignKey->onDelete";
        }

        if ($foreignKey->onUpdate !== null) {
            $sql .= " ON UPDATE $foreignKey->onUpdate";
        }

        return $sql;
    }

    public function generateDropForeignKey(
        string $table,
        string $keyName,
    ): string {
        return "ALTER TABLE \"$table\" DROP CONSTRAINT \"$keyName\"";
    }

    /**
     * Generate column definition SQL.
     *
     * @param Column $column The column to generate SQL for
     * @param bool $forAlter Whether this is for an ALTER TABLE statement
     */
    private function generateColumnDefinition(
        Column $column,
        bool $forAlter = false,
    ): string {
        $parts = ["\"$column->name\""];

        // Handle auto-increment with SERIAL types
        if ($column->autoIncrement) {
            $parts[] = $this->getSerialType($column->type);
        } else {
            $parts[] = $this->mapType($column);
        }

        // NOT NULL constraint (not needed for PRIMARY KEY or nullable columns)
        if (!$column->nullable && !$column->primaryKey && !$column->autoIncrement) {
            $parts[] = 'NOT NULL';
        }

        // DEFAULT value
        if ($column->default !== null) {
            $parts[] = 'DEFAULT ' . $this->formatDefaultValue($column->default);
        }

        // UNIQUE constraint
        if ($column->unique && !$column->primaryKey) {
            $parts[] = 'UNIQUE';
        }

        // PRIMARY KEY (only in CREATE TABLE context, not ALTER)
        if ($column->primaryKey && !$forAlter) {
            $parts[] = 'PRIMARY KEY';
        }

        return implode(' ', $parts);
    }

    /**
     * Map abstract column type to PostgreSQL data type.
     */
    private function mapType(
        Column $column,
    ): string {
        $baseType = self::TYPE_MAP[$column->type] ?? strtoupper($column->type);

        // Add length for VARCHAR - default to 255 if not specified
        if ($baseType === 'VARCHAR') {
            $length = $column->length ?? 255;

            return "VARCHAR($length)";
        }

        return $baseType;
    }

    /**
     * Get the SERIAL type for auto-increment columns.
     */
    private function getSerialType(
        string $type,
    ): string {
        return match ($type) {
            'bigint' => 'BIGSERIAL',
            'smallint' => 'SMALLSERIAL',
            default => 'SERIAL',
        };
    }

    /**
     * Format a default value for SQL.
     */
    private function formatDefaultValue(
        mixed $value,
    ): string {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            // SQL expressions (also what introspection reads back) are emitted as-is
            if (in_array(strtoupper($value), self::SQL_EXPRESSIONS, true)) {
                return $value;
            }

            // Escape single quotes
            $escaped = str_replace("'", "''", $value);

            return "'$escaped'";
        }

        return 'NULL';
    }

    /**
     * Quote multiple identifiers.
     *
     * @param array<string> $identifiers
     * @return array<string>
     */
    private function quoteIdentifiers(
        array $identifiers,
    ): array {
        return array_map(
            static fn (string $identifier): string => "\"$identifier\"",
            $identifiers,
        );
    }

    /**
     * Generate ALTER TABLE statements for a table diff.
     *
     * @return array<string>
     */
    private function generateTableAlterations(
        TableDiff $diff,
    ): array {
        $statements = [];

        // Add columns
        foreach ($diff->columnsToAdd as $column) {
            $statements[] = $this->generateAddColumn($diff->tableName, $column);
        }

        // Drop columns
        foreach ($diff->columnsToDrop as $column) {
            $statements[] = $this->generateDropColumn($diff->tableName, $column->name);
        }

        // Modify columns
        $statements = [...$statements, ...$this->generateColumnModifications($diff, reverse: false)];

        // Add indexes
        foreach ($diff->indexesToAdd as $index) {
            $statements[] = $this->generateAddIndex($diff->tableName, $index);
        }

        // Drop indexes
        foreach ($diff->indexesToDrop as $index) {
            $statements[] = $this->generateDropIndex($diff->tableName, $index->name);
        }

        // Add foreign keys
        foreach ($diff->foreignKeysToAdd as $foreignKey) {
            $statements[] = $this->generateAddForeignKey($diff->tableName, $foreignKey);
        }

        // Drop foreign keys
        foreach ($diff->foreignKeysToDrop as $foreignKey) {
            $statements[] = $this->generateDropForeignKey($diff->tableName, $foreignKey->name);
        }

        return $statements;
    }

    /**
     * Generate reverse ALTER TABLE statements for a table diff.
     *
     * @return array<string>
     */
    private function generateReverseTableAlterations(
        TableDiff $diff,
    ): array {
        $statements = [];

        // Reverse: drop added foreign keys
        foreach ($diff->foreignKeysToAdd as $foreignKey) {
            $statements[] = $this->generateDropForeignKey($diff->tableName, $foreignKey->name);
        }

        // Reverse: drop added indexes
        foreach ($diff->indexesToAdd as $index) {
            $statements[] = $this->generateDropIndex($diff->tableName, $index->name);
        }

        // Reverse: drop added columns
        foreach ($diff->columnsToAdd as $column) {
            $statements[] = $this->generateDropColumn($diff->tableName, $column->name);
        }

        // Reverse: add dropped columns
        foreach ($diff->columnsToDrop as $column) {
            $statements[] = $this->generateAddColumn($diff->tableName, $column);
        }

        // Reverse: restore modified columns before the indexes and foreign keys that rely on them
        $statements = [...$statements, ...$this->generateColumnModifications($diff, reverse: true)];

        // Reverse: add dropped indexes
        foreach ($diff->indexesToDrop as $index) {
            $statements[] = $this->generateAddIndex($diff->tableName, $index);
        }

        // Reverse: add dropped foreign keys
        foreach ($diff->foreignKeysToDrop as $foreignKey) {
            $statements[] = $this->generateAddForeignKey($diff->tableName, $foreignKey);
        }

        return $statements;
    }

    /**
     * ALTER TABLE statements that apply (or, in reverse, undo) every modified column of a table diff.
     *
     * @return list<string>
     * @throws MigrationException When the diff holds no previous definition for a modified column, or
     *                            the primary key or auto-increment changes
     */
    private function generateColumnModifications(
        TableDiff $diff,
        bool $reverse,
    ): array {
        $statements = [];

        foreach ($diff->columnsToModify as $columnName => $column) {
            $previous = $diff->previousColumn($columnName);
            // The diff's tolerances (an undeclared length or default keeps the database's) live in Column
            $target = $column->resolveAgainst($previous);

            $statement = $reverse
                ? $this->generateModifyColumnIfChanged($diff->tableName, $previous, $target)
                : $this->generateModifyColumnIfChanged($diff->tableName, $target, $previous);

            if ($statement !== null) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * The ALTER TABLE statement that turns $oldColumn into $column, or null when the only difference
     * is one the index diff applies (uniqueness) or that needs no DDL (such as an unspecified length).
     *
     * @throws MigrationException When the primary key or auto-increment changes
     */
    private function generateModifyColumnIfChanged(
        string $table,
        Column $column,
        Column $oldColumn,
    ): ?string {
        if ($column->primaryKey !== $oldColumn->primaryKey) {
            throw MigrationException::columnChangeNotSupported($table, $column->name, 'PostgreSQL', 'primary key');
        }

        if ($column->autoIncrement !== $oldColumn->autoIncrement) {
            throw MigrationException::columnChangeNotSupported($table, $column->name, 'PostgreSQL', 'auto-increment');
        }

        $alterations = [];

        $newType = $this->mapType($column);

        if ($newType !== $this->mapType($oldColumn)) {
            $alterations[] = "ALTER COLUMN \"$column->name\" TYPE $newType";
        }

        // A primary key column is always NOT NULL, whatever the PHP property allows
        if ($column->nullable !== $oldColumn->nullable && !$column->primaryKey) {
            $nullability = $column->nullable ? 'DROP NOT NULL' : 'SET NOT NULL';
            $alterations[] = "ALTER COLUMN \"$column->name\" $nullability";
        }

        // An auto-increment column's default is its sequence, which is never altered here
        if ($column->default !== $oldColumn->default && !$column->autoIncrement) {
            $alterations[] = $column->default === null
                ? "ALTER COLUMN \"$column->name\" DROP DEFAULT"
                : "ALTER COLUMN \"$column->name\" SET DEFAULT " . $this->formatDefaultValue($column->default);
        }

        if ($alterations === []) {
            return null;
        }

        return "ALTER TABLE \"$table\" " . implode(', ', $alterations);
    }
}

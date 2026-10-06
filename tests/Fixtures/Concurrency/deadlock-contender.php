<?php

/**
 * The second session of a deadlock, run as its own process by
 * ConcurrencyErrorsTest (one PHP process can only wait on one connection at a
 * time). Connects with the MARKO_TEST_PGSQL_* variables, runs the first
 * statement (argv[1]) in a transaction, prints "locked", waits for a line on
 * STDIN, runs the second statement (argv[2]) and commits. Prints "committed",
 * or the class of the exception that stopped it.
 */

declare(strict_types=1);

use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$connection = new PgSqlConnection(SharedConnectionContainer::config(
    host: (string) getenv('MARKO_TEST_PGSQL_HOST'),
    port: (int) (getenv('MARKO_TEST_PGSQL_PORT') ?: 5432),
    database: getenv('MARKO_TEST_PGSQL_DATABASE') ?: 'marko_test',
    username: getenv('MARKO_TEST_PGSQL_USERNAME') ?: 'postgres',
    password: getenv('MARKO_TEST_PGSQL_PASSWORD') ?: '',
));

$connection->beginTransaction();
$connection->execute($argv[1]);

fwrite(STDOUT, "locked\n");
fflush(STDOUT);
fgets(STDIN);

try {
    $connection->execute($argv[2]);
    $connection->commit();
    fwrite(STDOUT, "committed\n");
} catch (Throwable $e) {
    if ($connection->inTransaction()) {
        $connection->rollback();
    }

    fwrite(STDOUT, $e::class . "\n");
}

$connection->disconnect();

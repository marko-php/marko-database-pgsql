<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Integration;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\Config\DatabaseTimezoneConfig;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;
use PDO;

/*
 * The session time zone against a real PostgreSQL server whose default zone
 * for the test database is not UTC. Set MARKO_TEST_PGSQL_HOST (and optionally
 * MARKO_TEST_PGSQL_PORT, _DATABASE, _USERNAME, _PASSWORD) to enable; the tests
 * skip otherwise.
 *
 * Each test sets the test database's default TimeZone to America/New_York
 * (ALTER DATABASE ... SET timezone, which needs the database owner) and resets
 * it afterwards. The tests create and drop the session_timezone_items table.
 *
 * Settings come from tests/Fixtures/IntegrationDatabase. With
 * MARKO_INTEGRATION_REQUIRED set (CI), a missing host fails instead of
 * skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

/**
 * The integration database config with database.timezone set to the given zone.
 */
function pgsqlConfigInTimezone(
    DatabaseConfig $config,
    string $timezone,
): DatabaseConfig {
    return DatabaseConfig::fromArray([
        'driver' => $config->driver,
        'host' => $config->host,
        'port' => $config->port,
        'database' => $config->database,
        'username' => $config->username,
        'password' => $config->password,
        'timezone' => $timezone,
    ]);
}

/**
 * Seconds between a stored datetime, read in the given zone, and now.
 */
function pgsqlSecondsFromNow(
    string $stored,
    string $timezone,
): int {
    $instant = DatabaseTimezoneConfig::fromName($timezone)->parse($stored);

    return abs($instant->getTimestamp() - time());
}

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->config = $config;
    $this->database = '"' . str_replace('"', '""', $config->database) . '"';
    $this->admin = new PgSqlConnection($config);
    $this->admin->execute("ALTER DATABASE $this->database SET timezone = 'America/New_York'");
    $this->admin->execute('DROP TABLE IF EXISTS session_timezone_items');
    $this->admin->execute(
        'CREATE TABLE session_timezone_items ('
        . 'id SERIAL PRIMARY KEY, '
        . 'created_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP)',
    );
});

afterEach(function (): void {
    if (isset($this->admin)) {
        $this->admin->execute('DROP TABLE IF EXISTS session_timezone_items');
        $this->admin->execute("ALTER DATABASE $this->database RESET timezone");
        $this->admin->disconnect();
    }
});

describe('PostgreSQL session time zone', function (): void {
    it('runs the session in database.timezone although the database default zone is not UTC', function (): void {
        $config = $this->config;
        $plain = new PDO(
            "pgsql:host=$config->host;port=$config->port;dbname=$config->database",
            $config->username,
            $config->password,
        );
        $connection = new PgSqlConnection($config);

        expect($plain->query('SHOW TimeZone')->fetchColumn())->toBe('America/New_York')
            ->and($connection->query('SHOW TimeZone')[0]['TimeZone'])->toBe('UTC');

        $connection->disconnect();
    })->issue(304);

    it('fills DEFAULT CURRENT_TIMESTAMP with the current time in database.timezone', function (): void {
        $connection = new PgSqlConnection($this->config);
        $connection->execute('INSERT INTO session_timezone_items DEFAULT VALUES');
        $row = $connection->query(
            'SELECT created_at, LOCALTIMESTAMP(0)::text AS local_now FROM session_timezone_items',
        )[0];

        expect(pgsqlSecondsFromNow($row['created_at'], 'UTC'))->toBeLessThan(60)
            ->and(pgsqlSecondsFromNow($row['local_now'], 'UTC'))->toBeLessThan(60);

        $connection->disconnect();
    })->issue(304);

    it('pins a named database timezone on the session', function (): void {
        $connection = new PgSqlConnection(pgsqlConfigInTimezone($this->config, 'Asia/Tokyo'));
        $connection->execute('INSERT INTO session_timezone_items DEFAULT VALUES');
        $zone = $connection->query('SHOW TimeZone')[0]['TimeZone'];
        $row = $connection->query('SELECT created_at FROM session_timezone_items')[0];

        expect($zone)->toBe('Asia/Tokyo')
            ->and(pgsqlSecondsFromNow($row['created_at'], 'Asia/Tokyo'))->toBeLessThan(60);

        $connection->disconnect();
    })->issue(304);

    it('pins a fixed offset with the sign PHP uses', function (): void {
        $connection = new PgSqlConnection(pgsqlConfigInTimezone($this->config, '+05:30'));
        $row = $connection->query(
            'SELECT EXTRACT(TIMEZONE FROM now())::int AS offset_seconds, LOCALTIMESTAMP(0)::text AS local_now',
        )[0];

        expect((int) $row['offset_seconds'])->toBe(19800)
            ->and(pgsqlSecondsFromNow($row['local_now'], '+05:30'))->toBeLessThan(60);

        $connection->disconnect();
    })->issue(304);

    it('pins the session zone again after a reconnect', function (): void {
        $connection = new PgSqlConnection($this->config);
        $connection->connect();
        $connection->disconnect();

        expect($connection->query('SHOW TimeZone')[0]['TimeZone'])->toBe('UTC');

        $connection->disconnect();
    })->issue(304);
});

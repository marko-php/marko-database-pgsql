<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures;

use Marko\Database\Config\DatabaseConfig;
use Marko\Database\PgSql\Tests\Fixtures\SharedConnection\SharedConnectionContainer;
use RuntimeException;

/**
 * Connection settings for the tests that run against a real PostgreSQL
 * server (the integration-services group), read from the MARKO_TEST_PGSQL_*
 * variables.
 *
 * Without MARKO_TEST_PGSQL_HOST the tests skip. With MARKO_INTEGRATION_REQUIRED
 * set (the CI Integration job) a missing host is a failure instead, so the
 * job can never pass by skipping these tests.
 */
class IntegrationDatabase
{
    public const string SKIP_REASON = 'Set MARKO_TEST_PGSQL_HOST (and _PORT, _DATABASE, _USERNAME, _PASSWORD) to run '
        . 'against a real PostgreSQL server. `docker compose -f tests/Integration/compose.yml up -d` starts one; '
        . 'see .claude/testing.md.';

    /**
     * The configured server, or null when MARKO_TEST_PGSQL_HOST is unset.
     *
     * @param array<string, string>|null $env The variables to read; the real process environment when null
     * @throws RuntimeException When MARKO_INTEGRATION_REQUIRED is set and MARKO_TEST_PGSQL_HOST is unset
     */
    public static function config(
        ?array $env = null,
    ): ?DatabaseConfig {
        $env ??= getenv();
        $host = $env['MARKO_TEST_PGSQL_HOST'] ?? '';

        if ($host === '') {
            if (in_array(strtolower($env['MARKO_INTEGRATION_REQUIRED'] ?? ''), ['1', 'true', 'yes'], true)) {
                throw new RuntimeException(
                    'MARKO_INTEGRATION_REQUIRED is set but MARKO_TEST_PGSQL_HOST is not, so the PostgreSQL driver '
                    . 'integration tests cannot run. ' . self::SKIP_REASON,
                );
            }

            return null;
        }

        return SharedConnectionContainer::config(
            host: $host,
            port: (int) self::value($env, 'MARKO_TEST_PGSQL_PORT', '5432'),
            database: self::value($env, 'MARKO_TEST_PGSQL_DATABASE', 'marko_test'),
            username: self::value($env, 'MARKO_TEST_PGSQL_USERNAME', 'postgres'),
            password: $env['MARKO_TEST_PGSQL_PASSWORD'] ?? '',
        );
    }

    /**
     * @param array<string, string> $env
     */
    private static function value(
        array $env,
        string $name,
        string $default,
    ): string {
        return ($env[$name] ?? '') !== '' ? $env[$name] : $default;
    }
}

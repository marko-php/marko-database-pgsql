<?php

declare(strict_types=1);

use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;

it('reads the pgsql driver test connection from MARKO_TEST_PGSQL_* variables', function (): void {
    $config = IntegrationDatabase::config([
        'MARKO_TEST_PGSQL_HOST' => 'db.test',
        'MARKO_TEST_PGSQL_PORT' => '55432',
        'MARKO_TEST_PGSQL_DATABASE' => 'drivers',
        'MARKO_TEST_PGSQL_USERNAME' => 'marko',
        'MARKO_TEST_PGSQL_PASSWORD' => 'secret',
    ]);

    expect($config?->host)->toBe('db.test')
        ->and($config?->port)->toBe(55432)
        ->and($config?->database)->toBe('drivers')
        ->and($config?->username)->toBe('marko')
        ->and($config?->password)->toBe('secret');
});

it('falls back to the documented defaults for unset pgsql variables', function (): void {
    $config = IntegrationDatabase::config(['MARKO_TEST_PGSQL_HOST' => 'db.test']);

    expect($config?->port)->toBe(5432)
        ->and($config?->database)->toBe('marko_test')
        ->and($config?->username)->toBe('postgres')
        ->and($config?->password)->toBe('');
});

it('returns no pgsql config when MARKO_TEST_PGSQL_HOST is unset', function (): void {
    expect(IntegrationDatabase::config([]))->toBeNull()
        ->and(IntegrationDatabase::config(['MARKO_TEST_PGSQL_HOST' => '']))->toBeNull();
});

it(
    'throws instead of skipping when MARKO_INTEGRATION_REQUIRED is set and MARKO_TEST_PGSQL_HOST is unset',
    function (): void {
        expect(fn () => IntegrationDatabase::config(['MARKO_INTEGRATION_REQUIRED' => '1']))
            ->toThrow(RuntimeException::class, 'MARKO_INTEGRATION_REQUIRED is set but MARKO_TEST_PGSQL_HOST is not');
    },
);

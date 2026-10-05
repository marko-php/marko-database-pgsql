<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures\SharedConnection;

/**
 * @extends InspectableRepository<Account>
 */
class AccountRepository extends InspectableRepository
{
    protected const string ENTITY_CLASS = Account::class;
}

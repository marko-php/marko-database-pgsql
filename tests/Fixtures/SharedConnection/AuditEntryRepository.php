<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures\SharedConnection;

/**
 * @extends InspectableRepository<AuditEntry>
 */
class AuditEntryRepository extends InspectableRepository
{
    protected const string ENTITY_CLASS = AuditEntry::class;
}

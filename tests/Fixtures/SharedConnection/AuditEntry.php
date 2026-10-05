<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures\SharedConnection;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Entity\Entity;

#[Table('shared_audit_entries')]
class AuditEntry extends Entity
{
    /** @noinspection PhpUnused - Entity property accessed via reflection */
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;

    /** @noinspection PhpUnused - Entity property accessed via reflection */
    #[Column(length: 255)]
    public string $message;
}

<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures\Constraint;

use Marko\Database\Repository\Repository;

/**
 * @extends Repository<ConstraintPost>
 */
class ConstraintPostRepository extends Repository
{
    protected const string ENTITY_CLASS = ConstraintPost::class;
}

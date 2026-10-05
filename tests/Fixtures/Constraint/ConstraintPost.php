<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures\Constraint;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Entity\Entity;

#[Table('constraint_posts')]
class ConstraintPost extends Entity
{
    /** @noinspection PhpUnused - Entity property accessed via reflection */
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;

    /** @noinspection PhpUnused - Entity property accessed via reflection */
    #[Column('user_id')]
    public int $userId;
}

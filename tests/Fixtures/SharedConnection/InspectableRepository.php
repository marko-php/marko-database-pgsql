<?php

declare(strict_types=1);

namespace Marko\Database\PgSql\Tests\Fixtures\SharedConnection;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Entity\EntityHydrator;
use Marko\Database\Query\QueryBuilderFactoryInterface;
use Marko\Database\Repository\Repository;

/**
 * Exposes the injected collaborators so tests can assert they are shared.
 */
abstract class InspectableRepository extends Repository
{
    public function exposedConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function exposedHydrator(): EntityHydrator
    {
        return $this->hydrator;
    }

    public function exposedQueryBuilderFactory(): ?QueryBuilderFactoryInterface
    {
        return $this->queryBuilderFactory;
    }
}

<?php

declare(strict_types=1);

use Marko\Database\Connection\ConnectionFactoryInterface;
use Marko\Core\Container\ContainerInterface;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\TransactionInterface;
use Marko\Database\Exceptions\TransactionException;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Introspection\IntrospectorInterface;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Connection\PgSqlConnectionFactory;
use Marko\Database\PgSql\Introspection\PgSqlIntrospector;
use Marko\Database\PgSql\Query\PgSqlQueryBuilder;
use Marko\Database\PgSql\Query\PgSqlQueryBuilderFactory;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\Query\QueryBuilderFactoryInterface;
use Marko\Database\Query\QueryBuilderInterface;

// Marko-specific configuration for this module.
// Name and version come from composer.json.

return [
    'bindings' => [
        ConnectionInterface::class => PgSqlConnection::class,
        ConnectionFactoryInterface::class => PgSqlConnectionFactory::class,
        SqlGeneratorInterface::class => PgSqlGenerator::class,
        IntrospectorInterface::class => PgSqlIntrospector::class,
        QueryBuilderInterface::class => PgSqlQueryBuilder::class,
        QueryBuilderFactoryInterface::class => PgSqlQueryBuilderFactory::class,
        // Transactions run on the shared connection, so they cover every
        // repository, query builder and service that injects ConnectionInterface.
        TransactionInterface::class => static function (ContainerInterface $container): TransactionInterface {
            $connection = $container->get(ConnectionInterface::class);

            if (!$connection instanceof TransactionInterface) {
                throw TransactionException::connectionDoesNotSupportTransactions($connection::class);
            }

            return $connection;
        },
    ],
    // One connection (one PDO handle) per container, shared by every consumer.
    'singletons' => [
        ConnectionInterface::class,
    ],
];

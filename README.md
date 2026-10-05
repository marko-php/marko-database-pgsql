# marko/database-pgsql

PostgreSQL driver for the Marko framework database layer.

## Installation

```bash
composer require marko/database-pgsql
```

This automatically installs `marko/database` (the interface package) as a dependency.

## Configuration

Create `config/database.php` with a flat array of connection details:

```php title="config/database.php"
<?php

declare(strict_types=1);

return [
    'driver' => 'pgsql',
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => (int) ($_ENV['DB_PORT'] ?? 5432),
    'database' => $_ENV['DB_DATABASE'] ?? 'marko',
    'username' => $_ENV['DB_USERNAME'] ?? 'postgres',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
];
```

`driver`, `host`, `port`, `database`, `username`, and `password` are all required. Set the corresponding values in your `.env` file:

```dotenv
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=marko
DB_USERNAME=postgres
DB_PASSWORD=secret
```

For multiple connections (for example, read replicas), use [`marko/database-readwrite`](https://marko.build/docs/packages/database-readwrite/), which adds the `connections` layout.

## Driver Notes

This driver supports **PostgreSQL** 14+.

## Quick Example

```php
use Marko\Database\Connection\ConnectionInterface;

class MyService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function doSomething(): void
    {
        $result = $this->connection->query('SELECT * FROM users');
    }
}
```

## Documentation

Full usage, configuration, and API reference: [marko/database-pgsql](https://marko.build/docs/packages/database-pgsql/)

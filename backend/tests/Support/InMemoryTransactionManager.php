<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\TransactionManager;

final class InMemoryTransactionManager implements TransactionManager
{
    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
}

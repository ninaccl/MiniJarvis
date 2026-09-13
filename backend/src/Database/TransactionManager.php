<?php

declare(strict_types=1);

namespace App\Database;

interface TransactionManager
{
    public function transaction(callable $callback): mixed;
}

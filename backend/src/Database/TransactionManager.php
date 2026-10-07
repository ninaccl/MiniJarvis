<?php

namespace App\Database;

interface TransactionManager
{
    public function transaction(callable $callback);
}

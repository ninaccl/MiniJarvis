<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\TransactionManager;
use Throwable;

final class SnapshotTransactionManager implements TransactionManager
{
    public function __construct(private readonly object $state)
    {
    }

    public function transaction(callable $callback): mixed
    {
        $snapshot = unserialize(serialize(get_object_vars($this->state)));
        try {
            return $callback();
        } catch (Throwable $exception) {
            foreach (array_keys(get_object_vars($this->state)) as $property) {
                unset($this->state->{$property});
            }
            foreach ($snapshot as $property => $value) {
                $this->state->{$property} = $value;
            }
            throw $exception;
        }
    }
}

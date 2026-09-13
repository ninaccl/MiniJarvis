<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\TransactionManager;
use Throwable;

final class MultiSnapshotTransactionManager implements TransactionManager
{
    public int $calls = 0;

    /** @param list<object> $states */
    public function __construct(private readonly array $states)
    {
    }

    public function transaction(callable $callback): mixed
    {
        ++$this->calls;
        $snapshots = array_map(static fn (object $state): array => unserialize(serialize(get_object_vars($state))), $this->states);
        try {
            return $callback();
        } catch (Throwable $exception) {
            foreach ($this->states as $index => $state) {
                foreach (array_keys(get_object_vars($state)) as $property) unset($state->{$property});
                foreach ($snapshots[$index] as $property => $value) $state->{$property} = $value;
            }
            throw $exception;
        }
    }
}

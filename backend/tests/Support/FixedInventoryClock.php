<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inventory\InventoryClock;
use DateTimeImmutable;

final class FixedInventoryClock implements InventoryClock
{
    public function __construct(private readonly DateTimeImmutable $today)
    {
    }

    public function today(): DateTimeImmutable
    {
        return $this->today;
    }
}

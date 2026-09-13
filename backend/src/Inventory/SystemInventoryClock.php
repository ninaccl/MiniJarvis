<?php

declare(strict_types=1);

namespace App\Inventory;

use DateTimeImmutable;
use DateTimeZone;

final class SystemInventoryClock implements InventoryClock
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', $this->timezone);
    }
}

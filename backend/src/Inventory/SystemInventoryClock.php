<?php

namespace App\Inventory;

use DateTimeImmutable;
use DateTimeZone;
final class SystemInventoryClock implements InventoryClock
{
    private $timezone;
    public function __construct(DateTimeZone $timezone)
    {
        $this->timezone = $timezone;
    }
    public function today()
    {
        return new DateTimeImmutable('today', $this->timezone);
    }
}

<?php

namespace App\Inventory;

use DateTimeImmutable;
interface InventoryClock
{
    public function today();
}

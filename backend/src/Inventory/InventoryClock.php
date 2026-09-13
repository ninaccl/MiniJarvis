<?php

declare(strict_types=1);

namespace App\Inventory;

use DateTimeImmutable;

interface InventoryClock
{
    public function today(): DateTimeImmutable;
}

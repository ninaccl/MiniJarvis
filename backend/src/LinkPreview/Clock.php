<?php

declare(strict_types=1);

namespace App\LinkPreview;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}

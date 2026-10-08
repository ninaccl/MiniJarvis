<?php

namespace App\LinkPreview;

use DateTimeImmutable;
use DateTimeZone;
final class SystemClock implements Clock
{
    public function now()
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testCalendarTimezoneDefaultsToAsiaShanghai(): void
    {
        $config = new Config([]);

        self::assertSame('Asia/Shanghai', $config->calendarTimezone()->getName());
    }
}

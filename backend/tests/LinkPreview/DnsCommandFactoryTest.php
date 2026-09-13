<?php

declare(strict_types=1);

namespace Tests\LinkPreview;

use App\LinkPreview\DnsCommandFactory;
use PHPUnit\Framework\TestCase;

final class DnsCommandFactoryTest extends TestCase
{
    public function testCommandUsesConfiguredCliBinaryWithoutAShell(): void
    {
        $factory = new DnsCommandFactory('/opt/jarvis/php-cli');

        $command = $factory->forHost('bilibili.com');

        self::assertSame('/opt/jarvis/php-cli', $command[0]);
        self::assertSame('-r', $command[1]);
        self::assertSame('bilibili.com', $command[3]);
        self::assertCount(4, $command);
    }
}

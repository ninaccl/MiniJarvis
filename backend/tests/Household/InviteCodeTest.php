<?php

declare(strict_types=1);

namespace Tests\Household;

use App\Household\InviteCode;
use PHPUnit\Framework\TestCase;

final class InviteCodeTest extends TestCase
{
    public function testGeneratedCodesAreEightUnambiguousUppercaseCharacters(): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = InviteCode::generate();
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
            self::assertStringNotContainsString('0', $code);
            self::assertStringNotContainsString('O', $code);
            self::assertStringNotContainsString('1', $code);
            self::assertStringNotContainsString('I', $code);
        }
    }
}

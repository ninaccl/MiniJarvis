<?php

declare(strict_types=1);

namespace Tests\Recipe;

use App\Recipe\IngredientNormalizer;
use PHPUnit\Framework\TestCase;

final class IngredientNormalizerTest extends TestCase
{
    public function testItTrimsCollapsesUnicodeWhitespaceAndLowercasesAsciiOnly(): void
    {
        self::assertSame('tofu 豆 腐', IngredientNormalizer::normalize(" \tToFU\u{3000}豆\u{00A0}腐 \n"));
        self::assertSame('土豆', IngredientNormalizer::normalize('土豆'));
        self::assertSame('马铃薯', IngredientNormalizer::normalize('马铃薯'));
    }
}

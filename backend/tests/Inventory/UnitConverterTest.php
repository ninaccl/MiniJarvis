<?php

declare(strict_types=1);

namespace Tests\Inventory;

use App\Http\ApiException;
use App\Inventory\UnitConverter;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryRecipeRepository;

final class UnitConverterTest extends TestCase
{
    private UnitConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new UnitConverter(new InMemoryRecipeRepository());
    }

    public function testMassAndVolumeUseTheirSeededBaseFactors(): void
    {
        self::assertSame(['quantity' => '1000', 'unit_code' => 'g'], $this->converter->toBase('1', 'kg'));
        self::assertSame(['quantity' => '1', 'unit_code' => 'g'], $this->converter->toBase('1', 'g'));
        self::assertSame(['quantity' => '1000', 'unit_code' => 'ml'], $this->converter->toBase('1', 'l'));
        self::assertSame(['quantity' => '1', 'unit_code' => 'ml'], $this->converter->toBase('1', 'ml'));
    }

    public function testEveryDiscreteUnitKeepsItsExactCode(): void
    {
        foreach (['piece', 'pack', 'box', 'bunch', 'tbsp', 'tsp'] as $code) {
            self::assertSame(['quantity' => '2', 'unit_code' => $code], $this->converter->toBase('2', $code));
        }
    }

    public function testDifferentDiscreteCodesAndDifferentDimensionsAreIncompatible(): void
    {
        self::assertFalse($this->converter->compatible('piece', 'pack'));
        self::assertFalse($this->converter->compatible('g', 'ml'));
        self::assertTrue($this->converter->compatible('kg', 'g'));
        self::assertTrue($this->converter->compatible('l', 'ml'));
    }

    public function testUnknownUnitsAreRejected(): void
    {
        try {
            $this->converter->toBase('1', 'cup');
            self::fail('Expected unknown unit validation failure.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('VALIDATION_FAILED', $exception->errorCode());
        }
    }
}

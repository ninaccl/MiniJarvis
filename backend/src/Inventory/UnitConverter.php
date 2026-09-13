<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Http\ApiException;
use App\Recipe\RecipeRepository;

final class UnitConverter
{
    public function __construct(private readonly RecipeRepository $units)
    {
    }

    /** @return array{quantity:string,unit_code:string} */
    public function toBase(string $quantity, string $unitCode): array
    {
        $unit = $this->requireUnit($unitCode);
        $baseCode = match ($unit['dimension']) {
            'mass' => 'g',
            'volume' => 'ml',
            'discrete' => $unit['code'],
            default => throw new ApiException(422, 'VALIDATION_FAILED', 'Unit is invalid.', ['unit_code' => 'Unknown unit dimension.']),
        };
        return [
            'quantity' => Quantity::format((float) $quantity * (float) $unit['base_factor']),
            'unit_code' => $baseCode,
        ];
    }

    public function compatible(string $leftCode, string $rightCode): bool
    {
        $left = $this->requireUnit($leftCode);
        $right = $this->requireUnit($rightCode);
        if ($left['dimension'] !== $right['dimension']) return false;
        return $left['dimension'] !== 'discrete' || $left['code'] === $right['code'];
    }

    /** @return array{code:string,display_name:string,dimension:string,base_factor:string} */
    private function requireUnit(string $code): array
    {
        return $this->units->unit($code) ?? throw new ApiException(
            422,
            'VALIDATION_FAILED',
            'Unit is invalid.',
            ['unit_code' => 'Unknown unit.'],
        );
    }
}

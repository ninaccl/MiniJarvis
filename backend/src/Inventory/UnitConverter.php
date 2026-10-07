<?php

namespace App\Inventory;

use App\Http\ApiException;
use App\Recipe\RecipeRepository;
final class UnitConverter
{
    private $units;
    public function __construct(RecipeRepository $units)
    {
        $this->units = $units;
    }
    /** @return array{quantity:string,unit_code:string} */
    public function toBase($quantity, $unitCode)
    {
        $unit = $this->requireUnit($unitCode);
        switch ($unit['dimension']) {
            case 'mass':
                $baseCode = 'g';
                break;
            case 'volume':
                $baseCode = 'ml';
                break;
            case 'discrete':
                $baseCode = $unit['code'];
                break;
            default:
                throw new ApiException(422, 'VALIDATION_FAILED', 'Unit is invalid.', ['unit_code' => 'Unknown unit dimension.']);
        }
        return ['quantity' => Quantity::format((float) $quantity * (float) $unit['base_factor']), 'unit_code' => $baseCode];
    }
    public function compatible($leftCode, $rightCode)
    {
        $left = $this->requireUnit($leftCode);
        $right = $this->requireUnit($rightCode);
        if ($left['dimension'] !== $right['dimension']) {
            return false;
        }
        return $left['dimension'] !== 'discrete' || $left['code'] === $right['code'];
    }
    /** @return array{code:string,display_name:string,dimension:string,base_factor:string} */
    private function requireUnit($code)
    {
        $unit = $this->units->unit($code);
        if ($unit === null) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Unit is invalid.', ['unit_code' => 'Unknown unit.']);
        }
        return $unit;
    }
}

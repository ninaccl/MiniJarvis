<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Recipe\DecimalQuantity;
use App\Recipe\RecipeRepository;

final class RecipeMatcher
{
    public function __construct(
        private readonly InventoryRepository $inventory,
        private readonly RecipeRepository $recipes,
        private readonly UnitConverter $converter,
        private readonly InventoryClock $clock,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function matches(AuthContext $context, int $count = 2): array
    {
        $householdId = $this->guard->requireMembership($context);
        if ($count < 1 || $count > 10) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Match query is invalid.', ['count' => 'Must be between 1 and 10.']);
        }
        $remaining = $this->recipes->activeForMatching($householdId);
        $stock = $this->stock($this->inventory->availableBatches($householdId, $this->clock->today()->format('Y-m-d')));
        $selected = [];

        while (count($selected) < $count && $remaining !== []) {
            $scores = array_map(fn (array $recipe): array => $this->score($recipe, $stock), $remaining);
            usort($scores, [$this, 'compareScores']);
            $best = $scores[0];
            $selected[] = $best;
            $this->deplete($stock, $best['_requirements']);
            $pickedId = $best['recipe']['id'];
            $remaining = array_values(array_filter($remaining, static fn (array $recipe): bool => $recipe['id'] !== $pickedId));
        }

        return array_map(static function (array $match): array {
            unset($match['_requirements']);
            return $match;
        }, $selected);
    }

    /** @param list<array<string,mixed>> $batches @return array<int,array<string,string>> */
    private function stock(array $batches): array
    {
        $stock = [];
        foreach ($batches as $batch) {
            $ingredientId = $batch['ingredient_id'];
            $unitCode = $batch['base_unit_code'];
            $stock[$ingredientId][$unitCode] = Quantity::add($stock[$ingredientId][$unitCode] ?? '0', $batch['base_quantity']);
        }
        return $stock;
    }

    /** @param array<string,mixed> $recipe @param array<int,array<string,string>> $stock @return array<string,mixed> */
    private function score(array $recipe, array $stock): array
    {
        $matched = [];
        $missing = [];
        $requirements = [];
        $missingTotal = '0';

        foreach ($recipe['ingredients'] as $ingredient) {
            $ingredientId = $ingredient['ingredient_id'];
            if ($ingredient['quantity'] === null) {
                $present = false;
                foreach ($stock[$ingredientId] ?? [] as $available) {
                    if (Quantity::compare($available, '0') > 0) { $present = true; break; }
                }
                $entry = $this->ingredientResult($ingredient) + ['presence_only' => true];
                if ($present) $matched[] = $entry;
                else $missing[] = $entry + ['missing_base_quantity' => null, 'base_unit_code' => null];
                $requirements[] = ['ingredient_id' => $ingredientId, 'quantity' => null, 'unit_code' => null];
                continue;
            }

            $quantity = DecimalQuantity::forStorage($ingredient['quantity']);
            $base = $this->converter->toBase($quantity, $ingredient['unit_code']);
            $available = $stock[$ingredientId][$base['unit_code']] ?? '0';
            $shortfall = Quantity::compare($available, $base['quantity']) >= 0
                ? '0'
                : Quantity::subtract($base['quantity'], $available);
            $entry = $this->ingredientResult($ingredient) + [
                'required_base_quantity' => $base['quantity'],
                'base_unit_code' => $base['unit_code'],
            ];
            if ($shortfall === '0') {
                $matched[] = $entry;
            } else {
                $missing[] = $entry + ['available_base_quantity' => $available, 'missing_base_quantity' => $shortfall];
                $missingTotal = Quantity::add($missingTotal, $shortfall);
            }
            $requirements[] = ['ingredient_id' => $ingredientId, 'quantity' => $base['quantity'], 'unit_code' => $base['unit_code']];
        }

        $requiredCount = count($recipe['ingredients']);
        $satisfiedCount = count($matched);
        return [
            'recipe' => [
                'id' => $recipe['id'],
                'title' => $recipe['title'],
                'cover_url' => $recipe['cover_url'] ?? null,
            ],
            'score' => [
                'satisfied_count' => $satisfiedCount,
                'required_count' => $requiredCount,
                'ratio' => $requiredCount === 0 ? 1.0 : $satisfiedCount / $requiredCount,
                'missing_base_quantity' => $missingTotal,
            ],
            'matched_ingredients' => $matched,
            'missing_ingredients' => $missing,
            'fully_matched' => $satisfiedCount === $requiredCount,
            '_requirements' => $requirements,
        ];
    }

    /** @param array<string,mixed> $ingredient @return array<string,mixed> */
    private function ingredientResult(array $ingredient): array
    {
        return [
            'ingredient_id' => $ingredient['ingredient_id'],
            'name' => $ingredient['name'],
            'quantity' => $ingredient['quantity'],
            'unit_code' => $ingredient['unit_code'],
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function compareScores(array $left, array $right): int
    {
        if ($left['fully_matched'] !== $right['fully_matched']) return $left['fully_matched'] ? -1 : 1;
        $leftRatio = $left['score']['satisfied_count'] * $right['score']['required_count'];
        $rightRatio = $right['score']['satisfied_count'] * $left['score']['required_count'];
        if ($leftRatio !== $rightRatio) return $rightRatio <=> $leftRatio;
        $missing = Quantity::compare($left['score']['missing_base_quantity'], $right['score']['missing_base_quantity']);
        return $missing !== 0 ? $missing : $left['recipe']['id'] <=> $right['recipe']['id'];
    }

    /** @param array<int,array<string,string>> $stock @param list<array<string,mixed>> $requirements */
    private function deplete(array &$stock, array $requirements): void
    {
        foreach ($requirements as $requirement) {
            if ($requirement['quantity'] === null) continue;
            $ingredientId = $requirement['ingredient_id'];
            $unitCode = $requirement['unit_code'];
            $available = $stock[$ingredientId][$unitCode] ?? '0';
            $used = Quantity::compare($available, $requirement['quantity']) >= 0 ? $requirement['quantity'] : $available;
            $stock[$ingredientId][$unitCode] = Quantity::subtract($available, $used);
        }
    }
}

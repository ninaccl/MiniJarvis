<?php

declare(strict_types=1);

namespace Tests\Inventory;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Inventory\RecipeMatcher;
use App\Inventory\UnitConverter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedInventoryClock;
use Tests\Support\InMemoryInventoryRepository;
use Tests\Support\InMemoryRecipeRepository;

final class RecipeMatcherTest extends TestCase
{
    private InMemoryInventoryRepository $inventory;
    private InMemoryRecipeRepository $recipes;
    private RecipeMatcher $matcher;

    protected function setUp(): void
    {
        $this->inventory = new InMemoryInventoryRepository();
        $this->recipes = new InMemoryRecipeRepository();
        $this->matcher = new RecipeMatcher(
            $this->inventory,
            $this->recipes,
            new UnitConverter($this->recipes),
            new FixedInventoryClock(new DateTimeImmutable('2026-09-13', new DateTimeZone('Asia/Shanghai'))),
            new TenantGuard(),
        );
    }

    public function testMultipleBatchesCombineButExpiredAndIncompatibleStockDoNotSatisfy(): void
    {
        $flour = $this->ingredient('Flour', 'g');
        $egg = $this->ingredient('Egg', 'piece');
        $this->batch($flour, '400', 'g', null);
        $this->batch($flour, '600', 'g', '2026-09-16');
        $this->batch($flour, '9999', 'g', '2026-09-12');
        $this->batch($egg, '6', 'pack', null);
        $this->recipe(1, 'Cake', [
            $this->required($flour, 1, 'kg'),
            $this->required($egg, 1, 'piece'),
        ]);

        $match = $this->matcher->matches($this->context(), 1)[0];
        self::assertFalse($match['fully_matched']);
        self::assertSame(1, $match['score']['satisfied_count']);
        self::assertSame(['Flour'], array_column($match['matched_ingredients'], 'name'));
        self::assertSame(['Egg'], array_column($match['missing_ingredients'], 'name'));
    }

    public function testNullQuantityNeedsPresenceAndDoesNotInventCrossIngredientStock(): void
    {
        $salt = $this->ingredient('Salt', 'g');
        $pepper = $this->ingredient('Pepper', 'g');
        $this->batch($salt, '0.0001', 'g', null);
        $this->recipe(1, 'Seasoned', [$this->required($salt, null, null), $this->required($pepper, null, null)]);

        $match = $this->matcher->matches($this->context(), 1)[0];
        self::assertSame(1, $match['score']['satisfied_count']);
        self::assertSame(['Salt'], array_column($match['matched_ingredients'], 'name'));
        self::assertSame(['Pepper'], array_column($match['missing_ingredients'], 'name'));
    }

    public function testTieBreakIsFullyMatchedThenRatioThenMissingAmountThenRecipeId(): void
    {
        $rice = $this->ingredient('Rice', 'g');
        $this->batch($rice, '100', 'g', null);
        $this->recipe(9, 'Missing more', [$this->required($rice, 300, 'g')]);
        $this->recipe(8, 'Missing less', [$this->required($rice, 200, 'g')]);
        $this->recipe(3, 'Same deficit lower id', [$this->required($rice, 200, 'g')]);
        $this->recipe(12, 'Fully matched', [$this->required($rice, 50, 'g')]);

        self::assertSame(12, $this->matcher->matches($this->context(), 1)[0]['recipe']['id']);
        unset($this->recipes->recipes[12]);
        self::assertSame(3, $this->matcher->matches($this->context(), 1)[0]['recipe']['id']);
    }

    public function testGreedySelectionsVirtuallyDepleteStockWithoutChangingDatabase(): void
    {
        $egg = $this->ingredient('Egg', 'piece');
        $this->batch($egg, '2', 'piece', null);
        $this->recipe(1, 'Two eggs', [$this->required($egg, 2, 'piece')]);
        $this->recipe(2, 'One egg', [$this->required($egg, 1, 'piece')]);
        $before = $this->inventory->batches;

        $matches = $this->matcher->matches($this->context(), 2);

        self::assertSame([1, 2], array_column(array_column($matches, 'recipe'), 'id'));
        self::assertTrue($matches[0]['fully_matched']);
        self::assertFalse($matches[1]['fully_matched']);
        self::assertSame($before, $this->inventory->batches);
    }

    public function testCountMustBeBetweenOneAndTenAndOtherHouseholdsRecipesAreHidden(): void
    {
        $ingredient = $this->ingredient('Egg', 'piece');
        $this->recipe(1, 'Ours', [$this->required($ingredient, null, null)]);
        $this->recipes->recipes[2] = ['id' => 2, 'household_id' => 2, 'title' => 'Theirs', 'deleted_at' => null, 'ingredients' => []];

        self::assertSame([1], array_column(array_column($this->matcher->matches($this->context(), 2), 'recipe'), 'id'));
        foreach ([0, 11] as $count) {
            try {
                $this->matcher->matches($this->context(), $count);
                self::fail('Expected count validation failure.');
            } catch (ApiException $exception) {
                self::assertSame(422, $exception->status());
            }
        }
    }

    /** @return array<string,mixed> */
    private function ingredient(string $name, string $unit): array
    {
        return $this->recipes->createIngredient(1, 10, $name, strtolower($name), $unit);
    }

    /** @param array<string,mixed> $ingredient */
    private function batch(array $ingredient, string $quantity, string $unit, ?string $expiry): void
    {
        $this->inventory->createBatch(1, 10, [
            'ingredient_id' => $ingredient['id'], 'ingredient_name' => $ingredient['name'],
            'base_quantity' => $quantity, 'base_unit_code' => $unit,
            'display_quantity' => $quantity, 'display_unit_code' => $unit,
            'expiry_date' => $expiry, 'note' => null,
        ]);
    }

    /** @param list<array<string,mixed>> $ingredients */
    private function recipe(int $id, string $title, array $ingredients): void
    {
        $this->recipes->recipes[$id] = ['id' => $id, 'household_id' => 1, 'title' => $title, 'deleted_at' => null, 'ingredients' => $ingredients];
    }

    /** @param array<string,mixed> $ingredient @return array<string,mixed> */
    private function required(array $ingredient, int|float|null $quantity, ?string $unit): array
    {
        return ['ingredient_id' => $ingredient['id'], 'name' => $ingredient['name'], 'quantity' => $quantity, 'unit_code' => $unit];
    }

    private function context(): AuthContext
    {
        return new AuthContext(10, 1, 'member');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Shopping;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Inventory\InventoryService;
use App\Inventory\UnitConverter;
use App\Shopping\ShoppingListService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FixedInventoryClock;
use Tests\Support\InMemoryInventoryRepository;
use Tests\Support\InMemoryMealPlanRepository;
use Tests\Support\InMemoryRecipeRepository;
use Tests\Support\InMemoryShoppingListRepository;
use Tests\Support\MultiSnapshotTransactionManager;

final class ShoppingListServiceTest extends TestCase
{
    private InMemoryRecipeRepository $recipes;
    private InMemoryMealPlanRepository $plans;
    private InMemoryInventoryRepository $inventory;
    private InMemoryShoppingListRepository $shopping;
    private MultiSnapshotTransactionManager $transactions;
    private ShoppingListService $service;

    protected function setUp(): void
    {
        $this->recipes = new InMemoryRecipeRepository();
        $this->plans = new InMemoryMealPlanRepository();
        $this->inventory = new InMemoryInventoryRepository();
        $this->shopping = new InMemoryShoppingListRepository();
        $this->transactions = new MultiSnapshotTransactionManager([$this->shopping, $this->inventory, $this->recipes]);
        $converter = new UnitConverter($this->recipes);
        $clock = new FixedInventoryClock(new DateTimeImmutable('2026-09-13', new DateTimeZone('Asia/Shanghai')));
        $guard = new TenantGuard();
        $inventoryService = new InventoryService($this->transactions, $this->inventory, $this->recipes, $converter, $clock, $guard);
        $this->service = new ShoppingListService(
            $this->transactions, $this->shopping, $this->plans, $this->recipes,
            $this->inventory, $inventoryService, $converter, $clock, $guard,
        );
    }

    public function testSelectionsRequireUniqueValidDateMealPairs(): void
    {
        foreach ([
            [],
            [['date' => '2026-02-30', 'meals' => ['dinner']]],
            [['date' => '2026-09-13', 'meals' => ['snack']]],
            [['date' => '2026-09-13', 'meals' => ['dinner', 'dinner']]],
            [['date' => '2026-09-13', 'meals' => ['dinner']], ['date' => '2026-09-13', 'meals' => ['dinner']]],
        ] as $selections) {
            $this->assertApiError(fn () => $this->service->generate($this->context(), ['selections' => $selections]), 'VALIDATION_FAILED', 422);
        }
    }

    public function testNonContiguousSelectionsFilterAllThreeMealTypesAndPersistCanonicalSnapshot(): void
    {
        $ingredient = $this->ingredient('Egg', 'piece');
        $breakfast = $this->recipe('Breakfast', '2', [[$ingredient, '2', 'piece']]);
        $lunch = $this->recipe('Lunch', '2', [[$ingredient, '20', 'piece']]);
        $dinner = $this->recipe('Dinner', '2', [[$ingredient, '4', 'piece']]);
        $this->entry('2026-09-13', 'breakfast', $breakfast, '2');
        $this->entry('2026-09-13', 'lunch', $lunch, '2');
        $this->entry('2026-09-15', 'dinner', $dinner, '2');

        $list = $this->service->generate($this->context(), ['selections' => [
            ['date' => '2026-09-15', 'meals' => ['dinner']],
            ['date' => '2026-09-13', 'meals' => ['breakfast']],
        ]]);

        self::assertSame([
            ['date' => '2026-09-13', 'meals' => ['breakfast']],
            ['date' => '2026-09-15', 'meals' => ['dinner']],
        ], $list['selection_snapshot']['selections']);
        self::assertSame('6', $list['items'][0]['required_quantity']);
        self::assertSame(['Breakfast', 'Dinner'], array_column($list['items'][0]['source_summary'], 'recipe_title'));
    }

    public function testScalingConversionDiscreteUnitsAndInventoryAreAggregatedBeforeOneSubtraction(): void
    {
        $rice = $this->ingredient('Rice', 'g');
        $tomato = $this->ingredient('Tomato', 'piece');
        $salt = $this->ingredient('Salt', 'piece');
        $cilantro = $this->ingredient('Cilantro', 'bunch');
        $spice = $this->ingredient('Spice', 'pack');
        $first = $this->recipe('First', '2', [[$rice, '500', 'g'], [$tomato, '2', 'piece'], [$salt, '1', 'piece'], [$cilantro, null, null], [$spice, null, null]]);
        $second = $this->recipe('Second', '2', [[$rice, '0.5', 'kg'], [$tomato, '1', 'pack'], [$cilantro, null, null]]);
        $this->entry('2026-09-13', 'dinner', $first, '4');
        $this->entry('2026-09-14', 'dinner', $second, '2');
        $this->batch($rice, '200', 'g', null);
        $this->batch($rice, '300', 'g', '2026-09-13');
        $this->batch($rice, '900', 'g', '2026-09-12');
        $this->batch($tomato, '1', 'piece', null);
        $this->batch($salt, '2', 'piece', null);
        $this->batch($spice, '1', 'pack', null);

        $list = $this->service->generate($this->context(), ['selections' => [
            ['date' => '2026-09-13', 'meals' => ['dinner']],
            ['date' => '2026-09-14', 'meals' => ['dinner']],
        ]]);
        $byKey = [];
        foreach ($list['items'] as $item) $byKey[$item['ingredient_name'] . '|' . ($item['unit_code'] ?? 'adequate')] = $item;

        self::assertSame('1500', $byKey['Rice|g']['required_quantity']);
        self::assertSame('500', $byKey['Rice|g']['inventory_offset']);
        self::assertSame('1000', $byKey['Rice|g']['quantity']);
        self::assertSame('3', $byKey['Tomato|piece']['quantity']);
        self::assertSame('1', $byKey['Tomato|pack']['quantity']);
        self::assertNull($byKey['Cilantro|adequate']['quantity']);
        self::assertArrayNotHasKey('Salt|piece', $byKey);
        self::assertArrayNotHasKey('Spice|adequate', $byKey);
    }

    public function testGeneratedListIsAnImmutableHouseholdScopedSnapshot(): void
    {
        $ingredient = $this->ingredient('Milk', 'ml');
        $recipe = $this->recipe('Porridge', '2', [[$ingredient, '500', 'ml']]);
        $this->entry('2026-09-13', 'breakfast', $recipe, '2');
        $list = $this->service->generate($this->context(), ['selections' => [['date' => '2026-09-13', 'meals' => ['breakfast']]]]);

        $this->recipes->recipes[$recipe]['title'] = 'Changed';
        $this->recipes->recipes[$recipe]['ingredients'][0]['quantity'] = '1';
        $this->batch($ingredient, '500', 'ml', null);

        $stored = $this->service->get($this->context(), $list['id']);
        self::assertSame('Porridge', $stored['items'][0]['source_summary'][0]['recipe_title']);
        self::assertSame('500', $stored['items'][0]['quantity']);
        $this->assertApiError(fn () => $this->service->get($this->context(20, 2), $list['id']), 'SHOPPING_LIST_NOT_FOUND', 404);
    }

    public function testItemListStateAndStockInAreIdempotentAndUseOneTransactionOwner(): void
    {
        $ingredient = $this->ingredient('Egg', 'piece');
        $recipe = $this->recipe('Eggs', '1', [[$ingredient, '2', 'piece']]);
        $this->entry('2026-09-13', 'breakfast', $recipe, '1');
        $list = $this->service->generate($this->context(), ['selections' => [['date' => '2026-09-13', 'meals' => ['breakfast']]]]);
        $itemId = $list['items'][0]['id'];

        self::assertTrue($this->service->check($this->context(), $list['id'], $itemId, ['checked' => true])['checked']);
        self::assertSame('completed', $this->service->updateStatus($this->context(), $list['id'], ['status' => 'completed'])['status']);
        $this->transactions->calls = 0;
        $stocked = $this->service->stock($this->context(), $list['id'], $itemId, [
            'quantity' => 3, 'unit_code' => 'piece', 'expiry_date' => '2026-09-20', 'note' => 'actual purchase',
        ]);
        self::assertNotNull($stocked['item']['stocked_at']);
        self::assertCount(1, $this->inventory->movements);
        self::assertSame(1, $this->transactions->calls);
        $this->assertApiError(fn () => $this->service->stock($this->context(), $list['id'], $itemId, ['quantity' => 3, 'unit_code' => 'piece']), 'SHOPPING_ITEM_ALREADY_STOCKED', 409);
        self::assertCount(1, $this->inventory->movements);
    }

    public function testStockInRollsBackBatchMovementAndMarkerTogether(): void
    {
        $ingredient = $this->ingredient('Rice', 'g');
        $recipe = $this->recipe('Rice', '1', [[$ingredient, '100', 'g']]);
        $this->entry('2026-09-13', 'dinner', $recipe, '1');
        $list = $this->service->generate($this->context(), ['selections' => [['date' => '2026-09-13', 'meals' => ['dinner']]]]);
        $itemId = $list['items'][0]['id'];
        $this->inventory->failMovementWrite = true;

        try {
            $this->service->stock($this->context(), $list['id'], $itemId, ['quantity' => 100, 'unit_code' => 'g']);
            self::fail('Expected movement failure.');
        } catch (RuntimeException) {
        }
        self::assertSame([], $this->inventory->batches);
        self::assertSame([], $this->inventory->movements);
        self::assertNull($this->shopping->items[$itemId]['stocked_at']);
    }

    private function ingredient(string $name, string $unit): int
    {
        return $this->recipes->createIngredient(1, 10, $name, strtolower($name), $unit)['id'];
    }

    /** @param list<array{0:int,1:?string,2:?string}> $ingredients */
    private function recipe(string $title, string $servings, array $ingredients): int
    {
        $id = $this->recipes->createRecipe(1, 10, [
            'title' => $title, 'category_id' => 1, 'description' => '', 'instructions' => '',
            'default_servings' => $servings, 'cover_url' => null,
        ]);
        $this->recipes->replaceIngredients(1, $id, array_map(static fn (array $row): array => [
            'ingredient_id' => $row[0], 'quantity' => $row[1], 'unit_code' => $row[2], 'note' => null,
        ], $ingredients));
        return $id;
    }

    private function entry(string $date, string $meal, int $recipeId, string $servings): void
    {
        $this->plans->create(1, 10, ['date' => $date, 'meal' => $meal, 'recipe_id' => $recipeId, 'servings' => $servings]);
    }

    private function batch(int $ingredientId, string $quantity, string $unit, ?string $expiry): void
    {
        $ingredient = $this->recipes->ingredient(1, $ingredientId);
        $this->inventory->createBatch(1, 10, [
            'ingredient_id' => $ingredientId, 'ingredient_name' => $ingredient['name'],
            'base_quantity' => $quantity, 'base_unit_code' => $unit,
            'display_quantity' => $quantity, 'display_unit_code' => $unit,
            'expiry_date' => $expiry, 'note' => null,
        ]);
    }

    private function context(int $userId = 10, int $householdId = 1): AuthContext
    {
        return new AuthContext($userId, $householdId, 'member');
    }

    private function assertApiError(callable $action, string $code, int $status): void
    {
        try {
            $action();
            self::fail('Expected API error ' . $code . '.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status());
            self::assertSame($code, $exception->errorCode());
        }
    }
}

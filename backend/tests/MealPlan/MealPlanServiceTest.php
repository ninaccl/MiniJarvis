<?php

declare(strict_types=1);

namespace Tests\MealPlan;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\MealPlan\MealPlanService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InMemoryMealPlanRepository;
use Tests\Support\InMemoryRecipeRepository;
use Tests\Support\SnapshotTransactionManager;

final class MealPlanServiceTest extends TestCase
{
    private InMemoryMealPlanRepository $plans;
    private InMemoryRecipeRepository $recipes;
    private MealPlanService $service;

    protected function setUp(): void
    {
        $this->plans = new InMemoryMealPlanRepository();
        $this->recipes = new InMemoryRecipeRepository();
        $this->service = new MealPlanService(
            new SnapshotTransactionManager($this->plans),
            $this->plans,
            $this->recipes,
            new TenantGuard(),
        );
    }

    public function testDatesMealsAndServingsAreValidated(): void
    {
        foreach ([
            [['date' => '2026-02-30', 'meal' => 'dinner', 'recipes' => [['recipe_id' => 1, 'servings' => 2]]], 'date'],
            [['date' => '2026-09-13', 'meal' => 'snack', 'recipes' => [['recipe_id' => 1, 'servings' => 2]]], 'meal'],
            [['date' => '2026-09-13', 'meal' => 'dinner', 'recipes' => [['recipe_id' => 1, 'servings' => 101]]], 'recipes.0.servings'],
        ] as [$payload, $field]) {
            try {
                $this->service->add($this->context(), $payload);
                self::fail('Expected validation error.');
            } catch (ApiException $exception) {
                self::assertSame(422, $exception->status());
                self::assertArrayHasKey($field, $exception->fields());
            }
        }

        $this->assertApiError(fn () => $this->service->list($this->context(), '2026-09-14', '2026-09-13'), 'VALIDATION_FAILED', 422);
    }

    public function testBatchAddIsAtomicAndRejectsMissingDeletedOrOtherHouseholdRecipes(): void
    {
        $first = $this->recipe(1, 'First');
        $other = $this->recipe(2, 'Other');
        $this->recipes->recipes[$other]['household_id'] = 2;
        $deleted = $this->recipe(1, 'Deleted');
        $this->recipes->recipes[$deleted]['deleted_at'] = '2026-09-13T00:00:00.000000Z';

        foreach ([$other, $deleted, 999] as $invalidId) {
            $this->assertApiError(fn () => $this->service->add($this->context(), [
                'date' => '2026-09-13', 'meal' => 'dinner',
                'recipes' => [['recipe_id' => $first, 'servings' => 2], ['recipe_id' => $invalidId, 'servings' => 2]],
            ]), 'RECIPE_NOT_FOUND', 404);
            self::assertSame([], $this->plans->entries);
        }

        $this->plans->failOnCreateNumber = 2;
        try {
            $this->service->add($this->context(), [
                'date' => '2026-09-13', 'meal' => 'dinner',
                'recipes' => [['recipe_id' => $first, 'servings' => 2], ['recipe_id' => $first, 'servings' => 3]],
            ]);
            self::fail('Expected repository failure.');
        } catch (RuntimeException) {
        }
        self::assertSame([], $this->plans->entries);
    }

    public function testRangeUpdateDeleteAndHouseholdIsolation(): void
    {
        $recipe = $this->recipe(1, 'Dinner');
        $created = $this->service->add($this->context(), [
            'date' => '2026-09-13', 'meal' => 'dinner', 'recipes' => [['recipe_id' => $recipe, 'servings' => 2]],
        ])[0];

        self::assertCount(1, $this->service->list($this->context(), '2026-09-13', '2026-09-13'));
        self::assertSame([], $this->service->list($this->context(), '2026-09-14', '2026-09-15'));
        self::assertSame('3.5', $this->service->update($this->context(), $created['id'], ['servings' => 3.5])['servings']);
        self::assertSame([], $this->service->list($this->context(20, 2), '2026-09-13', '2026-09-13'));
        $this->assertApiError(fn () => $this->service->update($this->context(20, 2), $created['id'], ['servings' => 4]), 'MEAL_PLAN_ENTRY_NOT_FOUND', 404);
        $this->service->delete($this->context(), $created['id']);
        self::assertSame([], $this->plans->entries);
    }

    private function recipe(int $householdId, string $name): int
    {
        return $this->recipes->createRecipe($householdId, 10, [
            'title' => $name, 'category_id' => 1, 'description' => '', 'instructions' => '',
            'default_servings' => '2', 'cover_url' => null,
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

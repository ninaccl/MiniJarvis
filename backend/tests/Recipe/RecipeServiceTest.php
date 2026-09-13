<?php

declare(strict_types=1);

namespace Tests\Recipe;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Recipe\RecipeService;
use App\Recipe\SearchPattern;
use App\Household\TenantGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InMemoryRecipeRepository;
use Tests\Support\SnapshotTransactionManager;

final class RecipeServiceTest extends TestCase
{
    private InMemoryRecipeRepository $store;
    private RecipeService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryRecipeRepository();
        $this->service = new RecipeService(
            new SnapshotTransactionManager($this->store),
            $this->store,
            new TenantGuard(),
        );
    }

    public function testHouseholdIsolationHidesOtherHouseholdRecipes(): void
    {
        $created = $this->service->create($this->context(10, 1), $this->payload('Family one'));

        self::assertSame([], $this->service->list($this->context(20, 2), '', null, 1, 20)['items']);
        $this->assertApiError(fn () => $this->service->get($this->context(20, 2), $created['id']), 'RECIPE_NOT_FOUND', 404);
    }

    public function testSearchTreatsSqlWildcardsAsLiteralCharacters(): void
    {
        $this->service->create($this->context(), $this->payload('100% tasty'));
        $this->service->create($this->context(), $this->payload('plain meal'));
        $this->service->create($this->context(), $this->payload('under_score'));

        self::assertSame(['100% tasty'], array_column($this->service->list($this->context(), '%', null, 1, 20)['items'], 'title'));
        self::assertSame(['under_score'], array_column($this->service->list($this->context(), '_', null, 1, 20)['items'], 'title'));
        self::assertSame('%\\\\\\_%', SearchPattern::contains('\\_'));
    }

    public function testPaginationDefaultsAndMaximumAreEnforced(): void
    {
        foreach (range(1, 3) as $number) {
            $this->service->create($this->context(), $this->payload('Recipe ' . $number));
        }
        $page = $this->service->list($this->context(), '', null, 2, 2);
        self::assertCount(1, $page['items']);
        self::assertSame(['page' => 2, 'page_size' => 2, 'total' => 3, 'total_pages' => 2], $page['meta']);
        $this->assertApiError(fn () => $this->service->list($this->context(), '', null, 1, 51), 'VALIDATION_FAILED', 422);
    }

    public function testWriteValidationBoundsAreRejectedBeforePersistence(): void
    {
        $invalid = $this->payload(str_repeat('菜', 101));
        $invalid['default_servings'] = 0;
        $invalid['ingredients'] = [];
        $invalid['links'] = array_fill(0, 6, ['platform' => 'other', 'url' => 'https://bilibili.com/x']);

        try {
            $this->service->create($this->context(), $invalid);
            self::fail('Expected validation failure.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('VALIDATION_FAILED', $exception->errorCode());
            self::assertArrayHasKey('title', $exception->fields());
            self::assertArrayHasKey('default_servings', $exception->fields());
            self::assertArrayHasKey('ingredients', $exception->fields());
            self::assertArrayHasKey('links', $exception->fields());
        }
        self::assertSame([], $this->store->recipes);
    }

    public function testNewIngredientNamesAreReusedByNormalizedNameWithinHouseholdOnly(): void
    {
        $first = $this->payload('First');
        $first['ingredients'] = [['name' => "  ToFU\u{3000}", 'quantity' => 1, 'unit_code' => 'piece']];
        $second = $this->payload('Second');
        $second['ingredients'] = [['name' => 'tofu', 'quantity' => 2, 'unit_code' => 'piece']];
        $this->service->create($this->context(), $first);
        $this->service->create($this->context(), $second);
        $this->service->create($this->context(20, 2), $second);

        self::assertCount(2, $this->store->ingredients);
        self::assertSame(1, $this->store->recipes[1]['ingredients'][0]['ingredient_id']);
        self::assertSame(1, $this->store->recipes[2]['ingredients'][0]['ingredient_id']);
        self::assertSame(2, $this->store->recipes[3]['ingredients'][0]['ingredient_id']);
    }

    public function testCreateRollsBackRecipeIngredientAndLinkWritesTogether(): void
    {
        $this->store->failWhenReplacingLinks = true;
        $payload = $this->payload('Rollback');
        $payload['links'] = [['platform' => 'bilibili', 'url' => 'https://bilibili.com/video/BV1']];

        try {
            $this->service->create($this->context(), $payload);
            self::fail('Expected persistence failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('link write failed', $exception->getMessage());
        }
        self::assertSame([], $this->store->recipes);
        self::assertSame([], $this->store->ingredients);
    }

    public function testPatchReplacementIsAtomic(): void
    {
        $created = $this->service->create($this->context(), $this->payload('Original'));
        $replacement = $this->payload('Replacement');
        $replacement['ingredients'] = [['name' => 'Rice', 'quantity' => 2, 'unit_code' => 'g']];
        $replacement['links'] = [['platform' => 'other', 'url' => 'https://bilibili.com/new']];
        $this->store->failWhenReplacingLinks = true;

        try {
            $this->service->update($this->context(), $created['id'], $replacement);
            self::fail('Expected persistence failure.');
        } catch (RuntimeException) {
        }

        $actual = $this->service->get($this->context(), $created['id']);
        self::assertSame('Original', $actual['title']);
        self::assertSame('Egg', $actual['ingredients'][0]['name']);
        self::assertSame([], $actual['links']);
    }

    public function testSoftDeletedRecipeIsHiddenFromListDetailAndUpdate(): void
    {
        $created = $this->service->create($this->context(), $this->payload('Delete me'));
        $this->service->delete($this->context(), $created['id']);

        self::assertSame([], $this->service->list($this->context(), '', null, 1, 20)['items']);
        $this->assertApiError(fn () => $this->service->get($this->context(), $created['id']), 'RECIPE_NOT_FOUND', 404);
        $this->assertApiError(fn () => $this->service->update($this->context(), $created['id'], $this->payload('No')), 'RECIPE_NOT_FOUND', 404);
        self::assertNotNull($this->store->recipes[$created['id']]['deleted_at']);
    }

    public function testDecimalQuantityAcceptsExactStorageBoundaries(): void
    {
        $minimum = $this->payload('Minimum');
        $minimum['ingredients'][0]['quantity'] = 0.0001;
        $maximum = $this->payload('Maximum');
        $maximum['ingredients'][0]['quantity'] = 9999999999.9999;

        $first = $this->service->create($this->context(), $minimum);
        $second = $this->service->create($this->context(), $maximum);

        self::assertSame('0.0001', $first['ingredients'][0]['quantity']);
        self::assertSame('9999999999.9999', $second['ingredients'][0]['quantity']);
    }

    public function testRecipeIngredientQuantityAcceptsWechatInputStrings(): void
    {
        $payload = $this->payload('String quantity');
        $payload['ingredients'][0]['quantity'] = '250.5000';

        $created = $this->service->create($this->context(), $payload);

        self::assertSame('250.5', $created['ingredients'][0]['quantity']);
    }

    public function testDecimalQuantityRejectsScaleOverflowAndNonFiniteValues(): void
    {
        foreach ([0.00001, 1.23456, 10000000000, NAN, INF] as $quantity) {
            $payload = $this->payload('Invalid');
            $payload['ingredients'][0]['quantity'] = $quantity;
            $this->assertApiError(fn () => $this->service->create($this->context(), $payload), 'VALIDATION_FAILED', 422);
        }
        self::assertSame([], $this->store->recipes);
    }

    /** @return array<string,mixed> */
    private function payload(string $title): array
    {
        return [
            'title' => $title,
            'category_id' => 1,
            'description' => 'Dinner',
            'instructions' => 'Cook it',
            'default_servings' => 2,
            'cover_url' => null,
            'ingredients' => [['name' => 'Egg', 'quantity' => 1, 'unit_code' => 'piece', 'note' => 'large']],
            'links' => [],
        ];
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

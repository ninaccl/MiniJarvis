<?php

declare(strict_types=1);

namespace Tests\Inventory;

use App\Auth\AuthContext;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Inventory\InventoryService;
use App\Inventory\UnitConverter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FixedInventoryClock;
use Tests\Support\InMemoryInventoryRepository;
use Tests\Support\InMemoryRecipeRepository;
use Tests\Support\SnapshotTransactionManager;

final class InventoryServiceTest extends TestCase
{
    private InMemoryInventoryRepository $inventory;
    private InMemoryRecipeRepository $recipes;
    private InventoryService $service;

    protected function setUp(): void
    {
        $this->inventory = new InMemoryInventoryRepository();
        $this->recipes = new InMemoryRecipeRepository();
        $this->service = new InventoryService(
            new SnapshotTransactionManager($this->inventory),
            $this->inventory,
            $this->recipes,
            new UnitConverter($this->recipes),
            new FixedInventoryClock(new DateTimeImmutable('2026-09-13', new DateTimeZone('Asia/Shanghai'))),
            new TenantGuard(),
        );
    }

    public function testCreationNormalizesNewIngredientAndStoresBaseAndOriginalAmountsWithInitialMovement(): void
    {
        $first = $this->service->create($this->context(), [
            'ingredient_name' => "  FLOUR\u{3000}", 'quantity' => 2, 'unit_code' => 'kg',
            'expiry_date' => '2026-09-16', 'note' => 'bread',
        ]);
        $second = $this->service->create($this->context(), [
            'ingredient_name' => 'flour', 'quantity' => 500, 'unit_code' => 'g',
        ]);

        self::assertSame($first['ingredient_id'], $second['ingredient_id']);
        self::assertSame('2000', $first['base_quantity']);
        self::assertSame('g', $first['base_unit_code']);
        self::assertSame('2', $first['display_quantity']);
        self::assertSame('kg', $first['display_unit_code']);
        self::assertCount(1, $this->recipes->ingredients);
        self::assertCount(2, $this->inventory->movements);
        self::assertSame('add', $this->inventory->movements[1]['operation']);
        self::assertSame('2000', $this->inventory->movements[1]['base_delta']);
    }

    public function testExistingIngredientMustBelongToTheHousehold(): void
    {
        $ingredient = $this->recipes->createIngredient(1, 10, 'Egg', 'egg', 'piece');
        $this->assertApiError(fn () => $this->service->create($this->context(10, 2), [
            'ingredient_id' => $ingredient['id'], 'quantity' => 1, 'unit_code' => 'piece',
        ]), 'VALIDATION_FAILED', 422);
        self::assertSame([], $this->inventory->batches);
    }

    public function testAddConsumeAndSetConvertUnitsAndAppendSignedHistory(): void
    {
        $batch = $this->service->create($this->context(), ['ingredient_name' => 'Rice', 'quantity' => 1, 'unit_code' => 'kg']);
        self::assertSame('1500', $this->service->move($this->context(), $batch['id'], ['operation' => 'add', 'quantity' => 500, 'unit_code' => 'g'])['base_quantity']);
        self::assertSame('1250', $this->service->move($this->context(), $batch['id'], ['operation' => 'consume', 'quantity' => 0.25, 'unit_code' => 'kg'])['base_quantity']);
        self::assertSame('2000', $this->service->move($this->context(), $batch['id'], ['operation' => 'set', 'quantity' => 2, 'unit_code' => 'kg'])['base_quantity']);
        self::assertSame(['1000', '500', '-250', '750'], array_column($this->inventory->movements, 'base_delta'));
    }

    public function testNegativeStockAndMovementWriteFailuresRollBackBatchAndHistory(): void
    {
        $batch = $this->service->create($this->context(), ['ingredient_name' => 'Milk', 'quantity' => 1, 'unit_code' => 'l']);
        $this->assertApiError(fn () => $this->service->move($this->context(), $batch['id'], ['operation' => 'consume', 'quantity' => 1001, 'unit_code' => 'ml']), 'INSUFFICIENT_STOCK', 409);
        self::assertSame('1000', $this->inventory->batches[$batch['id']]['base_quantity']);
        self::assertCount(1, $this->inventory->movements);

        $this->inventory->failMovementWrite = true;
        try {
            $this->service->move($this->context(), $batch['id'], ['operation' => 'add', 'quantity' => 1, 'unit_code' => 'ml']);
            self::fail('Expected movement persistence failure.');
        } catch (RuntimeException) {
        }
        self::assertSame('1000', $this->inventory->batches[$batch['id']]['base_quantity']);
        self::assertCount(1, $this->inventory->movements);
    }

    public function testZeroStockRemainsInAllHistoryButIsNotActive(): void
    {
        $batch = $this->service->create($this->context(), ['ingredient_name' => 'Egg', 'quantity' => 2, 'unit_code' => 'piece']);
        $this->service->move($this->context(), $batch['id'], ['operation' => 'consume', 'quantity' => 2, 'unit_code' => 'piece']);

        self::assertCount(1, $this->service->list($this->context(), '', 'all'));
        self::assertSame([], $this->service->list($this->context(), '', 'active'));
        self::assertSame('inactive', $this->service->list($this->context(), '', 'all')[0]['status']);
        self::assertCount(2, $this->service->movements($this->context(), 1, 20)['items']);
    }

    public function testExpiryFiltersUseInclusiveShanghaiThreeDayBoundary(): void
    {
        foreach ([
            ['Expired', '2026-09-12'], ['Today', '2026-09-13'], ['Third day', '2026-09-16'],
            ['Fourth day', '2026-09-17'], ['No date', null],
        ] as [$name, $date]) {
            $this->service->create($this->context(), ['ingredient_name' => $name, 'quantity' => 1, 'unit_code' => 'piece', 'expiry_date' => $date]);
        }

        self::assertSame(['Today', 'Third day'], array_column($this->service->list($this->context(), '', 'expiring'), 'ingredient_name'));
        self::assertSame(['Expired'], array_column($this->service->list($this->context(), '', 'expired'), 'ingredient_name'));
        self::assertSame(['Today', 'Third day', 'Fourth day', 'No date'], array_column($this->service->list($this->context(), '', 'active'), 'ingredient_name'));
    }

    public function testPatchOnlyChangesExpiryAndNoteAndOtherHouseholdsCannotReadOrMutateBatch(): void
    {
        $batch = $this->service->create($this->context(), ['ingredient_name' => 'Tofu', 'quantity' => 1, 'unit_code' => 'pack']);
        $updated = $this->service->update($this->context(), $batch['id'], ['expiry_date' => '2026-09-16', 'note' => 'opened']);
        self::assertSame('2026-09-16', $updated['expiry_date']);
        self::assertSame('opened', $updated['note']);
        self::assertSame([], $this->service->list($this->context(20, 2), '', 'all'));
        $this->assertApiError(fn () => $this->service->update($this->context(20, 2), $batch['id'], ['note' => 'leak']), 'INVENTORY_BATCH_NOT_FOUND', 404);
        $this->assertApiError(fn () => $this->service->move($this->context(20, 2), $batch['id'], ['operation' => 'add', 'quantity' => 1, 'unit_code' => 'pack']), 'INVENTORY_BATCH_NOT_FOUND', 404);
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

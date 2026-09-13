<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Inventory\InventoryRepository;

final class InMemoryInventoryRepository implements InventoryRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $batches = [];
    /** @var array<int,array<string,mixed>> */
    public array $movements = [];
    public bool $failMovementWrite = false;
    private int $nextBatchId = 1;
    private int $nextMovementId = 1;

    public function listBatches(int $householdId, string $query): array
    {
        return array_values(array_filter($this->batches, static fn (array $batch): bool =>
            $batch['household_id'] === $householdId
            && ($query === '' || str_contains(strtolower($batch['ingredient_name']), strtolower($query)))
        ));
    }

    public function findBatch(int $householdId, int $batchId, bool $forUpdate = false): ?array
    {
        $batch = $this->batches[$batchId] ?? null;
        return $batch !== null && $batch['household_id'] === $householdId ? $batch : null;
    }

    public function createBatch(int $householdId, int $userId, array $batch): int
    {
        $id = $this->nextBatchId++;
        $this->batches[$id] = $batch + [
            'id' => $id,
            'household_id' => $householdId,
            'created_by' => $userId,
            'created_at' => '2026-09-13T00:00:00.000000Z',
            'updated_at' => '2026-09-13T00:00:00.000000Z',
        ];
        return $id;
    }

    public function updateBatchDetails(int $householdId, int $batchId, bool $hasExpiry, ?string $expiryDate, bool $hasNote, ?string $note): bool
    {
        if ($this->findBatch($householdId, $batchId) === null) return false;
        if ($hasExpiry) $this->batches[$batchId]['expiry_date'] = $expiryDate;
        if ($hasNote) $this->batches[$batchId]['note'] = $note;
        return true;
    }

    public function updateBatchQuantity(int $householdId, int $batchId, string $baseQuantity): bool
    {
        if ($this->findBatch($householdId, $batchId) === null) return false;
        $this->batches[$batchId]['base_quantity'] = $baseQuantity;
        return true;
    }

    public function appendMovement(int $householdId, int $userId, array $movement): int
    {
        if ($this->failMovementWrite) throw new \RuntimeException('movement write failed');
        $id = $this->nextMovementId++;
        $batch = $this->batches[$movement['batch_id']];
        $this->movements[$id] = $movement + [
            'id' => $id,
            'household_id' => $householdId,
            'ingredient_id' => $batch['ingredient_id'],
            'ingredient_name' => $batch['ingredient_name'],
            'actor_user_id' => $userId,
            'occurred_at' => '2026-09-13T00:00:00.000000Z',
        ];
        return $id;
    }

    public function listMovements(int $householdId, int $limit, int $offset): array
    {
        $items = array_values(array_filter($this->movements, static fn (array $row): bool => $row['household_id'] === $householdId));
        usort($items, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items)];
    }

    public function availableBatches(int $householdId, string $today): array
    {
        return array_values(array_filter($this->batches, static fn (array $batch): bool =>
            $batch['household_id'] === $householdId
            && (float) $batch['base_quantity'] > 0
            && ($batch['expiry_date'] === null || $batch['expiry_date'] >= $today)
        ));
    }
}

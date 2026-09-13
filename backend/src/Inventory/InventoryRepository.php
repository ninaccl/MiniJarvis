<?php

declare(strict_types=1);

namespace App\Inventory;

interface InventoryRepository
{
    /** @return list<array<string,mixed>> */
    public function listBatches(int $householdId, string $query): array;
    /** @return array<string,mixed>|null */
    public function findBatch(int $householdId, int $batchId, bool $forUpdate = false): ?array;
    /** @param array<string,mixed> $batch */
    public function createBatch(int $householdId, int $userId, array $batch): int;
    public function updateBatchDetails(int $householdId, int $batchId, bool $hasExpiry, ?string $expiryDate, bool $hasNote, ?string $note): bool;
    public function updateBatchQuantity(int $householdId, int $batchId, string $baseQuantity): bool;
    /** @param array<string,mixed> $movement */
    public function appendMovement(int $householdId, int $userId, array $movement): int;
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function listMovements(int $householdId, int $limit, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function availableBatches(int $householdId, string $today): array;
}

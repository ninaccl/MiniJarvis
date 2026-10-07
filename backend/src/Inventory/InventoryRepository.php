<?php

namespace App\Inventory;

interface InventoryRepository
{
    /** @return list<array<string,mixed>> */
    public function listBatches($householdId, $query);
    /** @return array<string,mixed>|null */
    public function findBatch($householdId, $batchId, $forUpdate = false);
    /** @param array<string,mixed> $batch */
    public function createBatch($householdId, $userId, array $batch);
    public function updateBatchDetails($householdId, $batchId, $hasExpiry, $expiryDate, $hasNote, $note);
    public function updateBatchQuantity($householdId, $batchId, $baseQuantity);
    /** @param array<string,mixed> $movement */
    public function appendMovement($householdId, $userId, array $movement);
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function listMovements($householdId, $limit, $offset);
    /** @return list<array<string,mixed>> */
    public function availableBatches($householdId, $today);
}

<?php

declare(strict_types=1);

namespace App\Shopping;

interface ShoppingListRepository
{
    /** @param array<string,mixed> $selectionSnapshot */
    public function createList(int $householdId, int $userId, string $name, array $selectionSnapshot): int;
    /** @param array<string,mixed> $item */
    public function addItem(int $householdId, int $listId, array $item): int;
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function list(int $householdId, int $limit, int $offset): array;
    /** @return array<string,mixed>|null */
    public function findList(int $householdId, int $listId): ?array;
    /** @return list<array<string,mixed>> */
    public function items(int $householdId, int $listId): array;
    /** @return array<string,mixed>|null */
    public function findItem(int $householdId, int $listId, int $itemId, bool $forUpdate = false): ?array;
    public function setChecked(int $householdId, int $listId, int $itemId, int $userId, bool $checked): bool;
    public function updateStatus(int $householdId, int $listId, string $status): bool;
    public function markStocked(int $householdId, int $listId, int $itemId, int $userId, int $batchId): bool;
}

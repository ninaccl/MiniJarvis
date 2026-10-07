<?php

namespace App\Shopping;

interface ShoppingListRepository
{
    /** @param array<string,mixed> $selectionSnapshot */
    public function createList($householdId, $userId, $name, array $selectionSnapshot);
    /** @param array<string,mixed> $item */
    public function addItem($householdId, $listId, array $item);
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function listItems($householdId, $limit, $offset);
    /** @return array<string,mixed>|null */
    public function findList($householdId, $listId);
    /** @return list<array<string,mixed>> */
    public function items($householdId, $listId);
    /** @return array<string,mixed>|null */
    public function findItem($householdId, $listId, $itemId, $forUpdate = false);
    public function setChecked($householdId, $listId, $itemId, $userId, $checked);
    public function updateStatus($householdId, $listId, $status);
    public function markStocked($householdId, $listId, $itemId, $userId, $batchId);
}

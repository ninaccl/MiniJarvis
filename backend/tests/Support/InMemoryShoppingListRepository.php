<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shopping\ShoppingListRepository;
use RuntimeException;

final class InMemoryShoppingListRepository implements ShoppingListRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $lists = [];
    /** @var array<int,array<string,mixed>> */
    public array $items = [];
    public bool $failMarkStocked = false;
    private int $nextListId = 1;
    private int $nextItemId = 1;

    public function createList(int $householdId, int $userId, string $name, array $selectionSnapshot): int
    {
        $id = $this->nextListId++;
        $this->lists[$id] = [
            'id' => $id, 'household_id' => $householdId, 'name' => $name, 'status' => 'active',
            'selection_snapshot' => $selectionSnapshot, 'created_by' => $userId,
            'created_at' => '2026-09-13T00:00:00.000000Z', 'updated_at' => '2026-09-13T00:00:00.000000Z',
            'completed_at' => null,
        ];
        return $id;
    }

    public function addItem(int $householdId, int $listId, array $item): int
    {
        $id = $this->nextItemId++;
        $this->items[$id] = $item + [
            'id' => $id, 'household_id' => $householdId, 'shopping_list_id' => $listId,
            'checked' => false, 'checked_by' => null, 'checked_at' => null,
            'stocked_at' => null, 'stocked_by' => null, 'stocked_batch_id' => null,
            'created_at' => '2026-09-13T00:00:00.000000Z', 'updated_at' => '2026-09-13T00:00:00.000000Z',
        ];
        return $id;
    }

    public function list(int $householdId, int $limit, int $offset): array
    {
        $items = array_values(array_filter($this->lists, static fn (array $list): bool => $list['household_id'] === $householdId));
        usort($items, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items)];
    }

    public function findList(int $householdId, int $listId): ?array
    {
        $list = $this->lists[$listId] ?? null;
        return $list !== null && $list['household_id'] === $householdId ? $list : null;
    }

    public function items(int $householdId, int $listId): array
    {
        return array_values(array_filter($this->items, static fn (array $item): bool =>
            $item['household_id'] === $householdId && $item['shopping_list_id'] === $listId
        ));
    }

    public function findItem(int $householdId, int $listId, int $itemId, bool $forUpdate = false): ?array
    {
        $item = $this->items[$itemId] ?? null;
        return $item !== null && $item['household_id'] === $householdId && $item['shopping_list_id'] === $listId ? $item : null;
    }

    public function setChecked(int $householdId, int $listId, int $itemId, int $userId, bool $checked): bool
    {
        if ($this->findItem($householdId, $listId, $itemId) === null) return false;
        $this->items[$itemId]['checked'] = $checked;
        $this->items[$itemId]['checked_by'] = $checked ? $userId : null;
        $this->items[$itemId]['checked_at'] = $checked ? '2026-09-13T00:00:00.000000Z' : null;
        return true;
    }

    public function updateStatus(int $householdId, int $listId, string $status): bool
    {
        if ($this->findList($householdId, $listId) === null) return false;
        $this->lists[$listId]['status'] = $status;
        $this->lists[$listId]['completed_at'] = $status === 'completed' ? '2026-09-13T00:00:00.000000Z' : null;
        return true;
    }

    public function markStocked(int $householdId, int $listId, int $itemId, int $userId, int $batchId): bool
    {
        if ($this->failMarkStocked) throw new RuntimeException('stock mark failed');
        $item = $this->findItem($householdId, $listId, $itemId);
        if ($item === null || $item['stocked_at'] !== null) return false;
        $this->items[$itemId]['stocked_at'] = '2026-09-13T00:00:00.000000Z';
        $this->items[$itemId]['stocked_by'] = $userId;
        $this->items[$itemId]['stocked_batch_id'] = $batchId;
        return true;
    }
}

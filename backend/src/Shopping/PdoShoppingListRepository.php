<?php

declare(strict_types=1);

namespace App\Shopping;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;

final class PdoShoppingListRepository implements ShoppingListRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createList(int $householdId, int $userId, string $name, array $selectionSnapshot): int
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_shopping_lists (household_id, name, status, selection_snapshot, created_by) VALUES (:household_id, :name, \'active\', :selection_snapshot, :created_by)');
        $statement->execute([
            'household_id' => $householdId, 'name' => $name,
            'selection_snapshot' => json_encode($selectionSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_by' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addItem(int $householdId, int $listId, array $item): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jarvis_shopping_list_items (shopping_list_id, household_id, ingredient_id, name, required_quantity, inventory_offset, quantity, unit_code, source_summary) '
            . 'VALUES (:shopping_list_id, :household_id, :ingredient_id, :name, :required_quantity, :inventory_offset, :quantity, :unit_code, :source_summary)'
        );
        $statement->execute([
            'shopping_list_id' => $listId, 'household_id' => $householdId, 'ingredient_id' => $item['ingredient_id'],
            'name' => $item['ingredient_name'], 'required_quantity' => $item['required_quantity'],
            'inventory_offset' => $item['inventory_offset'], 'quantity' => $item['quantity'], 'unit_code' => $item['unit_code'],
            'source_summary' => json_encode($item['source_summary'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function list(int $householdId, int $limit, int $offset): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM jarvis_shopping_lists WHERE household_id = :household_id');
        $count->execute(['household_id' => $householdId]);
        $statement = $this->pdo->prepare(
            $this->listSelect() . ' WHERE l.household_id = :household_id ORDER BY l.created_at DESC, l.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':household_id', $householdId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => array_map([$this, 'listRow'], $statement->fetchAll()), 'total' => (int) $count->fetchColumn()];
    }

    public function findList(int $householdId, int $listId): ?array
    {
        $statement = $this->pdo->prepare($this->listSelect() . ' WHERE l.household_id = :household_id AND l.id = :id');
        $statement->execute(['household_id' => $householdId, 'id' => $listId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->listRow($row);
    }

    public function items(int $householdId, int $listId): array
    {
        $statement = $this->pdo->prepare($this->itemSelect() . ' WHERE i.household_id = :household_id AND i.shopping_list_id = :list_id ORDER BY i.name, i.unit_code, i.id');
        $statement->execute(['household_id' => $householdId, 'list_id' => $listId]);
        return array_map([$this, 'itemRow'], $statement->fetchAll());
    }

    public function findItem(int $householdId, int $listId, int $itemId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare($this->itemSelect() . ' WHERE i.household_id = :household_id AND i.shopping_list_id = :list_id AND i.id = :id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute(['household_id' => $householdId, 'list_id' => $listId, 'id' => $itemId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->itemRow($row);
    }

    public function setChecked(int $householdId, int $listId, int $itemId, int $userId, bool $checked): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE jarvis_shopping_list_items SET is_checked = :checked, checked_household_id = :checked_household_id, checked_by = :checked_by, checked_at = :checked_at, updated_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE household_id = :household_id AND shopping_list_id = :list_id AND id = :id'
        );
        $statement->execute([
            'checked' => $checked ? 1 : 0, 'checked_household_id' => $checked ? $householdId : null,
            'checked_by' => $checked ? $userId : null, 'checked_at' => $checked ? gmdate('Y-m-d H:i:s.u') : null,
            'household_id' => $householdId, 'list_id' => $listId, 'id' => $itemId,
        ]);
        return $statement->rowCount() === 1 || $this->findItem($householdId, $listId, $itemId) !== null;
    }

    public function updateStatus(int $householdId, int $listId, string $status): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE jarvis_shopping_lists SET status = :status, completed_at = IF(:completed = 1, COALESCE(completed_at, CURRENT_TIMESTAMP(6)), NULL), updated_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE household_id = :household_id AND id = :id'
        );
        $statement->execute(['status' => $status, 'completed' => $status === 'completed' ? 1 : 0, 'household_id' => $householdId, 'id' => $listId]);
        return $statement->rowCount() === 1 || $this->findList($householdId, $listId) !== null;
    }

    public function markStocked(int $householdId, int $listId, int $itemId, int $userId, int $batchId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE jarvis_shopping_list_items SET stocked_at = CURRENT_TIMESTAMP(6), stocked_household_id = :stocked_household_id, stocked_by = :stocked_by, stocked_batch_id = :batch_id, updated_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE household_id = :household_id AND shopping_list_id = :list_id AND id = :id AND stocked_at IS NULL'
        );
        $statement->execute([
            'stocked_household_id' => $householdId, 'stocked_by' => $userId, 'batch_id' => $batchId, 'household_id' => $householdId,
            'list_id' => $listId, 'id' => $itemId,
        ]);
        return $statement->rowCount() === 1;
    }

    private function listSelect(): string
    {
        return 'SELECT l.id, l.household_id, l.name, l.status, l.selection_snapshot, l.created_by, l.created_at, l.updated_at, l.completed_at, '
            . '(SELECT COUNT(*) FROM jarvis_shopping_list_items i WHERE i.household_id = l.household_id AND i.shopping_list_id = l.id) AS item_count, '
            . '(SELECT COUNT(*) FROM jarvis_shopping_list_items i WHERE i.household_id = l.household_id AND i.shopping_list_id = l.id AND i.is_checked = 1) AS checked_count FROM jarvis_shopping_lists l';
    }

    private function itemSelect(): string
    {
        return 'SELECT i.id, i.shopping_list_id, i.ingredient_id, i.name, i.required_quantity, i.inventory_offset, i.quantity, i.unit_code, i.source_summary, '
            . 'i.is_checked, i.checked_by, i.checked_at, i.stocked_at, i.stocked_by, i.stocked_batch_id, i.created_at, i.updated_at FROM jarvis_shopping_list_items i';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function listRow(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'name' => (string) $row['name'], 'status' => (string) $row['status'],
            'selection_snapshot' => $this->json((string) $row['selection_snapshot']), 'created_by' => (int) $row['created_by'],
            'item_count' => (int) $row['item_count'], 'checked_count' => (int) $row['checked_count'],
            'created_at' => $this->timestamp((string) $row['created_at']), 'updated_at' => $this->timestamp((string) $row['updated_at']),
            'completed_at' => $row['completed_at'] === null ? null : $this->timestamp((string) $row['completed_at']),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function itemRow(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'shopping_list_id' => (int) $row['shopping_list_id'],
            'ingredient_id' => (int) $row['ingredient_id'], 'ingredient_name' => (string) $row['name'],
            'required_quantity' => $this->nullableDecimal($row['required_quantity']),
            'inventory_offset' => $this->nullableDecimal($row['inventory_offset']),
            'quantity' => $this->nullableDecimal($row['quantity']), 'unit_code' => $row['unit_code'] === null ? null : (string) $row['unit_code'],
            'source_summary' => $this->json((string) $row['source_summary']), 'checked' => (bool) $row['is_checked'],
            'checked_by' => $row['checked_by'] === null ? null : (int) $row['checked_by'],
            'checked_at' => $row['checked_at'] === null ? null : $this->timestamp((string) $row['checked_at']),
            'stocked_at' => $row['stocked_at'] === null ? null : $this->timestamp((string) $row['stocked_at']),
            'stocked_by' => $row['stocked_by'] === null ? null : (int) $row['stocked_by'],
            'stocked_batch_id' => $row['stocked_batch_id'] === null ? null : (int) $row['stocked_batch_id'],
            'created_at' => $this->timestamp((string) $row['created_at']), 'updated_at' => $this->timestamp((string) $row['updated_at']),
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $value): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Stored shopping JSON is invalid.', 0, $exception);
        }
        if (!is_array($decoded)) throw new \RuntimeException('Stored shopping JSON is invalid.');
        return $decoded;
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = rtrim(rtrim((string) $value, '0'), '.');
        return $value === '' ? '0' : $value;
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Recipe\SearchPattern;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoInventoryRepository implements InventoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listBatches(int $householdId, string $query): array
    {
        $sql = $this->batchSelect() . ' WHERE b.household_id = :household_id';
        $params = ['household_id' => $householdId];
        if ($query !== '') {
            $sql .= " AND i.name LIKE :query ESCAPE '\\\\'";
            $params['query'] = SearchPattern::contains($query);
        }
        $sql .= ' ORDER BY b.id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return array_map([$this, 'batchRow'], $statement->fetchAll());
    }

    public function findBatch(int $householdId, int $batchId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare($this->batchSelect() . ' WHERE b.household_id = :household_id AND b.id = :id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute(['household_id' => $householdId, 'id' => $batchId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->batchRow($row);
    }

    public function createBatch(int $householdId, int $userId, array $batch): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jarvis_inventory_batches (household_id, ingredient_id, quantity, unit_code, display_quantity, display_unit_code, expires_on, note, created_by) '
            . 'VALUES (:household_id, :ingredient_id, :quantity, :unit_code, :display_quantity, :display_unit_code, :expires_on, :note, :created_by)'
        );
        $statement->execute([
            'household_id' => $householdId, 'ingredient_id' => $batch['ingredient_id'],
            'quantity' => $batch['base_quantity'], 'unit_code' => $batch['base_unit_code'],
            'display_quantity' => $batch['display_quantity'], 'display_unit_code' => $batch['display_unit_code'],
            'expires_on' => $batch['expiry_date'], 'note' => $batch['note'], 'created_by' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateBatchDetails(int $householdId, int $batchId, bool $hasExpiry, ?string $expiryDate, bool $hasNote, ?string $note): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE jarvis_inventory_batches SET '
            . 'expires_on = IF(:has_expiry = 1, :expires_on, expires_on), '
            . 'note = IF(:has_note = 1, :note, note), updated_at = CURRENT_TIMESTAMP(6) '
            . 'WHERE household_id = :household_id AND id = :id'
        );
        $statement->execute([
            'has_expiry' => $hasExpiry ? 1 : 0, 'expires_on' => $expiryDate,
            'has_note' => $hasNote ? 1 : 0, 'note' => $note,
            'household_id' => $householdId, 'id' => $batchId,
        ]);
        return $statement->rowCount() === 1 || $this->findBatch($householdId, $batchId) !== null;
    }

    public function updateBatchQuantity(int $householdId, int $batchId, string $baseQuantity): bool
    {
        $statement = $this->pdo->prepare('UPDATE jarvis_inventory_batches SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP(6) WHERE household_id = :household_id AND id = :id');
        $statement->execute(['quantity' => $baseQuantity, 'household_id' => $householdId, 'id' => $batchId]);
        return $statement->rowCount() === 1 || $this->findBatch($householdId, $batchId) !== null;
    }

    public function appendMovement(int $householdId, int $userId, array $movement): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jarvis_inventory_movements (household_id, batch_id, ingredient_id, movement_type, quantity, unit_code, display_quantity, display_unit_code, actor_user_id, note) '
            . 'SELECT b.household_id, b.id, b.ingredient_id, :movement_type, :quantity, :unit_code, :display_quantity, :display_unit_code, :actor_user_id, :note '
            . 'FROM jarvis_inventory_batches b WHERE b.household_id = :household_id AND b.id = :batch_id'
        );
        $statement->execute([
            'movement_type' => $movement['operation'], 'quantity' => $movement['base_delta'],
            'unit_code' => $movement['base_unit_code'], 'display_quantity' => $movement['display_quantity'],
            'display_unit_code' => $movement['display_unit_code'], 'actor_user_id' => $userId,
            'note' => $movement['note'], 'household_id' => $householdId, 'batch_id' => $movement['batch_id'],
        ]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('Inventory movement batch disappeared during the transaction.');
        return (int) $this->pdo->lastInsertId();
    }

    public function listMovements(int $householdId, int $limit, int $offset): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM jarvis_inventory_movements WHERE household_id = :household_id');
        $count->execute(['household_id' => $householdId]);
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.batch_id, m.ingredient_id, i.name AS ingredient_name, m.movement_type AS operation, '
            . 'm.quantity AS base_delta, m.unit_code AS base_unit_code, m.display_quantity, m.display_unit_code, '
            . 'm.actor_user_id, m.note, m.occurred_at '
            . 'FROM jarvis_inventory_movements m JOIN jarvis_ingredients i ON i.household_id = m.household_id AND i.id = m.ingredient_id '
            . 'WHERE m.household_id = :household_id ORDER BY m.occurred_at DESC, m.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':household_id', $householdId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return [
            'items' => array_map([$this, 'movementRow'], $statement->fetchAll()),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function availableBatches(int $householdId, string $today): array
    {
        $statement = $this->pdo->prepare(
            $this->batchSelect()
            . ' WHERE b.household_id = :household_id AND b.quantity > 0 AND (b.expires_on IS NULL OR b.expires_on >= :today) ORDER BY b.id'
        );
        $statement->execute(['household_id' => $householdId, 'today' => $today]);
        return array_map([$this, 'batchRow'], $statement->fetchAll());
    }

    private function batchSelect(): string
    {
        return 'SELECT b.id, b.household_id, b.ingredient_id, i.name AS ingredient_name, '
            . 'b.quantity AS base_quantity, b.unit_code AS base_unit_code, b.display_quantity, b.display_unit_code, '
            . 'b.expires_on AS expiry_date, b.note, b.created_by, b.created_at, b.updated_at '
            . 'FROM jarvis_inventory_batches b JOIN jarvis_ingredients i ON i.household_id = b.household_id AND i.id = b.ingredient_id';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function batchRow(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'ingredient_id' => (int) $row['ingredient_id'],
            'ingredient_name' => (string) $row['ingredient_name'],
            'base_quantity' => $this->decimal((string) $row['base_quantity']), 'base_unit_code' => (string) $row['base_unit_code'],
            'display_quantity' => $this->decimal((string) $row['display_quantity']), 'display_unit_code' => (string) $row['display_unit_code'],
            'expiry_date' => $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'created_by' => (int) $row['created_by'],
            'created_at' => $this->timestamp((string) $row['created_at']), 'updated_at' => $this->timestamp((string) $row['updated_at']),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function movementRow(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'batch_id' => (int) $row['batch_id'], 'ingredient_id' => (int) $row['ingredient_id'],
            'ingredient_name' => (string) $row['ingredient_name'], 'operation' => (string) $row['operation'],
            'base_delta' => $this->decimal((string) $row['base_delta']), 'base_unit_code' => (string) $row['base_unit_code'],
            'display_quantity' => $this->decimal((string) $row['display_quantity']), 'display_unit_code' => (string) $row['display_unit_code'],
            'actor_user_id' => (int) $row['actor_user_id'], 'note' => $row['note'] === null ? null : (string) $row['note'],
            'occurred_at' => $this->timestamp((string) $row['occurred_at']),
        ];
    }

    private function decimal(string $value): string
    {
        if (str_contains($value, '.')) $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' || $value === '-' ? '0' : $value;
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
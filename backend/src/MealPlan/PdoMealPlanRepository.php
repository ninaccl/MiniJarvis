<?php

declare(strict_types=1);

namespace App\MealPlan;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoMealPlanRepository implements MealPlanRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(int $householdId, string $from, string $to): array
    {
        $statement = $this->pdo->prepare($this->select() . ' WHERE m.household_id = :household_id AND m.meal_date BETWEEN :date_from AND :date_to ORDER BY m.meal_date, FIELD(m.meal_type, \'breakfast\', \'lunch\', \'dinner\'), m.id');
        $statement->execute(['household_id' => $householdId, 'date_from' => $from, 'date_to' => $to]);
        return array_map([$this, 'row'], $statement->fetchAll());
    }

    public function selected(int $householdId, array $selectionPairs): array
    {
        if ($selectionPairs === []) return [];
        $clauses = [];
        $params = ['household_id' => $householdId];
        foreach ($selectionPairs as $index => $pair) {
            $clauses[] = "(m.meal_date = :date_$index AND m.meal_type = :meal_$index)";
            $params["date_$index"] = $pair['date'];
            $params["meal_$index"] = $pair['meal'];
        }
        $statement = $this->pdo->prepare($this->select() . ' WHERE m.household_id = :household_id AND (' . implode(' OR ', $clauses) . ') ORDER BY m.meal_date, FIELD(m.meal_type, \'breakfast\', \'lunch\', \'dinner\'), m.id');
        $statement->execute($params);
        return array_map([$this, 'row'], $statement->fetchAll());
    }

    public function find(int $householdId, int $entryId): ?array
    {
        $statement = $this->pdo->prepare($this->select() . ' WHERE m.household_id = :household_id AND m.id = :id');
        $statement->execute(['household_id' => $householdId, 'id' => $entryId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->row($row);
    }

    public function create(int $householdId, int $userId, array $entry): int
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_meal_plan_entries (household_id, recipe_id, meal_date, meal_type, servings, created_by) VALUES (:household_id, :recipe_id, :meal_date, :meal_type, :servings, :created_by)');
        $statement->execute([
            'household_id' => $householdId, 'recipe_id' => $entry['recipe_id'], 'meal_date' => $entry['date'],
            'meal_type' => $entry['meal'], 'servings' => $entry['servings'], 'created_by' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateServings(int $householdId, int $entryId, string $servings): bool
    {
        $statement = $this->pdo->prepare('UPDATE jarvis_meal_plan_entries SET servings = :servings, updated_at = CURRENT_TIMESTAMP(6) WHERE household_id = :household_id AND id = :id');
        $statement->execute(['servings' => $servings, 'household_id' => $householdId, 'id' => $entryId]);
        return $statement->rowCount() === 1 || $this->find($householdId, $entryId) !== null;
    }

    public function delete(int $householdId, int $entryId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM jarvis_meal_plan_entries WHERE household_id = :household_id AND id = :id');
        $statement->execute(['household_id' => $householdId, 'id' => $entryId]);
        return $statement->rowCount() === 1;
    }

    private function select(): string
    {
        return 'SELECT m.id, m.household_id, m.recipe_id, m.meal_date, m.meal_type, m.servings, m.created_by, m.created_at, m.updated_at, r.name AS recipe_title, r.image_url AS recipe_cover_url FROM jarvis_meal_plan_entries m JOIN jarvis_recipes r ON r.household_id = m.household_id AND r.id = m.recipe_id';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function row(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'recipe_id' => (int) $row['recipe_id'],
            'recipe_title' => (string) $row['recipe_title'], 'recipe_cover_url' => $row['recipe_cover_url'] === null ? null : (string) $row['recipe_cover_url'],
            'date' => (string) $row['meal_date'], 'meal' => (string) $row['meal_type'],
            'servings' => $this->decimal((string) $row['servings']), 'created_by' => (int) $row['created_by'],
            'created_at' => $this->timestamp((string) $row['created_at']), 'updated_at' => $this->timestamp((string) $row['updated_at']),
        ];
    }

    private function decimal(string $value): string
    {
        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' ? '0' : $value;
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
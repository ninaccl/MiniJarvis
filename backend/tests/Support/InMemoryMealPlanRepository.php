<?php

declare(strict_types=1);

namespace Tests\Support;

use App\MealPlan\MealPlanRepository;
use RuntimeException;

final class InMemoryMealPlanRepository implements MealPlanRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $entries = [];
    public ?int $failOnCreateNumber = null;
    public int $createCount = 0;
    private int $nextId = 1;

    public function list(int $householdId, string $from, string $to): array
    {
        $items = array_values(array_filter($this->entries, static fn (array $entry): bool =>
            $entry['household_id'] === $householdId && $entry['date'] >= $from && $entry['date'] <= $to
        ));
        usort($items, static fn (array $a, array $b): int => [$a['date'], $a['meal'], $a['id']] <=> [$b['date'], $b['meal'], $b['id']]);
        return $items;
    }

    public function selected(int $householdId, array $selectionPairs): array
    {
        $wanted = array_fill_keys(array_map(static fn (array $pair): string => $pair['date'] . '|' . $pair['meal'], $selectionPairs), true);
        return array_values(array_filter($this->entries, static fn (array $entry): bool =>
            $entry['household_id'] === $householdId && isset($wanted[$entry['date'] . '|' . $entry['meal']])
        ));
    }

    public function find(int $householdId, int $entryId): ?array
    {
        $entry = $this->entries[$entryId] ?? null;
        return $entry !== null && $entry['household_id'] === $householdId ? $entry : null;
    }

    public function create(int $householdId, int $userId, array $entry): int
    {
        ++$this->createCount;
        if ($this->failOnCreateNumber === $this->createCount) throw new RuntimeException('meal entry write failed');
        $id = $this->nextId++;
        $this->entries[$id] = $entry + [
            'id' => $id, 'household_id' => $householdId, 'created_by' => $userId,
            'created_at' => '2026-09-13T00:00:00.000000Z', 'updated_at' => '2026-09-13T00:00:00.000000Z',
        ];
        return $id;
    }

    public function updateServings(int $householdId, int $entryId, string $servings): bool
    {
        if ($this->find($householdId, $entryId) === null) return false;
        $this->entries[$entryId]['servings'] = $servings;
        return true;
    }

    public function delete(int $householdId, int $entryId): bool
    {
        if ($this->find($householdId, $entryId) === null) return false;
        unset($this->entries[$entryId]);
        return true;
    }
}

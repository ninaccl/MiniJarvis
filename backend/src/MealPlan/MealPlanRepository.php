<?php

declare(strict_types=1);

namespace App\MealPlan;

interface MealPlanRepository
{
    /** @return list<array<string,mixed>> */
    public function list(int $householdId, string $from, string $to): array;
    /** @param list<array{date:string,meal:string}> $selectionPairs @return list<array<string,mixed>> */
    public function selected(int $householdId, array $selectionPairs): array;
    /** @return array<string,mixed>|null */
    public function find(int $householdId, int $entryId): ?array;
    /** @param array<string,mixed> $entry */
    public function create(int $householdId, int $userId, array $entry): int;
    public function updateServings(int $householdId, int $entryId, string $servings): bool;
    public function delete(int $householdId, int $entryId): bool;
}

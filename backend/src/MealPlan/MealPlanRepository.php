<?php

namespace App\MealPlan;

interface MealPlanRepository
{
    /** @return list<array<string,mixed>> */
    public function listItems($householdId, $from, $to);
    /** @param list<array{date:string,meal:string}> $selectionPairs @return list<array<string,mixed>> */
    public function selected($householdId, array $selectionPairs);
    /** @return array<string,mixed>|null */
    public function find($householdId, $entryId);
    /** @param array<string,mixed> $entry */
    public function create($householdId, $userId, array $entry);
    public function updateServings($householdId, $entryId, $servings);
    public function delete($householdId, $entryId);
}

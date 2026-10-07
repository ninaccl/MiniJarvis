<?php

namespace App\Shopping;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Inventory\InventoryClock;
use App\Inventory\InventoryRepository;
use App\Inventory\InventoryService;
use App\Inventory\Quantity;
use App\Inventory\UnitConverter;
use App\MealPlan\MealPlanRepository;
use App\Recipe\RecipeRepository;
use DateTimeImmutable;
final class ShoppingListService
{
    private $transactions;
    private $shopping;
    private $plans;
    private $recipes;
    private $inventory;
    private $inventoryService;
    private $converter;
    private $clock;
    private $guard;
    private static function meals()
    {
        return ['breakfast', 'lunch', 'dinner'];
    }
    public function __construct(TransactionManager $transactions, ShoppingListRepository $shopping, MealPlanRepository $plans, RecipeRepository $recipes, InventoryRepository $inventory, InventoryService $inventoryService, UnitConverter $converter, InventoryClock $clock, TenantGuard $guard)
    {
        $this->transactions = $transactions;
        $this->shopping = $shopping;
        $this->plans = $plans;
        $this->recipes = $recipes;
        $this->inventory = $inventory;
        $this->inventoryService = $inventoryService;
        $this->converter = $converter;
        $this->clock = $clock;
        $this->guard = $guard;
    }
    /** @return array<string,mixed> */
    public function generate(AuthContext $context, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        $selections = $this->selections(isset($payload['selections']) ? $payload['selections'] : null);
        return $this->transactions->transaction(function () use ($householdId, $context, $selections) {
            $pairs = [];
            foreach ($selections as $selection) {
                foreach ($selection['meals'] as $meal) {
                    $pairs[] = ['date' => $selection['date'], 'meal' => $meal];
                }
            }
            $entries = $this->plans->selected($householdId, $pairs);
            $aggregates = [];
            $entrySnapshots = [];
            foreach ($entries as $entry) {
                $recipe = $this->recipes->find($householdId, (int) $entry['recipe_id']);
                if ($recipe === null) {
                    throw new ApiException(409, 'MEAL_PLAN_RECIPE_UNAVAILABLE', 'A selected meal references a recipe that is no longer available.');
                }
                $ingredients = [];
                foreach ($recipe['ingredients'] as $ingredient) {
                    $ingredients[] = ['ingredient_id' => (int) $ingredient['ingredient_id'], 'ingredient_name' => (string) $ingredient['name'], 'quantity' => $ingredient['quantity'] === null ? null : Quantity::format((float) $ingredient['quantity']), 'unit_code' => $ingredient['unit_code'], 'note' => $ingredient['note']];
                    $source = ['meal_plan_entry_id' => (int) $entry['id'], 'date' => (string) $entry['date'], 'meal' => (string) $entry['meal'], 'recipe_id' => (int) $recipe['id'], 'recipe_title' => (string) $recipe['title'], 'planned_servings' => Quantity::format((float) $entry['servings']), 'default_servings' => Quantity::format((float) $recipe['default_servings']), 'ingredient_quantity' => $ingredient['quantity'] === null ? null : Quantity::format((float) $ingredient['quantity']), 'ingredient_unit_code' => $ingredient['unit_code'], 'ingredient_note' => $ingredient['note']];
                    $ingredientId = (int) $ingredient['ingredient_id'];
                    if ($ingredient['quantity'] === null) {
                        $key = $ingredientId . '|adequate';
                        if (!isset($aggregates[$key])) {
                            $aggregates[$key] = ['ingredient_id' => $ingredientId, 'ingredient_name' => (string) $ingredient['name'], 'required_quantity' => null, 'unit_code' => null, 'source_summary' => []];
                        }
                    } else {
                        $scaled = Quantity::format((float) $ingredient['quantity'] * (float) $entry['servings'] / (float) $recipe['default_servings']);
                        $base = $this->converter->toBase($scaled, (string) $ingredient['unit_code']);
                        $key = $ingredientId . '|' . $base['unit_code'];
                        if (!isset($aggregates[$key])) {
                            $aggregates[$key] = ['ingredient_id' => $ingredientId, 'ingredient_name' => (string) $ingredient['name'], 'required_quantity' => '0', 'unit_code' => $base['unit_code'], 'source_summary' => []];
                        }
                        $aggregates[$key]['required_quantity'] = Quantity::add($aggregates[$key]['required_quantity'], $base['quantity']);
                        $source['scaled_quantity'] = $base['quantity'];
                        $source['scaled_unit_code'] = $base['unit_code'];
                    }
                    $aggregates[$key]['source_summary'][] = $source;
                }
                $entrySnapshots[] = ['id' => (int) $entry['id'], 'date' => (string) $entry['date'], 'meal' => (string) $entry['meal'], 'servings' => Quantity::format((float) $entry['servings']), 'recipe' => ['id' => (int) $recipe['id'], 'title' => (string) $recipe['title'], 'default_servings' => Quantity::format((float) $recipe['default_servings']), 'ingredients' => $ingredients]];
            }
            $available = [];
            $hasStock = [];
            foreach ($this->inventory->availableBatches($householdId, $this->clock->today()->format('Y-m-d')) as $batch) {
                $ingredientId = (int) $batch['ingredient_id'];
                $hasStock[$ingredientId] = true;
                $key = $ingredientId . '|' . $batch['base_unit_code'];
                $available[$key] = Quantity::add(isset($available[$key]) ? $available[$key] : '0', (string) $batch['base_quantity']);
            }
            $items = [];
            foreach ($aggregates as $key => $aggregate) {
                if ($aggregate['required_quantity'] === null) {
                    if (isset($hasStock[$aggregate['ingredient_id']])) {
                        continue;
                    }
                    $items[] = $aggregate + ['inventory_offset' => null, 'quantity' => null];
                    continue;
                }
                $offset = isset($available[$key]) ? $available[$key] : '0';
                if (Quantity::compare($offset, $aggregate['required_quantity']) > 0) {
                    $offset = $aggregate['required_quantity'];
                }
                $deficit = Quantity::subtract($aggregate['required_quantity'], $offset);
                if (Quantity::compare($deficit, '0') <= 0) {
                    continue;
                }
                $items[] = $aggregate + ['inventory_offset' => $offset, 'quantity' => $deficit];
            }
            usort($items, static function (array $a, array $b) {
                $left = [$a['ingredient_name'], isset($a['unit_code']) ? $a['unit_code'] : ''];
                $right = [$b['ingredient_name'], isset($b['unit_code']) ? $b['unit_code'] : ''];
                return $left < $right ? -1 : ($left > $right ? 1 : 0);
            });
            $snapshot = ['selections' => $selections, 'meal_entries' => $entrySnapshots];
            $listId = $this->shopping->createList($householdId, $context->userId, $this->listName($selections), $snapshot);
            foreach ($items as $item) {
                $this->shopping->addItem($householdId, $listId, $item);
            }
            return $this->getForHousehold($householdId, $listId);
        });
    }
    /** @return array{items:list<array<string,mixed>>,meta:array<string,int>} */
    public function listItems(AuthContext $context, $page = 1, $pageSize = 20)
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if ($page < 1) {
            $fields['page'] = 'Must be at least 1.';
        }
        if ($pageSize < 1 || $pageSize > 50) {
            $fields['page_size'] = 'Must be between 1 and 50.';
        }
        $this->throwValidation($fields, 'Shopping-list query is invalid.');
        $result = $this->shopping->listItems($householdId, $pageSize, ($page - 1) * $pageSize);
        return ['items' => $result['items'], 'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $result['total'], 'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $pageSize)]];
    }
    /** @return array<string,mixed> */
    public function get(AuthContext $context, $listId)
    {
        return $this->getForHousehold($this->guard->requireMembership($context), $listId);
    }
    /** @return array<string,mixed> */
    public function check(AuthContext $context, $listId, $itemId, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        if (array_keys($payload) !== ['checked'] || !is_bool(isset($payload['checked']) ? $payload['checked'] : null)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Shopping item is invalid.', ['checked' => 'Must be a boolean.']);
        }
        $this->requireList($householdId, $listId);
        if (!$this->shopping->setChecked($householdId, $listId, $itemId, $context->userId, $payload['checked'])) {
            throw $this->itemNotFound();
        }
        return \App\Support\Compat::valueOrThrow($this->shopping->findItem($householdId, $listId, $itemId), $this->itemNotFound());
    }
    /** @return array<string,mixed> */
    public function updateStatus(AuthContext $context, $listId, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        $status = isset($payload['status']) ? $payload['status'] : null;
        if (array_keys($payload) !== ['status'] || !is_string($status) || !in_array($status, ['active', 'completed'], true)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Shopping list is invalid.', ['status' => 'Must be active or completed.']);
        }
        if (!$this->shopping->updateStatus($householdId, $listId, $status)) {
            throw $this->notFound();
        }
        return $this->requireList($householdId, $listId);
    }
    /** @return array{item:array<string,mixed>,batch:array<string,mixed>} */
    public function stock(AuthContext $context, $listId, $itemId, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        return $this->transactions->transaction(function () use ($householdId, $context, $listId, $itemId, $payload) {
            $this->requireList($householdId, $listId);
            $item = \App\Support\Compat::valueOrThrow($this->shopping->findItem($householdId, $listId, $itemId, true), $this->itemNotFound());
            if ($item['stocked_at'] !== null) {
                throw new ApiException(409, 'SHOPPING_ITEM_ALREADY_STOCKED', 'This shopping item has already been stocked.');
            }
            $batch = $this->inventoryService->createWithinTransaction($context, ['ingredient_id' => (int) $item['ingredient_id'], 'quantity' => isset($payload['quantity']) ? $payload['quantity'] : null, 'unit_code' => isset($payload['unit_code']) ? $payload['unit_code'] : null, 'expiry_date' => isset($payload['expiry_date']) ? $payload['expiry_date'] : null, 'note' => isset($payload['note']) ? $payload['note'] : null]);
            if (!$this->shopping->markStocked($householdId, $listId, $itemId, $context->userId, (int) $batch['id'])) {
                throw new ApiException(409, 'SHOPPING_ITEM_ALREADY_STOCKED', 'This shopping item has already been stocked.');
            }
            return ['item' => \App\Support\Compat::valueOrThrow($this->shopping->findItem($householdId, $listId, $itemId), $this->itemNotFound()), 'batch' => $batch];
        });
    }
    /** @return list<array{date:string,meals:list<string>}> */
    private function selections($value)
    {
        $fields = [];
        if (!is_array($value) || !\App\Support\Compat::isList($value) || $value === []) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Shopping-list selections are invalid.', ['selections' => 'Must contain at least one selection.']);
        }
        $pairs = [];
        $byDate = [];
        foreach ($value as $index => $selection) {
            if (!is_array($selection)) {
                $fields["selections.{$index}"] = 'Must be an object.';
                continue;
            }
            $date = isset($selection['date']) ? $selection['date'] : null;
            $meals = isset($selection['meals']) ? $selection['meals'] : null;
            if (!$this->validDate($date)) {
                $fields["selections.{$index}.date"] = 'Must be a valid YYYY-MM-DD date.';
            }
            if (!is_array($meals) || !\App\Support\Compat::isList($meals) || $meals === []) {
                $fields["selections.{$index}.meals"] = 'Must contain at least one meal.';
                continue;
            }
            foreach ($meals as $mealIndex => $meal) {
                if (!is_string($meal) || !in_array($meal, self::meals(), true)) {
                    $fields["selections.{$index}.meals.{$mealIndex}"] = 'Must be breakfast, lunch, or dinner.';
                    continue;
                }
                $key = (string) $date . '|' . $meal;
                if (isset($pairs[$key])) {
                    $fields["selections.{$index}.meals.{$mealIndex}"] = 'Duplicate date and meal selection.';
                }
                $pairs[$key] = true;
                $byDate[(string) $date][$meal] = true;
            }
        }
        $this->throwValidation($fields, 'Shopping-list selections are invalid.');
        ksort($byDate);
        $canonical = [];
        foreach ($byDate as $date => $meals) {
            $canonicalMeals = array_values(array_filter(self::meals(), static function ($meal) use ($meals) {
                return isset($meals[$meal]);
            }));
            $canonical[] = ['date' => $date, 'meals' => $canonicalMeals];
        }
        return $canonical;
    }
    private function validDate($value)
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->clock->today()->getTimezone());
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    /** @param list<array{date:string,meals:list<string>}> $selections */
    private function listName(array $selections)
    {
        $first = $selections[0]['date'];
        $last = $selections[count($selections) - 1]['date'];
        return $first === $last ? '购物清单 ' . $first : '购物清单 ' . $first . '–' . $last;
    }
    /** @return array<string,mixed> */
    private function getForHousehold($householdId, $listId)
    {
        return $this->requireList($householdId, $listId) + ['items' => $this->shopping->items($householdId, $listId)];
    }
    /** @return array<string,mixed> */
    private function requireList($householdId, $listId)
    {
        return \App\Support\Compat::valueOrThrow($this->shopping->findList($householdId, $listId), $this->notFound());
    }
    private function notFound()
    {
        return new ApiException(404, 'SHOPPING_LIST_NOT_FOUND', 'The shopping list was not found.');
    }
    private function itemNotFound()
    {
        return new ApiException(404, 'SHOPPING_ITEM_NOT_FOUND', 'The shopping-list item was not found.');
    }
    /** @param array<string,string> $fields */
    private function throwValidation(array $fields, $message)
    {
        if ($fields !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', $message, $fields);
        }
    }
}

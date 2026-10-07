<?php

namespace App\MealPlan;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Recipe\DecimalQuantity;
use App\Recipe\RecipeRepository;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
final class MealPlanService
{
    private $transactions;
    private $plans;
    private $recipes;
    private $guard;
    private static function meals()
    {
        return ['breakfast', 'lunch', 'dinner'];
    }
    public function __construct(TransactionManager $transactions, MealPlanRepository $plans, RecipeRepository $recipes, TenantGuard $guard)
    {
        $this->transactions = $transactions;
        $this->plans = $plans;
        $this->recipes = $recipes;
        $this->guard = $guard;
    }
    /** @return list<array<string,mixed>> */
    public function listItems(AuthContext $context, $from, $to)
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if (!$this->validDate($from)) {
            $fields['from'] = 'Must be a valid YYYY-MM-DD date.';
        }
        if (!$this->validDate($to)) {
            $fields['to'] = 'Must be a valid YYYY-MM-DD date.';
        }
        if ($fields === [] && $from > $to) {
            $fields['to'] = 'Must be on or after from.';
        }
        $this->throwValidation($fields, 'Meal-plan range is invalid.');
        return $this->plans->listItems($householdId, $from, $to);
    }
    /** @return list<array<string,mixed>> */
    public function add(AuthContext $context, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        $date = isset($payload['date']) ? $payload['date'] : null;
        $meal = isset($payload['meal']) ? $payload['meal'] : null;
        $recipeRows = isset($payload['recipes']) ? $payload['recipes'] : null;
        $fields = [];
        if (!$this->validDate($date)) {
            $fields['date'] = 'Must be a valid YYYY-MM-DD date.';
        }
        if (!is_string($meal) || !in_array($meal, self::meals(), true)) {
            $fields['meal'] = 'Must be breakfast, lunch, or dinner.';
        }
        if (!is_array($recipeRows) || !\App\Support\Compat::isList($recipeRows) || $recipeRows === []) {
            $fields['recipes'] = 'Must contain at least one recipe.';
            $recipeRows = [];
        }
        $validated = [];
        foreach ($recipeRows as $index => $row) {
            if (!is_array($row)) {
                $fields["recipes.{$index}"] = 'Must be an object.';
                continue;
            }
            $recipeId = isset($row['recipe_id']) ? $row['recipe_id'] : null;
            if (!is_int($recipeId) || $recipeId < 1) {
                $fields["recipes.{$index}.recipe_id"] = 'Must be a positive integer.';
            }
            $servings = $this->servings(isset($row['servings']) ? $row['servings'] : null, "recipes.{$index}.servings", $fields);
            $validated[] = ['recipe_id' => $recipeId, 'servings' => $servings];
        }
        $this->throwValidation($fields, 'Meal-plan entry is invalid.');
        return $this->transactions->transaction(function () use ($householdId, $context, $date, $meal, $validated) {
            $created = [];
            foreach ($validated as $row) {
                if ($this->recipes->find($householdId, $row['recipe_id']) === null) {
                    throw $this->recipeNotFound();
                }
                $id = $this->plans->create($householdId, $context->userId, ['date' => $date, 'meal' => $meal, 'recipe_id' => $row['recipe_id'], 'servings' => $row['servings']]);
                $created[] = \App\Support\Compat::valueOrThrow($this->plans->find($householdId, $id), new \RuntimeException('Meal-plan entry disappeared during creation.'));
            }
            return $created;
        });
    }
    /** @return array<string,mixed> */
    public function update(AuthContext $context, $entryId, array $payload)
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if (array_keys($payload) !== ['servings']) {
            $fields['payload'] = 'Only servings may be updated.';
        }
        $servings = $this->servings(isset($payload['servings']) ? $payload['servings'] : null, 'servings', $fields);
        $this->throwValidation($fields, 'Meal-plan entry is invalid.');
        if (!$this->plans->updateServings($householdId, $entryId, $servings)) {
            throw $this->notFound();
        }
        return \App\Support\Compat::valueOrThrow($this->plans->find($householdId, $entryId), $this->notFound());
    }
    public function delete(AuthContext $context, $entryId)
    {
        $householdId = $this->guard->requireMembership($context);
        if (!$this->plans->delete($householdId, $entryId)) {
            throw $this->notFound();
        }
    }
    /** @param array<string,string> $fields */
    private function servings($value, $field, array &$fields)
    {
        try {
            $servings = DecimalQuantity::forStorage($value);
            if ((float) $servings > 100) {
                throw new \InvalidArgumentException();
            }
            return $servings;
        } catch (Exception $ignored) {
            $fields[$field] = 'Must be between 1 and 100 with at most 4 fractional digits.';
            return '0';
        }
    }
    private function validDate($value)
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    private function recipeNotFound()
    {
        return new ApiException(404, 'RECIPE_NOT_FOUND', 'The recipe was not found.');
    }
    private function notFound()
    {
        return new ApiException(404, 'MEAL_PLAN_ENTRY_NOT_FOUND', 'The meal-plan entry was not found.');
    }
    /** @param array<string,string> $fields */
    private function throwValidation(array $fields, $message)
    {
        if ($fields !== []) {
            throw new ApiException(422, 'VALIDATION_FAILED', $message, $fields);
        }
    }
}

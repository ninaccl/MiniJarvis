<?php

namespace App\Recipe;

interface RecipeRepository
{
    /** @return list<array{id:int,name:string}> */
    public function categories();
    public function categoryExists($categoryId);
    /** @return array{code:string,display_name:string,dimension:string,base_factor:string}|null */
    public function unit($code);
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function listItems($householdId, $query, $categoryId, $limit, $offset);
    /** @return list<array<string,mixed>> */
    public function activeForMatching($householdId);
    /** @return array<string,mixed>|null */
    public function find($householdId, $recipeId);
    /** @return array<string,mixed>|null */
    public function ingredient($householdId, $ingredientId);
    /** @return array<string,mixed>|null */
    public function ingredientByNormalizedName($householdId, $normalizedName);
    /** @return array<string,mixed> */
    public function createIngredient($householdId, $userId, $name, $normalizedName, $defaultUnitCode);
    /** @param array<string,mixed> $recipe */
    public function createRecipe($householdId, $userId, array $recipe);
    /** @param array<string,mixed> $recipe */
    public function updateRecipe($householdId, $recipeId, array $recipe);
    /** @param list<array<string,mixed>> $ingredients */
    public function replaceIngredients($householdId, $recipeId, array $ingredients);
    /** @param list<array<string,mixed>> $links */
    public function replaceLinks($householdId, $recipeId, $userId, array $links);
    public function softDelete($householdId, $recipeId);
}

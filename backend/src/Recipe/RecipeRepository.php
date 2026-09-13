<?php

declare(strict_types=1);

namespace App\Recipe;

interface RecipeRepository
{
    /** @return list<array{id:int,name:string}> */
    public function categories(): array;
    public function categoryExists(int $categoryId): bool;
    /** @return array{code:string,display_name:string,dimension:string,base_factor:string}|null */
    public function unit(string $code): ?array;
    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function list(int $householdId, string $query, ?int $categoryId, int $limit, int $offset): array;
    /** @return list<array<string,mixed>> */
    public function activeForMatching(int $householdId): array;
    /** @return array<string,mixed>|null */
    public function find(int $householdId, int $recipeId): ?array;
    /** @return array<string,mixed>|null */
    public function ingredient(int $householdId, int $ingredientId): ?array;
    /** @return array<string,mixed>|null */
    public function ingredientByNormalizedName(int $householdId, string $normalizedName): ?array;
    /** @return array<string,mixed> */
    public function createIngredient(int $householdId, int $userId, string $name, string $normalizedName, string $defaultUnitCode): array;
    /** @param array<string,mixed> $recipe */
    public function createRecipe(int $householdId, int $userId, array $recipe): int;
    /** @param array<string,mixed> $recipe */
    public function updateRecipe(int $householdId, int $recipeId, array $recipe): bool;
    /** @param list<array<string,mixed>> $ingredients */
    public function replaceIngredients(int $householdId, int $recipeId, array $ingredients): void;
    /** @param list<array<string,mixed>> $links */
    public function replaceLinks(int $householdId, int $recipeId, int $userId, array $links): void;
    public function softDelete(int $householdId, int $recipeId): bool;
}

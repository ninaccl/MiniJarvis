<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Recipe\IngredientNormalizer;
use App\Recipe\RecipeRepository;
use RuntimeException;

final class InMemoryRecipeRepository implements RecipeRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $recipes = [];
    /** @var array<int,array<string,mixed>> */
    public array $ingredients = [];
    public bool $failWhenReplacingLinks = false;
    private int $nextRecipeId = 1;
    private int $nextIngredientId = 1;

    public function categories(): array
    {
        return [
            ['id' => 1, 'name' => '荤菜'],
            ['id' => 2, 'name' => '素菜'],
        ];
    }

    public function categoryExists(int $categoryId): bool
    {
        return in_array($categoryId, [1, 2], true);
    }

    public function unit(string $code): ?array
    {
        $units = [
            'g' => ['code' => 'g', 'display_name' => '克', 'dimension' => 'mass', 'base_factor' => '1.000000'],
            'kg' => ['code' => 'kg', 'display_name' => '千克', 'dimension' => 'mass', 'base_factor' => '1000.000000'],
            'ml' => ['code' => 'ml', 'display_name' => '毫升', 'dimension' => 'volume', 'base_factor' => '1.000000'],
            'l' => ['code' => 'l', 'display_name' => '升', 'dimension' => 'volume', 'base_factor' => '1000.000000'],
            'piece' => ['code' => 'piece', 'display_name' => '个', 'dimension' => 'discrete', 'base_factor' => '1.000000'],
            'pack' => ['code' => 'pack', 'display_name' => '包', 'dimension' => 'discrete', 'base_factor' => '1.000000'],
            'box' => ['code' => 'box', 'display_name' => '盒', 'dimension' => 'discrete', 'base_factor' => '1.000000'],
            'bunch' => ['code' => 'bunch', 'display_name' => '把', 'dimension' => 'discrete', 'base_factor' => '1.000000'],
            'tbsp' => ['code' => 'tbsp', 'display_name' => '汤匙', 'dimension' => 'discrete', 'base_factor' => '1.000000'],
            'tsp' => ['code' => 'tsp', 'display_name' => '茶匙', 'dimension' => 'discrete', 'base_factor' => '1.000000'],
        ];
        return $units[$code] ?? null;
    }

    public function list(int $householdId, string $query, ?int $categoryId, int $limit, int $offset): array
    {
        $items = array_values(array_filter($this->recipes, static function (array $recipe) use ($householdId, $query, $categoryId): bool {
            if ($recipe['household_id'] !== $householdId || $recipe['deleted_at'] !== null) {
                return false;
            }
            if ($categoryId !== null && $recipe['category_id'] !== $categoryId) {
                return false;
            }
            return $query === '' || str_contains($recipe['title'], $query) || str_contains($recipe['description'], $query);
        }));
        usort($items, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items)];
    }

    public function activeForMatching(int $householdId): array
    {
        $items = array_values(array_filter($this->recipes, static fn (array $recipe): bool =>
            $recipe['household_id'] === $householdId && $recipe['deleted_at'] === null
        ));
        usort($items, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $items;
    }

    public function find(int $householdId, int $recipeId): ?array
    {
        $recipe = $this->recipes[$recipeId] ?? null;
        return $recipe !== null && $recipe['household_id'] === $householdId && $recipe['deleted_at'] === null ? $recipe : null;
    }

    public function ingredient(int $householdId, int $ingredientId): ?array
    {
        $ingredient = $this->ingredients[$ingredientId] ?? null;
        return $ingredient !== null && $ingredient['household_id'] === $householdId ? $ingredient : null;
    }

    public function ingredientByNormalizedName(int $householdId, string $normalizedName): ?array
    {
        foreach ($this->ingredients as $ingredient) {
            if ($ingredient['household_id'] === $householdId && $ingredient['normalized_name'] === $normalizedName) {
                return $ingredient;
            }
        }
        return null;
    }

    public function createIngredient(int $householdId, int $userId, string $name, string $normalizedName, string $defaultUnitCode): array
    {
        $existing = $this->ingredientByNormalizedName($householdId, $normalizedName);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->nextIngredientId++;
        return $this->ingredients[$id] = [
            'id' => $id,
            'household_id' => $householdId,
            'name' => trim($name),
            'normalized_name' => $normalizedName,
            'default_unit_code' => $defaultUnitCode,
        ];
    }

    public function createRecipe(int $householdId, int $userId, array $recipe): int
    {
        $id = $this->nextRecipeId++;
        $this->recipes[$id] = $recipe + [
            'id' => $id,
            'household_id' => $householdId,
            'created_by' => $userId,
            'category' => ['id' => $recipe['category_id'], 'name' => $recipe['category_id'] === 1 ? '荤菜' : '素菜'],
            'ingredients' => [],
            'links' => [],
            'created_at' => '2026-09-13T00:00:00.000000Z',
            'updated_at' => '2026-09-13T00:00:00.000000Z',
            'deleted_at' => null,
        ];
        return $id;
    }

    public function updateRecipe(int $householdId, int $recipeId, array $recipe): bool
    {
        if ($this->find($householdId, $recipeId) === null) {
            return false;
        }
        $this->recipes[$recipeId] = $recipe + $this->recipes[$recipeId];
        return true;
    }

    public function replaceIngredients(int $householdId, int $recipeId, array $ingredients): void
    {
        $rows = [];
        foreach ($ingredients as $row) {
            $ingredient = $this->ingredients[$row['ingredient_id']];
            $rows[] = $row + [
                'name' => $ingredient['name'],
                'normalized_name' => IngredientNormalizer::normalize($ingredient['name']),
                'unit' => $row['unit_code'] === null ? null : $this->unit($row['unit_code']),
            ];
        }
        $this->recipes[$recipeId]['ingredients'] = $rows;
    }

    public function replaceLinks(int $householdId, int $recipeId, int $userId, array $links): void
    {
        if ($this->failWhenReplacingLinks) {
            throw new RuntimeException('link write failed');
        }
        $this->recipes[$recipeId]['links'] = array_map(
            static fn (array $link, int $index): array => ['id' => $index + 1] + $link,
            $links,
            array_keys($links),
        );
    }

    public function softDelete(int $householdId, int $recipeId): bool
    {
        if ($this->find($householdId, $recipeId) === null) {
            return false;
        }
        $this->recipes[$recipeId]['deleted_at'] = '2026-09-13T01:00:00.000000Z';
        return true;
    }
}

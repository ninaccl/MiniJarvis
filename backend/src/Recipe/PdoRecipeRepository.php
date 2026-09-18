<?php

declare(strict_types=1);

namespace App\Recipe;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PdoRecipeRepository implements RecipeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function categories(): array
    {
        $rows = $this->pdo->query('SELECT id, name FROM jarvis_recipe_categories ORDER BY sort_order, id')->fetchAll();
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']], $rows);
    }

    public function categoryExists(int $categoryId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM jarvis_recipe_categories WHERE id = :id');
        $statement->execute(['id' => $categoryId]);
        return $statement->fetchColumn() !== false;
    }

    public function unit(string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT code, display_name, dimension, base_factor FROM jarvis_units WHERE code = :code');
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();
        return $row === false ? null : [
            'code' => (string) $row['code'], 'display_name' => (string) $row['display_name'],
            'dimension' => (string) $row['dimension'], 'base_factor' => (string) $row['base_factor'],
        ];
    }

    public function list(int $householdId, string $query, ?int $categoryId, int $limit, int $offset): array
    {
        $where = ['r.household_id = :household_id', 'r.deleted_at IS NULL'];
        $params = ['household_id' => $householdId];
        if ($query !== '') {
            $where[] = "(r.name LIKE :search_name ESCAPE '\\\\' OR COALESCE(r.description, '') LIKE :search_description ESCAPE '\\\\')";
            $params['search_name'] = SearchPattern::contains($query);
            $params['search_description'] = SearchPattern::contains($query);
        }
        if ($categoryId !== null) {
            $where[] = 'r.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }
        $filter = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM jarvis_recipes r WHERE ' . $filter);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $statement = $this->pdo->prepare('SELECT r.id FROM jarvis_recipes r WHERE ' . $filter . ' ORDER BY r.updated_at DESC, r.id DESC LIMIT :limit OFFSET :offset');
        foreach ($params as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $recipe = $this->find($householdId, (int) $id);
            if ($recipe !== null) $items[] = $recipe;
        }
        return ['items' => $items, 'total' => $total];
    }

    public function activeForMatching(int $householdId): array
    {
        $statement = $this->pdo->prepare('SELECT id FROM jarvis_recipes WHERE household_id = :household_id AND deleted_at IS NULL ORDER BY id');
        $statement->execute(['household_id' => $householdId]);
        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $recipe = $this->find($householdId, (int) $id);
            if ($recipe !== null) $items[] = $recipe;
        }
        return $items;
    }

    public function find(int $householdId, int $recipeId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.*, c.name AS category_name FROM jarvis_recipes r '
            . 'LEFT JOIN jarvis_recipe_categories c ON c.id = r.category_id '
            . 'WHERE r.household_id = :household_id AND r.id = :id AND r.deleted_at IS NULL'
        );
        $statement->execute(['household_id' => $householdId, 'id' => $recipeId]);
        $row = $statement->fetch();
        if ($row === false) return null;

        $ingredients = $this->pdo->prepare(
            'SELECT ri.ingredient_id, i.name, i.normalized_name, ri.quantity, ri.unit_code, ri.note, '
            . 'u.display_name AS unit_display_name, u.dimension AS unit_dimension, u.base_factor AS unit_base_factor '
            . 'FROM jarvis_recipe_ingredients ri JOIN jarvis_ingredients i ON i.household_id = ri.household_id AND i.id = ri.ingredient_id '
            . 'LEFT JOIN jarvis_units u ON u.code = ri.unit_code '
            . 'WHERE ri.household_id = :household_id AND ri.recipe_id = :recipe_id ORDER BY ri.sort_order, ri.id'
        );
        $ingredients->execute(['household_id' => $householdId, 'recipe_id' => $recipeId]);
        $ingredientRows = array_map(function (array $item): array {
            $unit = $item['unit_code'] === null ? null : [
                'code' => (string) $item['unit_code'], 'display_name' => (string) $item['unit_display_name'],
                'dimension' => (string) $item['unit_dimension'], 'base_factor' => (string) $item['unit_base_factor'],
            ];
            return [
                'ingredient_id' => (int) $item['ingredient_id'], 'name' => (string) $item['name'],
                'normalized_name' => (string) $item['normalized_name'],
                'quantity' => $item['quantity'] === null ? null : (float) $item['quantity'],
                'unit_code' => $item['unit_code'] === null ? null : (string) $item['unit_code'],
                'unit' => $unit, 'note' => $item['note'] === null ? null : (string) $item['note'],
            ];
        }, $ingredients->fetchAll());

        $links = $this->pdo->prepare('SELECT id, platform, url, miniapp_app_id, miniapp_path FROM jarvis_recipe_links WHERE household_id = :household_id AND recipe_id = :recipe_id ORDER BY id');
        $links->execute(['household_id' => $householdId, 'recipe_id' => $recipeId]);
        $linkRows = array_map(static fn (array $link): array => [
            'id' => (int) $link['id'], 'platform' => (string) $link['platform'], 'url' => (string) $link['url'],
            'miniapp_app_id' => $link['miniapp_app_id'] === null ? null : (string) $link['miniapp_app_id'],
            'miniapp_path' => $link['miniapp_path'] === null ? null : (string) $link['miniapp_path'],
        ], $links->fetchAll());

        return [
            'id' => (int) $row['id'], 'title' => (string) $row['name'],
            'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'category' => $row['category_id'] === null ? null : ['id' => (int) $row['category_id'], 'name' => (string) $row['category_name']],
            'description' => $row['description'] === null ? '' : (string) $row['description'],
            'instructions' => $row['instructions'] === null ? '' : (string) $row['instructions'],
            'default_servings' => (float) $row['servings'],
            'cover_url' => $row['image_url'] === null ? null : (string) $row['image_url'],
            'ingredients' => $ingredientRows, 'links' => $linkRows,
            'created_at' => $this->timestamp((string) $row['created_at']),
            'updated_at' => $this->timestamp((string) $row['updated_at']),
            'deleted_at' => null,
        ];
    }

    public function ingredient(int $householdId, int $ingredientId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, household_id, name, normalized_name, default_unit_code FROM jarvis_ingredients WHERE household_id = :household_id AND id = :id');
        $statement->execute(['household_id' => $householdId, 'id' => $ingredientId]);
        return ($row = $statement->fetch()) === false ? null : $this->ingredientRow($row);
    }

    public function ingredientByNormalizedName(int $householdId, string $normalizedName): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, household_id, name, normalized_name, default_unit_code FROM jarvis_ingredients WHERE household_id = :household_id AND normalized_name = :name');
        $statement->execute(['household_id' => $householdId, 'name' => $normalizedName]);
        return ($row = $statement->fetch()) === false ? null : $this->ingredientRow($row);
    }

    public function createIngredient(int $householdId, int $userId, string $name, string $normalizedName, string $defaultUnitCode): array
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO jarvis_ingredients (household_id, name, normalized_name, default_unit_code, created_by) '
            . 'VALUES (:household_id, :name, :normalized_name, :unit, :user_id) '
            . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute(['household_id' => $householdId, 'name' => $name, 'normalized_name' => $normalizedName, 'unit' => $defaultUnitCode, 'user_id' => $userId]);
        $id = (int) $this->pdo->lastInsertId();
        return $this->ingredient($householdId, $id) ?? throw new \RuntimeException('Ingredient write could not be read.');
    }

    public function createRecipe(int $householdId, int $userId, array $recipe): int
    {
        $statement = $this->pdo->prepare('INSERT INTO jarvis_recipes (household_id, category_id, created_by, name, description, instructions, servings, image_url) VALUES (:household_id, :category_id, :user_id, :name, :description, :instructions, :servings, :image_url)');
        $statement->execute($this->recipeParams($householdId, $userId, $recipe));
        return (int) $this->pdo->lastInsertId();
    }

    public function updateRecipe(int $householdId, int $recipeId, array $recipe): bool
    {
        $statement = $this->pdo->prepare('UPDATE jarvis_recipes SET category_id = :category_id, name = :name, description = :description, instructions = :instructions, servings = :servings, image_url = :image_url, updated_at = CURRENT_TIMESTAMP(6) WHERE household_id = :household_id AND id = :id AND deleted_at IS NULL');
        $params = $this->recipeParams($householdId, 0, $recipe);
        unset($params['user_id']);
        $params['id'] = $recipeId;
        $statement->execute($params);
        return $statement->rowCount() === 1 || $this->find($householdId, $recipeId) !== null;
    }

    public function replaceIngredients(int $householdId, int $recipeId, array $ingredients): void
    {
        $delete = $this->pdo->prepare('DELETE FROM jarvis_recipe_ingredients WHERE household_id = :household_id AND recipe_id = :recipe_id');
        $delete->execute(['household_id' => $householdId, 'recipe_id' => $recipeId]);
        $insert = $this->pdo->prepare('INSERT INTO jarvis_recipe_ingredients (household_id, recipe_id, ingredient_id, quantity, unit_code, note, sort_order) VALUES (:household_id, :recipe_id, :ingredient_id, :quantity, :unit_code, :note, :sort_order)');
        foreach ($ingredients as $row) $insert->execute(['household_id' => $householdId, 'recipe_id' => $recipeId] + $row);
    }

    public function replaceLinks(int $householdId, int $recipeId, int $userId, array $links): void
    {
        $delete = $this->pdo->prepare('DELETE FROM jarvis_recipe_links WHERE household_id = :household_id AND recipe_id = :recipe_id');
        $delete->execute(['household_id' => $householdId, 'recipe_id' => $recipeId]);
        $insert = $this->pdo->prepare('INSERT INTO jarvis_recipe_links (household_id, recipe_id, platform, url, miniapp_app_id, miniapp_path, created_by) VALUES (:household_id, :recipe_id, :platform, :url, :miniapp_app_id, :miniapp_path, :user_id)');
        foreach ($links as $link) $insert->execute(['household_id' => $householdId, 'recipe_id' => $recipeId, 'user_id' => $userId] + $link);
    }

    public function softDelete(int $householdId, int $recipeId): bool
    {
        $statement = $this->pdo->prepare('UPDATE jarvis_recipes SET deleted_at = CURRENT_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6) WHERE household_id = :household_id AND id = :id AND deleted_at IS NULL');
        $statement->execute(['household_id' => $householdId, 'id' => $recipeId]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed> */
    private function recipeParams(int $householdId, int $userId, array $recipe): array
    {
        return ['household_id' => $householdId, 'user_id' => $userId, 'category_id' => $recipe['category_id'], 'name' => $recipe['title'], 'description' => $recipe['description'], 'instructions' => $recipe['instructions'], 'servings' => $recipe['default_servings'], 'image_url' => $recipe['cover_url']];
    }

    /** @return array<string,mixed> */
    private function ingredientRow(array $row): array
    {
        return ['id' => (int) $row['id'], 'household_id' => (int) $row['household_id'], 'name' => (string) $row['name'], 'normalized_name' => (string) $row['normalized_name'], 'default_unit_code' => (string) $row['default_unit_code']];
    }

    private function timestamp(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
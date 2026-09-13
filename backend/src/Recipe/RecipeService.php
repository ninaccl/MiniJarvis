<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Support\Text;

final class RecipeService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly RecipeRepository $recipes,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return list<array{id:int,name:string}> */
    public function categories(AuthContext $context): array
    {
        $this->guard->requireMembership($context);
        return $this->recipes->categories();
    }

    /** @return array{items:list<array<string,mixed>>,meta:array{page:int,page_size:int,total:int,total_pages:int}} */
    public function list(AuthContext $context, string $query, ?int $categoryId, int $page = 1, int $pageSize = 20): array
    {
        $householdId = $this->guard->requireMembership($context);
        $fields = [];
        if ($page < 1) $fields['page'] = 'Must be at least 1.';
        if ($pageSize < 1 || $pageSize > 50) $fields['page_size'] = 'Must be between 1 and 50.';
        if (Text::length($query) > 200) $fields['q'] = 'Maximum length is 200 characters.';
        if ($categoryId !== null && !$this->recipes->categoryExists($categoryId)) $fields['category_id'] = 'Unknown category.';
        $this->throwValidation($fields);

        $result = $this->recipes->list($householdId, trim($query), $categoryId, $pageSize, ($page - 1) * $pageSize);
        return [
            'items' => $result['items'],
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $result['total'],
                'total_pages' => $result['total'] === 0 ? 0 : (int) ceil($result['total'] / $pageSize),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function create(AuthContext $context, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $validated = $this->validate($payload);
        return $this->transactions->transaction(function () use ($householdId, $context, $validated): array {
            $ingredients = $this->resolveIngredients($householdId, $context->userId, $validated['ingredients']);
            $id = $this->recipes->createRecipe($householdId, $context->userId, $validated['recipe']);
            $this->recipes->replaceIngredients($householdId, $id, $ingredients);
            $this->recipes->replaceLinks($householdId, $id, $context->userId, $validated['links']);
            return $this->requireRecipe($householdId, $id);
        });
    }

    /** @return array<string,mixed> */
    public function get(AuthContext $context, int $recipeId): array
    {
        return $this->requireRecipe($this->guard->requireMembership($context), $recipeId);
    }

    /** @return array<string,mixed> */
    public function update(AuthContext $context, int $recipeId, array $payload): array
    {
        $householdId = $this->guard->requireMembership($context);
        $validated = $this->validate($payload);
        return $this->transactions->transaction(function () use ($householdId, $context, $recipeId, $validated): array {
            $this->requireRecipe($householdId, $recipeId);
            $ingredients = $this->resolveIngredients($householdId, $context->userId, $validated['ingredients']);
            if (!$this->recipes->updateRecipe($householdId, $recipeId, $validated['recipe'])) {
                throw $this->notFound();
            }
            $this->recipes->replaceIngredients($householdId, $recipeId, $ingredients);
            $this->recipes->replaceLinks($householdId, $recipeId, $context->userId, $validated['links']);
            return $this->requireRecipe($householdId, $recipeId);
        });
    }

    public function delete(AuthContext $context, int $recipeId): void
    {
        $householdId = $this->guard->requireMembership($context);
        if (!$this->recipes->softDelete($householdId, $recipeId)) throw $this->notFound();
    }

    /** @return array{recipe:array<string,mixed>,ingredients:list<array<string,mixed>>,links:list<array<string,mixed>>} */
    private function validate(array $payload): array
    {
        $fields = [];
        $title = $payload['title'] ?? null;
        $description = $payload['description'] ?? '';
        $instructions = $payload['instructions'] ?? '';
        $categoryId = $payload['category_id'] ?? null;
        $servings = $payload['default_servings'] ?? null;
        $coverUrl = $payload['cover_url'] ?? null;
        $ingredients = $payload['ingredients'] ?? null;
        $links = $payload['links'] ?? [];

        if (!is_string($title) || trim($title) === '' || Text::length(trim($title)) > 100) $fields['title'] = 'Required; maximum length is 100 characters.';
        if (!is_int($categoryId) || $categoryId < 1 || !$this->recipes->categoryExists($categoryId)) $fields['category_id'] = 'Unknown category.';
        if (!is_string($description) || Text::length($description) > 2000) $fields['description'] = 'Maximum length is 2000 characters.';
        if (!is_string($instructions) || Text::length($instructions) > 10000) $fields['instructions'] = 'Maximum length is 10000 characters.';
        if ((!is_int($servings) && !is_float($servings)) || $servings < 1 || $servings > 100) $fields['default_servings'] = 'Must be between 1 and 100.';
        if ($coverUrl !== null && (!is_string($coverUrl) || !str_starts_with($coverUrl, '/uploads/') || Text::length($coverUrl) > 2048)) $fields['cover_url'] = 'Must be an uploaded image URL.';
        if (!is_array($ingredients) || count($ingredients) < 1 || count($ingredients) > 50) $fields['ingredients'] = 'Must contain between 1 and 50 entries.';
        if (!is_array($links) || count($links) > 5) $fields['links'] = 'Must contain at most 5 entries.';

        $validIngredients = [];
        if (is_array($ingredients) && count($ingredients) <= 50) {
            foreach (array_values($ingredients) as $index => $ingredient) {
                $valid = $this->validateIngredient($ingredient, $index, $fields);
                if ($valid !== null) $validIngredients[] = $valid;
            }
        }
        $validLinks = [];
        if (is_array($links) && count($links) <= 5) {
            foreach (array_values($links) as $index => $link) {
                $valid = $this->validateLink($link, $index, $fields);
                if ($valid !== null) $validLinks[] = $valid;
            }
        }
        $this->throwValidation($fields);

        return [
            'recipe' => [
                'title' => trim($title), 'category_id' => $categoryId, 'description' => $description,
                'instructions' => $instructions, 'default_servings' => (float) $servings, 'cover_url' => $coverUrl,
            ],
            'ingredients' => $validIngredients,
            'links' => $validLinks,
        ];
    }

    /** @param array<string,string> $fields @return array<string,mixed>|null */
    private function validateIngredient(mixed $value, int $index, array &$fields): ?array
    {
        $key = 'ingredients.' . $index;
        if (!is_array($value)) { $fields[$key] = 'Must be an object.'; return null; }
        $id = $value['ingredient_id'] ?? null;
        $name = $value['name'] ?? null;
        if (($id === null) === ($name === null) || ($id !== null && (!is_int($id) || $id < 1))) $fields[$key] = 'Provide exactly one valid ingredient_id or name.';
        if ($name !== null && (!is_string($name) || IngredientNormalizer::normalize($name) === '' || Text::length(trim($name)) > 120)) $fields[$key . '.name'] = 'Must be 1-120 characters.';
        $quantity = $value['quantity'] ?? null;
        $normalizedQuantity = null;
        $unitCode = $value['unit_code'] ?? null;
        if ($quantity !== null) {
            try {
                $normalizedQuantity = DecimalQuantity::forStorage($quantity);
            } catch (\Throwable) {
                $fields[$key . '.quantity'] = 'Must fit DECIMAL(14,4), be positive, finite, and use at most 4 fractional digits.';
            }
        }
        if ($quantity !== null && (!is_string($unitCode) || $this->recipes->unit($unitCode) === null)) $fields[$key . '.unit_code'] = 'A known unit is required with quantity.';
        if ($quantity === null && $unitCode !== null) $fields[$key . '.unit_code'] = 'Must be omitted when quantity is omitted.';
        $note = $value['note'] ?? null;
        if ($note !== null && (!is_string($note) || Text::length($note) > 255)) $fields[$key . '.note'] = 'Maximum length is 255 characters.';
        return ['ingredient_id' => $id, 'name' => $name, 'quantity' => $normalizedQuantity, 'unit_code' => $unitCode, 'note' => $note];
    }

    /** @param array<string,string> $fields @return array<string,mixed>|null */
    private function validateLink(mixed $value, int $index, array &$fields): ?array
    {
        $key = 'links.' . $index;
        if (!is_array($value)) { $fields[$key] = 'Must be an object.'; return null; }
        $platform = $value['platform'] ?? null;
        $url = $value['url'] ?? null;
        if (!is_string($platform) || !in_array($platform, ['douyin', 'bilibili', 'xiaohongshu', 'other'], true)) $fields[$key . '.platform'] = 'Unknown platform.';
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_string($url) || Text::length($url) > 2048 || $parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user'])) $fields[$key . '.url'] = 'Must be a valid HTTPS URL without credentials.';
        foreach (['miniapp_app_id' => 128, 'miniapp_path' => 1024] as $field => $limit) {
            if (isset($value[$field]) && (!is_string($value[$field]) || Text::length($value[$field]) > $limit)) $fields[$key . '.' . $field] = 'Invalid value.';
        }
        return ['platform' => $platform, 'url' => $url, 'miniapp_app_id' => $value['miniapp_app_id'] ?? null, 'miniapp_path' => $value['miniapp_path'] ?? null];
    }

    /** @param list<array<string,mixed>> $ingredients @return list<array<string,mixed>> */
    private function resolveIngredients(int $householdId, int $userId, array $ingredients): array
    {
        $resolved = [];
        $seen = [];
        foreach ($ingredients as $index => $row) {
            if ($row['ingredient_id'] !== null) {
                $ingredient = $this->recipes->ingredient($householdId, $row['ingredient_id']);
                if ($ingredient === null) throw new ApiException(422, 'VALIDATION_FAILED', 'Recipe payload is invalid.', ['ingredients.' . $index . '.ingredient_id' => 'Unknown ingredient.']);
            } else {
                $normalized = IngredientNormalizer::normalize($row['name']);
                $ingredient = $this->recipes->ingredientByNormalizedName($householdId, $normalized)
                    ?? $this->recipes->createIngredient($householdId, $userId, trim($row['name']), $normalized, $row['unit_code'] ?? 'piece');
            }
            if (isset($seen[$ingredient['id']])) throw new ApiException(422, 'VALIDATION_FAILED', 'Recipe payload is invalid.', ['ingredients.' . $index => 'Duplicate ingredient.']);
            $seen[$ingredient['id']] = true;
            $resolved[] = ['ingredient_id' => $ingredient['id'], 'quantity' => $row['quantity'], 'unit_code' => $row['unit_code'], 'note' => $row['note'], 'sort_order' => $index];
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    private function requireRecipe(int $householdId, int $recipeId): array
    {
        return $this->recipes->find($householdId, $recipeId) ?? throw $this->notFound();
    }

    private function notFound(): ApiException
    {
        return new ApiException(404, 'RECIPE_NOT_FOUND', 'The recipe was not found.');
    }

    /** @param array<string,string> $fields */
    private function throwValidation(array $fields): void
    {
        if ($fields !== []) throw new ApiException(422, 'VALIDATION_FAILED', 'Recipe payload is invalid.', $fields);
    }
}

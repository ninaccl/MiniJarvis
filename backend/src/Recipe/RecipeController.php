<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class RecipeController
{
    public function __construct(private readonly RecipeService $recipes)
    {
    }

    public function categories(Request $request): Response
    {
        return Response::success($this->recipes->categories($this->context($request)));
    }

    public function list(Request $request): Response
    {
        $page = $this->positiveInt($request->query('page', '1'), 'page');
        $pageSize = $this->positiveInt($request->query('page_size', '20'), 'page_size');
        $categoryRaw = $request->query('category_id');
        $categoryId = $categoryRaw === null || $categoryRaw === '' ? null : $this->positiveInt($categoryRaw, 'category_id');
        $result = $this->recipes->list($this->context($request), $request->query('q', '') ?? '', $categoryId, $page, $pageSize);
        return Response::success($result['items'], 200, $result['meta']);
    }

    public function create(Request $request): Response
    {
        return Response::success($this->recipes->create($this->context($request), $request->json()), 201);
    }

    public function get(Request $request): Response
    {
        return Response::success($this->recipes->get($this->context($request), $this->recipeId($request)));
    }

    public function update(Request $request): Response
    {
        return Response::success($this->recipes->update($this->context($request), $this->recipeId($request), $request->json()));
    }

    public function delete(Request $request): Response
    {
        $this->recipes->delete($this->context($request), $this->recipeId($request));
        return Response::success(['deleted' => true]);
    }

    private function recipeId(Request $request): int
    {
        return $this->positiveInt($request->routeParam('id'), 'id', true);
    }

    private function positiveInt(?string $value, string $field, bool $notFound = false): int
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ApiException($notFound ? 404 : 422, $notFound ? 'RECIPE_NOT_FOUND' : 'VALIDATION_FAILED', $notFound ? 'The recipe was not found.' : 'Query parameters are invalid.', $notFound ? [] : [$field => 'Must be a positive integer.']);
        }
        return (int) $value;
    }

    private function context(Request $request): AuthContext
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        return $context;
    }
}

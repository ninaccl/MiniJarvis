<?php

namespace App\Recipe;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class RecipeController
{
    private $recipes;
    public function __construct(RecipeService $recipes)
    {
        $this->recipes = $recipes;
    }
    public function categories(Request $request)
    {
        return Response::success($this->recipes->categories($this->context($request)));
    }
    public function listItems(Request $request)
    {
        $page = $this->positiveInt($request->query('page', '1'), 'page');
        $pageSize = $this->positiveInt($request->query('page_size', '20'), 'page_size');
        $categoryRaw = $request->query('category_id');
        $categoryId = $categoryRaw === null || $categoryRaw === '' ? null : $this->positiveInt($categoryRaw, 'category_id');
        $result = $this->recipes->listItems($this->context($request), $request->query('q', '') !== null ? $request->query('q', '') : '', $categoryId, $page, $pageSize);
        return Response::success($result['items'], 200, $result['meta']);
    }
    public function create(Request $request)
    {
        return Response::success($this->recipes->create($this->context($request), $request->json()), 201);
    }
    public function get(Request $request)
    {
        return Response::success($this->recipes->get($this->context($request), $this->recipeId($request)));
    }
    public function update(Request $request)
    {
        return Response::success($this->recipes->update($this->context($request), $this->recipeId($request), $request->json()));
    }
    public function delete(Request $request)
    {
        $this->recipes->delete($this->context($request), $this->recipeId($request));
        return Response::success(['deleted' => true]);
    }
    private function recipeId(Request $request)
    {
        return $this->positiveInt($request->routeParam('id'), 'id', true);
    }
    private function positiveInt($value, $field, $notFound = false)
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ApiException($notFound ? 404 : 422, $notFound ? 'RECIPE_NOT_FOUND' : 'VALIDATION_FAILED', $notFound ? 'The recipe was not found.' : 'Query parameters are invalid.', $notFound ? [] : [$field => 'Must be a positive integer.']);
        }
        return (int) $value;
    }
    private function context(Request $request)
    {
        $context = $request->attribute('auth');
        if (!$context instanceof AuthContext) {
            throw new ApiException(401, 'AUTHENTICATION_REQUIRED', 'A valid Bearer token is required.');
        }
        return $context;
    }
}

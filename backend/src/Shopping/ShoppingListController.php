<?php

namespace App\Shopping;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class ShoppingListController
{
    private $shopping;
    public function __construct(ShoppingListService $shopping)
    {
        $this->shopping = $shopping;
    }
    public function create(Request $request)
    {
        return Response::success($this->shopping->generate($this->context($request), $request->json()), 201);
    }
    public function listItems(Request $request)
    {
        $result = $this->shopping->listItems($this->context($request), $this->positiveInt($request->query('page', '1'), 'page'), $this->positiveInt($request->query('page_size', '20'), 'page_size'));
        return Response::success($result['items'], 200, $result['meta']);
    }
    public function get(Request $request)
    {
        return Response::success($this->shopping->get($this->context($request), $this->listId($request)));
    }
    public function check(Request $request)
    {
        return Response::success($this->shopping->check($this->context($request), $this->listId($request), $this->itemId($request), $request->json()));
    }
    public function updateStatus(Request $request)
    {
        return Response::success($this->shopping->updateStatus($this->context($request), $this->listId($request), $request->json()));
    }
    public function stock(Request $request)
    {
        return Response::success($this->shopping->stock($this->context($request), $this->listId($request), $this->itemId($request), $request->json()), 201);
    }
    private function listId(Request $request)
    {
        return $this->positiveInt($request->routeParam('id'), 'id', true);
    }
    private function itemId(Request $request)
    {
        return $this->positiveInt($request->routeParam('item_id'), 'item_id', true);
    }
    private function positiveInt($value, $field, $notFound = false)
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ApiException($notFound ? 404 : 422, $notFound ? 'SHOPPING_RESOURCE_NOT_FOUND' : 'VALIDATION_FAILED', $notFound ? 'The shopping resource was not found.' : 'Query parameters are invalid.', $notFound ? [] : [$field => 'Must be a positive integer.']);
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

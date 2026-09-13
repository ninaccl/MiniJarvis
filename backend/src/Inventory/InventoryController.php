<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;

final class InventoryController
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly RecipeMatcher $matcher,
    ) {
    }

    public function list(Request $request): Response
    {
        return Response::success($this->inventory->list(
            $this->context($request),
            $request->query('q', '') ?? '',
            $request->query('status', 'all') ?? 'all',
        ));
    }

    public function create(Request $request): Response
    {
        return Response::success($this->inventory->create($this->context($request), $request->json()), 201);
    }

    public function update(Request $request): Response
    {
        return Response::success($this->inventory->update($this->context($request), $this->batchId($request), $request->json()));
    }

    public function move(Request $request): Response
    {
        return Response::success($this->inventory->move($this->context($request), $this->batchId($request), $request->json()), 201);
    }

    public function movements(Request $request): Response
    {
        $result = $this->inventory->movements(
            $this->context($request),
            $this->positiveInt($request->query('page', '1'), 'page'),
            $this->positiveInt($request->query('page_size', '20'), 'page_size'),
        );
        return Response::success($result['items'], 200, $result['meta']);
    }

    public function matches(Request $request): Response
    {
        return Response::success($this->matcher->matches(
            $this->context($request),
            $this->positiveInt($request->query('count', '2'), 'count'),
        ));
    }

    private function batchId(Request $request): int
    {
        return $this->positiveInt($request->routeParam('id'), 'id', true);
    }

    private function positiveInt(?string $value, string $field, bool $notFound = false): int
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ApiException(
                $notFound ? 404 : 422,
                $notFound ? 'INVENTORY_BATCH_NOT_FOUND' : 'VALIDATION_FAILED',
                $notFound ? 'The inventory batch was not found.' : 'Query parameters are invalid.',
                $notFound ? [] : [$field => 'Must be a positive integer.'],
            );
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

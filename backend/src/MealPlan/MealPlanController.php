<?php

namespace App\MealPlan;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
final class MealPlanController
{
    private $plans;
    public function __construct(MealPlanService $plans)
    {
        $this->plans = $plans;
    }
    public function listItems(Request $request)
    {
        return Response::success($this->plans->listItems($this->context($request), $request->query('from'), $request->query('to')));
    }
    public function add(Request $request)
    {
        return Response::success($this->plans->add($this->context($request), $request->json()), 201);
    }
    public function update(Request $request)
    {
        return Response::success($this->plans->update($this->context($request), $this->entryId($request), $request->json()));
    }
    public function delete(Request $request)
    {
        $this->plans->delete($this->context($request), $this->entryId($request));
        return Response::success(['deleted' => true]);
    }
    private function entryId(Request $request)
    {
        $value = $request->routeParam('id');
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new ApiException(404, 'MEAL_PLAN_ENTRY_NOT_FOUND', 'The meal-plan entry was not found.');
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

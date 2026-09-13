<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuthController;
use App\Auth\AuthMiddleware;
use App\Household\HouseholdController;
use App\Inventory\InventoryController;
use App\LinkPreview\LinkPreviewController;
use App\MealPlan\MealPlanController;
use App\Notification\NotificationController;
use App\Recipe\RecipeController;
use App\Shopping\ShoppingListController;
use App\Task\TaskController;
use App\Upload\UploadController;

final class Application
{
    public static function routes(
        Router $router,
        AuthController $authController,
        HouseholdController $householdController,
        AuthMiddleware $authMiddleware,
        ?RecipeController $recipeController = null,
        ?UploadController $uploadController = null,
        ?LinkPreviewController $linkPreviewController = null,
        ?InventoryController $inventoryController = null,
        ?MealPlanController $mealPlanController = null,
        ?ShoppingListController $shoppingListController = null,
        ?TaskController $taskController = null,
        ?NotificationController $notificationController = null,
    ): void {
        $router->add('GET', '/api/v1/health', static fn (): Response => Response::success(['status' => 'ok']));
        $router->add('POST', '/api/v1/auth/wechat', $authController->wechat(...));

        $protected = static fn (callable $handler): callable =>
            static fn (Request $request): Response => $authMiddleware->handle($request, $handler);

        $router->add('POST', '/api/v1/households', $protected($householdController->create(...)));
        $router->add('POST', '/api/v1/households/join', $protected($householdController->join(...)));
        $router->add('GET', '/api/v1/households/current', $protected($householdController->current(...)));
        $router->add('POST', '/api/v1/households/invite/reset', $protected($householdController->resetInvite(...)));
        $router->add('DELETE', '/api/v1/households/members/{userId}', $protected($householdController->removeMember(...)));

        if ($recipeController !== null) {
            $router->add('GET', '/api/v1/categories', $protected($recipeController->categories(...)));
            $router->add('GET', '/api/v1/recipes', $protected($recipeController->list(...)));
            $router->add('POST', '/api/v1/recipes', $protected($recipeController->create(...)));
            if ($inventoryController !== null) $router->add('GET', '/api/v1/recipes/matches', $protected($inventoryController->matches(...)));
            $router->add('GET', '/api/v1/recipes/{id}', $protected($recipeController->get(...)));
            $router->add('PATCH', '/api/v1/recipes/{id}', $protected($recipeController->update(...)));
            $router->add('DELETE', '/api/v1/recipes/{id}', $protected($recipeController->delete(...)));
        }
        if ($uploadController !== null) $router->add('POST', '/api/v1/uploads/images', $protected($uploadController->image(...)));
        if ($linkPreviewController !== null) {
            $router->add('POST', '/api/v1/link-previews', $protected($linkPreviewController->create(...)));
            $router->add('GET', '/api/v1/link-previews/{token}/image', $protected($linkPreviewController->image(...)));
            $router->add('POST', '/api/v1/link-previews/{token}/adopt', $protected($linkPreviewController->adopt(...)));
        }
        if ($inventoryController !== null) {
            $router->add('GET', '/api/v1/inventory', $protected($inventoryController->list(...)));
            $router->add('POST', '/api/v1/inventory/batches', $protected($inventoryController->create(...)));
            $router->add('PATCH', '/api/v1/inventory/batches/{id}', $protected($inventoryController->update(...)));
            $router->add('POST', '/api/v1/inventory/batches/{id}/movements', $protected($inventoryController->move(...)));
            $router->add('GET', '/api/v1/inventory/movements', $protected($inventoryController->movements(...)));
        }
        if ($mealPlanController !== null) {
            $router->add('GET', '/api/v1/meal-plan', $protected($mealPlanController->list(...)));
            $router->add('POST', '/api/v1/meal-plan/entries', $protected($mealPlanController->add(...)));
            $router->add('PATCH', '/api/v1/meal-plan/entries/{id}', $protected($mealPlanController->update(...)));
            $router->add('DELETE', '/api/v1/meal-plan/entries/{id}', $protected($mealPlanController->delete(...)));
        }
        if ($shoppingListController !== null) {
            $router->add('POST', '/api/v1/shopping-lists', $protected($shoppingListController->create(...)));
            $router->add('GET', '/api/v1/shopping-lists', $protected($shoppingListController->list(...)));
            $router->add('GET', '/api/v1/shopping-lists/{id}', $protected($shoppingListController->get(...)));
            $router->add('PATCH', '/api/v1/shopping-lists/{id}/items/{item_id}', $protected($shoppingListController->check(...)));
            $router->add('PATCH', '/api/v1/shopping-lists/{id}', $protected($shoppingListController->updateStatus(...)));
            $router->add('POST', '/api/v1/shopping-lists/{id}/items/{item_id}/stock', $protected($shoppingListController->stock(...)));
        }
        if ($taskController !== null) {
            $router->add('GET', '/api/v1/tasks', $protected($taskController->list(...)));
            $router->add('POST', '/api/v1/tasks', $protected($taskController->create(...)));
            $router->add('GET', '/api/v1/tasks/{id}', $protected($taskController->get(...)));
            $router->add('PATCH', '/api/v1/tasks/{id}', $protected($taskController->update(...)));
            $router->add('DELETE', '/api/v1/tasks/{id}', $protected($taskController->delete(...)));
        }
        if ($notificationController !== null) {
            $router->add('GET', '/api/v1/notifications/preferences', $protected($notificationController->preferences(...)));
            $router->add('PATCH', '/api/v1/notifications/preferences', $protected($notificationController->updatePreferences(...)));
            $router->add('POST', '/api/v1/notifications/subscription-grants', $protected($notificationController->grant(...)));
        }
    }
}

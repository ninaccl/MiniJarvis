<?php

declare(strict_types=1);

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$basePrefix = '/backend';
if ($basePrefix !== '/' && str_starts_with($requestUri, $basePrefix)) {
    $requestUri = substr($requestUri, strlen($basePrefix)) ?: '/';
}
if (str_starts_with($requestUri, '/public/index.php')) {
    $requestUri = substr($requestUri, strlen('/public/index.php')) ?: '/';
}
$_SERVER['REQUEST_URI'] = $requestUri;

use App\Auth\AuthController;
use App\Auth\AuthMiddleware;
use App\Auth\AuthService;
use App\Auth\PdoSessionStore;
use App\Auth\PdoUserStore;
use App\Auth\WeChatApiClient;
use App\Config\Config;
use App\Database\Connection;
use App\Household\HouseholdController;
use App\Household\HouseholdService;
use App\Household\PdoHouseholdStore;
use App\Household\TenantGuard;
use App\Http\ApiException;
use App\Http\ApiKernel;
use App\Http\Application;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Inventory\InventoryController;
use App\Inventory\InventoryService;
use App\Inventory\PdoInventoryRepository;
use App\Inventory\RecipeMatcher;
use App\Inventory\SystemInventoryClock;
use App\Inventory\UnitConverter;
use App\LinkPreview\CurlHttpClient;
use App\LinkPreview\DnsCommandFactory;
use App\LinkPreview\LinkPreviewController;
use App\LinkPreview\LinkPreviewService;
use App\LinkPreview\LocalPreviewImageStore;
use App\LinkPreview\PdoLinkPreviewRepository;
use App\LinkPreview\SystemClock;
use App\LinkPreview\SystemDnsResolver;
use App\LinkPreview\UrlSafetyPolicy;
use App\MealPlan\MealPlanController;
use App\MealPlan\MealPlanService;
use App\MealPlan\PdoMealPlanRepository;
use App\Notification\AccessTokenCache;
use App\Notification\NotificationController;
use App\Notification\PdoNotificationRepository;
use App\Notification\ReminderService;
use App\Notification\TemplateConfig;
use App\Notification\WeChatNotificationClient;
use App\Recipe\PdoRecipeRepository;
use App\Recipe\RecipeController;
use App\Recipe\RecipeService;
use App\Shopping\PdoShoppingListRepository;
use App\Shopping\ShoppingListController;
use App\Shopping\ShoppingListService;
use App\Task\PdoTaskRepository;
use App\Task\TaskController;
use App\Task\TaskService;
use App\Upload\UploadController;
use App\Upload\UploadedFileMover;
use App\Upload\UploadService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

try {
    $config = Config::fromEnvironment();
    $config->calendarTimezone();
    $connection = Connection::fromConfig($config);
    $pdo = $connection->pdo();
    $users = new PdoUserStore($pdo);
    $sessions = new PdoSessionStore($pdo);
    $households = new PdoHouseholdStore($pdo);
    $auth = new AuthService(
        $config->environment(),
        $users,
        $sessions,
        new WeChatApiClient(
            $config->get('WECHAT_APP_ID', ''),
            $config->get('WECHAT_APP_SECRET', ''),
        ),
    );
    $householdService = new HouseholdService($connection, $households, new TenantGuard());
    $guard = new TenantGuard();
    $recipeRepository = new PdoRecipeRepository($pdo);
    $recipeService = new RecipeService($connection, $recipeRepository, $guard);
    $inventoryRepository = new PdoInventoryRepository($pdo);
    $inventoryClock = new SystemInventoryClock($config->calendarTimezone());
    $unitConverter = new UnitConverter($recipeRepository);
    $inventoryService = new InventoryService($connection, $inventoryRepository, $recipeRepository, $unitConverter, $inventoryClock, $guard);
    $inventoryController = new InventoryController(
        $inventoryService,
        new RecipeMatcher($inventoryRepository, $recipeRepository, $unitConverter, $inventoryClock, $guard),
    );
    $mealPlanRepository = new PdoMealPlanRepository($pdo);
    $mealPlanController = new MealPlanController(new MealPlanService($connection, $mealPlanRepository, $recipeRepository, $guard));
    $shoppingRepository = new PdoShoppingListRepository($pdo);
    $shoppingController = new ShoppingListController(new ShoppingListService(
        $connection, $shoppingRepository, $mealPlanRepository, $recipeRepository, $inventoryRepository,
        $inventoryService, $unitConverter, $inventoryClock, $guard,
    ));
    $notificationRepository = new PdoNotificationRepository($pdo);
    $tokenCachePath = $root . '/' . ltrim($config->get('WECHAT_TOKEN_CACHE_FILE', 'var/wechat-access-token.json'), '/');
    $notificationSender = new WeChatNotificationClient(
        $config->get('WECHAT_APP_ID', ''), $config->get('WECHAT_APP_SECRET', ''),
        new AccessTokenCache($tokenCachePath), new TemplateConfig($config),
    );
    $reminderService = new ReminderService($connection, $notificationRepository, $notificationSender, $guard);
    $taskController = new TaskController(new TaskService(
        $connection, new PdoTaskRepository($pdo), $households, $notificationRepository, $guard,
    ));
    $notificationController = new NotificationController($reminderService);
    $publicRoot = $root . '/' . ltrim($config->get('PUBLIC_ROOT', 'public'), '/');
    $uploadPrefix = $config->get('UPLOAD_PUBLIC_PREFIX', '/uploads');
    $uploadService = new UploadService($publicRoot . '/uploads', $uploadPrefix, new UploadedFileMover());
    $pageHosts = array_values(array_filter(array_map('trim', explode(',', $config->get('LINK_PREVIEW_ALLOWED_HOSTS', 'douyin.com,v.douyin.com,bilibili.com,b23.tv,xiaohongshu.com,xhslink.com')))));
    $cdnHosts = array_values(array_filter(array_map('trim', explode(',', $config->get('LINK_PREVIEW_CDN_HOSTS', implode(',', $pageHosts))))));
    $previewRoot = $root . '/' . ltrim($config->get('PREVIEW_ROOT', 'var/link-previews'), '/');
    $previewService = new LinkPreviewService(
        $connection,
        new PdoLinkPreviewRepository($pdo),
        new UrlSafetyPolicy(new SystemDnsResolver(new DnsCommandFactory($config->get('PHP_CLI_BINARY', PHP_BINDIR . '/php'))), $pageHosts, $cdnHosts),
        new CurlHttpClient(),
        new LocalPreviewImageStore($previewRoot, $publicRoot . '/uploads', $uploadPrefix),
        new SystemClock(),
        $guard,
    );
    $router = new Router();
    Application::routes(
        $router,
        new AuthController($auth),
        new HouseholdController($householdService),
        new AuthMiddleware($auth),
        new RecipeController($recipeService),
        new UploadController($uploadService, $guard),
        new LinkPreviewController($previewService),
        $inventoryController,
        $mealPlanController,
        $shoppingController,
        $taskController,
        $notificationController,
    );
    (new ApiKernel($router))->handle(Request::fromGlobals())->send();
} catch (ApiException $exception) {
    Response::fromException($exception)->send();
} catch (Throwable $exception) {
    error_log(sprintf('%s: %s', $exception::class, $exception->getMessage()));
    Response::internalError()->send();
}
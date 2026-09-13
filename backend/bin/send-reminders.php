<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Connection;
use App\Household\TenantGuard;
use App\Notification\AccessTokenCache;
use App\Notification\PdoNotificationRepository;
use App\Notification\ReminderService;
use App\Notification\TemplateConfig;
use App\Notification\WeChatNotificationClient;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
if (is_file($root . '/.env')) Dotenv\Dotenv::createImmutable($root)->safeLoad();

try {
    $config = Config::fromEnvironment();
    $connection = Connection::fromConfig($config);
    $repository = new PdoNotificationRepository($connection->pdo());
    $cachePath = $root . '/' . ltrim($config->get('WECHAT_TOKEN_CACHE_FILE', 'var/wechat-access-token.json'), '/');
    $sender = new WeChatNotificationClient(
        $config->get('WECHAT_APP_ID', ''), $config->get('WECHAT_APP_SECRET', ''),
        new AccessTokenCache($cachePath), new TemplateConfig($config),
    );
    $service = new ReminderService($connection, $repository, $sender, new TenantGuard());
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $materialized = $connection->transaction(fn (): int => $service->materializeExpiry($now));
    $stats = $service->deliverDue($now);
    fwrite(STDOUT, json_encode(['materialized' => $materialized] + $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    error_log($exception::class . ': ' . $exception->getMessage());
    fwrite(STDERR, "Reminder run failed.\n");
    exit(1);
}

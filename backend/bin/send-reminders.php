<?php

use App\Config\Config;
use App\Database\Connection;
use App\Household\TenantGuard;
use App\Notification\AccessTokenCache;
use App\Notification\PdoNotificationRepository;
use App\Notification\ReminderService;
use App\Notification\TemplateConfig;
use App\Notification\WeChatNotificationClient;
use App\Support\Compat;

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

try {
    $config = Config::fromEnvironment();
    $connection = Connection::fromConfig($config);
    $repository = new PdoNotificationRepository($connection->pdo());
    $cachePath = $root . '/' . ltrim($config->get('WECHAT_TOKEN_CACHE_FILE', 'var/wechat-access-token.json'), '/');
    $sender = new WeChatNotificationClient(
        $config->get('WECHAT_APP_ID', ''), $config->get('WECHAT_APP_SECRET', ''),
        new AccessTokenCache($cachePath), new TemplateConfig($config)
    );
    $service = new ReminderService($connection, $repository, $sender, new TenantGuard());
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $materialized = $connection->transaction(function () use ($service, $now) { return $service->materializeExpiry($now); });
    $stats = $service->deliverDue($now);
    fwrite(STDOUT, Compat::jsonEncode(array('materialized' => $materialized) + $stats) . PHP_EOL);
} catch (Exception $exception) {
    error_log(get_class($exception) . ': ' . $exception->getMessage());
    fwrite(STDERR, "Reminder run failed.\n");
    exit(1);
}

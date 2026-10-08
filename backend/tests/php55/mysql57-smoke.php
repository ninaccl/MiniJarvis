<?php

require dirname(dirname(__DIR__)) . '/bootstrap.php';

$config = App\Config\Config::fromEnvironment();
$connection = App\Database\Connection::fromConfig($config);
$pdo = $connection->pdo();
$pdo->beginTransaction();
try {
    $nonce = bin2hex(App\Support\Compat::randomBytes(8));
    $user = (new App\Auth\PdoUserStore($pdo))->upsertByOpenId('php55-smoke-' . $nonce, '中文测试', null);
    $household = (new App\Household\PdoHouseholdStore($pdo))->create('PHP 5.5 smoke', $user['id'], hash('sha256', $nonce));
    $repository = new App\Notification\PdoNotificationRepository($pdo);
    $repository->upsertJob($household, $user['id'], 'php55-smoke-' . $nonce, 'inventory_expiry', '2000-01-01 00:00:00', array('summary' => '中文通知'));
    $job = $repository->claimDueJob('2099-01-01 00:00:00');
    if (!is_array($job) || $job['payload']['summary'] !== '中文通知') {
        throw new RuntimeException('MySQL 5.7 notification JSON claim failed.');
    }
    fwrite(STDOUT, "MySQL 5.7 smoke passed\n");
} catch (Exception $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->rollBack();
}

<?php

require dirname(dirname(__DIR__)) . '/bootstrap.php';

function sameCore55($actual, $expected, $label)
{
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

class CoreUserStore55 implements App\Auth\UserStore
{
    public function upsertByOpenId($openId, $nickname, $avatarUrl)
    {
        return array('id' => 5, 'openid' => $openId, 'nickname' => $nickname, 'avatar_url' => $avatarUrl);
    }
}

class CoreSessionStore55 implements App\Auth\SessionStore
{
    public $hash;
    public function create($userId, $tokenHash, DateTimeImmutable $expiresAt)
    {
        $this->hash = $tokenHash;
    }
    public function findActiveContext($tokenHash, DateTimeImmutable $now)
    {
        if ($tokenHash !== $this->hash) return null;
        return new App\Auth\AuthContext(5, 7, 'owner');
    }
}

class CoreWeChat55 implements App\Auth\WeChatGateway
{
    public function exchangeCode($code)
    {
        return new App\Auth\WeChatIdentity('wx-id');
    }
}

$router = new App\Http\Router();
$router->add('GET', '/api/v1/health', function () {
    return App\Http\Response::success(array('status' => 'ok'));
});
$kernel = new App\Http\ApiKernel($router);
$response = $kernel->handle(new App\Http\Request('GET', '/api/v1/health'));
sameCore55($response->status(), 200, 'health status');
sameCore55($response->payload()['data']['status'], 'ok', 'health payload');

$sessions = new CoreSessionStore55();
$auth = new App\Auth\AuthService('local', new CoreUserStore55(), $sessions, new CoreWeChat55());
$login = $auth->login('dev:alice', null, null);
sameCore55(strlen($login['access_token']), 64, 'login token length');
sameCore55($auth->authenticateToken($login['access_token'])->householdId, 7, 'valid token');

try {
    $auth->authenticateToken(str_repeat('0', 64));
    fwrite(STDERR, "expired token: expected 401\n");
    exit(1);
} catch (App\Http\ApiException $exception) {
    sameCore55($exception->status(), 401, 'expired token');
}

$guard = new App\Household\TenantGuard();
try {
    $guard->requireTenant(new App\Auth\AuthContext(5, 7, 'owner'), 8);
    fwrite(STDERR, "tenant isolation: expected 404\n");
    exit(1);
} catch (App\Http\ApiException $exception) {
    sameCore55($exception->status(), 404, 'tenant isolation');
}

fwrite(STDOUT, "core smoke passed\n");

<?php

require dirname(dirname(__DIR__)) . '/bootstrap.php';

function assertDomain55($condition, $label)
{
    if (!$condition) {
        fwrite(STDERR, "$label failed\n");
        exit(1);
    }
}

class StaticDns55 implements App\LinkPreview\DnsResolver
{
    public $addresses = array('8.8.8.8');
    public function resolve($host, $timeoutMilliseconds)
    {
        return $this->addresses;
    }
}

assertDomain55(App\Recipe\SearchPattern::contains('a%_\\b') === '%a\%\_\\\\b%', 'literal recipe search');
assertDomain55(App\Recipe\DecimalQuantity::forStorage(1.25) === '1.25', 'recipe decimal');
assertDomain55(App\Inventory\Quantity::compare('2', '1') === 1, 'inventory comparison');
assertDomain55(App\Inventory\Quantity::add('1.25', '2.5') === '3.75', 'inventory addition');

$dns = new StaticDns55();
$policy = new App\LinkPreview\UrlSafetyPolicy($dns);
$valid = $policy->validatePage('https://www.bilibili.com/video/abc#section', 1000);
assertDomain55($valid->url === 'https://www.bilibili.com/video/abc', 'safe URL normalization');
foreach (array('https://127.0.0.1/', 'https://evil.example/') as $url) {
    try {
        $policy->validatePage($url, 1000);
        assertDomain55(false, 'unsafe host');
    } catch (App\Http\ApiException $expected) {
    }
}
$dns->addresses = array('10.0.0.1');
try {
    $policy->validatePage('https://www.bilibili.com/', 1000);
    assertDomain55(false, 'private DNS address');
} catch (App\Http\ApiException $expected) {
}

$systemDns = new App\LinkPreview\SystemDnsResolver(new App\LinkPreview\DnsCommandFactory(PHP_BINARY));
assertDomain55(is_array($systemDns->resolve('localhost', 2000)), 'DNS resolver');

$config = new App\Config\Config(array('WECHAT_TASK_DUE_TEMPLATE_ID' => 'test-template'));
$template = new App\Notification\TemplateConfig($config);
$message = $template->message(array('job_type' => 'task_due', 'payload' => array('title' => str_repeat('菜', 21), 'due_at' => '2026-10-07 12:00:00')));
assertDomain55(App\Support\Text::length($message['data']['thing1']['value']) === 20, 'UTF-8 reminder title');

fwrite(STDOUT, "domain smoke passed\n");

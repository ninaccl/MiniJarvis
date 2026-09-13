<?php

declare(strict_types=1);

namespace Tests\LinkPreview;

use App\Http\ApiException;
use App\LinkPreview\UrlSafetyPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Support\MapDnsResolver;

final class UrlSafetyPolicyTest extends TestCase
{
    public function testAllowsExactHostAndTrueSubdomainButRejectsLookalike(): void
    {
        $dns = new MapDnsResolver([
            'bilibili.com' => ['93.184.216.34'],
            'www.bilibili.com' => ['93.184.216.34'],
            'evilbilibili.com' => ['93.184.216.34'],
        ]);
        $policy = new UrlSafetyPolicy($dns);

        self::assertSame('bilibili', $policy->validatePage('https://bilibili.com/video/1', 750)->platform);
        self::assertSame('bilibili', $policy->validatePage('https://www.bilibili.com/video/1', 500)->platform);
        self::assertSame([750, 500], $dns->budgets);
        $this->assertRejected(fn () => $policy->validatePage('https://evilbilibili.com/video/1', 100));
        $this->assertRejected(fn () => $policy->validatePage('http://bilibili.com/video/1', 100));
    }

    public function testRejectsAnyPrivateIpv4OrIpv6DnsAnswer(): void
    {
        $policy = new UrlSafetyPolicy(new MapDnsResolver([
            'bilibili.com' => ['93.184.216.34', '10.0.0.7'],
            'b23.tv' => ['2001:db8::1'],
            'xhslink.com' => ['fc00::1234'],
            'douyin.com' => ['::1'],
            'xiaohongshu.com' => ['3fff::1'],
        ]));

        foreach (['https://bilibili.com/x', 'https://b23.tv/x', 'https://xhslink.com/x', 'https://douyin.com/x', 'https://xiaohongshu.com/x'] as $url) {
            $this->assertRejected(fn () => $policy->validatePage($url, 100));
        }
    }

    public function testConfigurableAllowlistCanNarrowOrExtendHosts(): void
    {
        $policy = new UrlSafetyPolicy(new MapDnsResolver([
            'recipes.example' => ['2606:4700:4700::1111'],
            'bilibili.com' => ['93.184.216.34'],
        ]), ['recipes.example']);

        self::assertSame('other', $policy->validatePage('https://recipes.example/post', 100)->platform);
        $this->assertRejected(fn () => $policy->validatePage('https://bilibili.com/post', 100));
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
            self::fail('Expected URL to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('UNSAFE_URL', $exception->errorCode());
        }
    }
}

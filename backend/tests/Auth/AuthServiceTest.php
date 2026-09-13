<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Auth\AuthService;
use App\Http\ApiException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWeChatGateway;
use Tests\Support\InMemorySessionStore;
use Tests\Support\InMemoryUserStore;

final class AuthServiceTest extends TestCase
{
    public function testDevCodeIsRejectedOutsideLocalEnvironment(): void
    {
        $service = new AuthService(
            'production',
            new InMemoryUserStore(),
            new InMemorySessionStore(),
            new FakeWeChatGateway(),
        );

        try {
            $service->login('dev:alice', null, null);
            self::fail('Expected the local-only code to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('AUTH_INVALID_CODE', $exception->errorCode());
        }
    }

    public function testLocalLoginStoresOnlyTokenHashAndThirtyDayExpiry(): void
    {
        $sessions = new InMemorySessionStore();
        $before = new DateTimeImmutable('now');
        $service = new AuthService(
            'local',
            new InMemoryUserStore(),
            $sessions,
            new FakeWeChatGateway(),
        );

        $result = $service->login('dev:alice', 'Alice', 'https://example.test/a.png');
        $after = new DateTimeImmutable('now');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['access_token']);
        self::assertCount(1, $sessions->sessions);
        self::assertSame(hash('sha256', $result['access_token']), $sessions->sessions[0]['token_hash']);
        self::assertNotSame($result['access_token'], $sessions->sessions[0]['token_hash']);
        self::assertGreaterThanOrEqual($before->modify('+30 days')->getTimestamp(), $sessions->sessions[0]['expires_at']->getTimestamp());
        self::assertLessThanOrEqual($after->modify('+30 days')->getTimestamp(), $sessions->sessions[0]['expires_at']->getTimestamp());
        self::assertSame('dev:alice', $result['user']['openid']);
    }

    public function testDevStableIdFitsOpenIdColumnBoundary(): void
    {
        $service = new AuthService(
            'local',
            new InMemoryUserStore(),
            new InMemorySessionStore(),
            new FakeWeChatGateway(),
        );

        $accepted = $service->login('dev:' . str_repeat('a', 124), null, null);
        self::assertSame(128, strlen($accepted['user']['openid']));

        try {
            $service->login('dev:' . str_repeat('a', 125), null, null);
            self::fail('Expected an openid longer than 128 bytes to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('AUTH_INVALID_CODE', $exception->errorCode());
        }
    }

    public function testNicknameLimitCountsUnicodeCharacters(): void
    {
        $service = new AuthService(
            'local',
            new InMemoryUserStore(),
            new InMemorySessionStore(),
            new FakeWeChatGateway(),
        );

        $accepted = $service->login('dev:unicode', str_repeat('家', 255), null);
        self::assertSame(str_repeat('家', 255), $accepted['user']['nickname']);

        try {
            $service->login('dev:unicode', str_repeat('家', 256), null);
            self::fail('Expected a 256-character nickname to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('VALIDATION_FAILED', $exception->errorCode());
        }
    }
}

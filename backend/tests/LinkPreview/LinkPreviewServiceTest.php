<?php

declare(strict_types=1);

namespace Tests\LinkPreview;

use App\Auth\AuthContext;
use App\Http\ApiException;
use App\Http\Request;
use App\LinkPreview\DnsLookupTimedOut;
use App\LinkPreview\DnsResolver;
use App\LinkPreview\Clock;
use App\LinkPreview\HttpClient;
use App\LinkPreview\HttpResponse;
use App\LinkPreview\LinkPreviewRepository;
use App\LinkPreview\LinkPreviewController;
use App\LinkPreview\LinkPreviewService;
use App\LinkPreview\PreviewImageStore;
use App\LinkPreview\UrlSafetyPolicy;
use App\Household\TenantGuard;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use App\Database\TransactionManager;
use RuntimeException;
use Tests\Support\InMemoryTransactionManager;
use Tests\Support\MapDnsResolver;

final class LinkPreviewServiceTest extends TestCase
{
    public function testForbiddenRedirectIsRejectedBeforeItIsFollowed(): void
    {
        $http = new QueueHttpClient([
            new HttpResponse(302, ['location' => 'https://evil.example/steal'], '', 'text/html'),
        ]);
        $service = $this->service($http);

        $this->assertApiError(fn () => $service->preview($this->context(), 'https://b23.tv/abc'), 'UNSAFE_URL', 422);
        self::assertCount(1, $http->requestedUrls);
    }

    public function testResponseCapAndUnparseablePageFailGracefully(): void
    {
        $oversize = $this->service(new QueueHttpClient([
            new HttpResponse(200, [], str_repeat('x', 1024 * 1024 + 1), 'text/html'),
        ]))->preview($this->context(), 'https://b23.tv/large');
        self::assertFalse($oversize['available']);
        self::assertSame('response_too_large', $oversize['reason']);

        $blocked = $this->service(new QueueHttpClient([
            new HttpResponse(200, [], '<html><body>blocked</body></html>', 'text/html'),
        ]))->preview($this->context(), 'https://b23.tv/blocked');
        self::assertFalse($blocked['available']);
        self::assertSame('unparseable', $blocked['reason']);
        self::assertSame('bilibili', $blocked['platform']);
    }

    public function testOpenGraphMetadataWinsAndImageReceivesSameSafetyChecks(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $repository = new MemoryPreviewRepository();
        $store = new MemoryImageStore();
        $http = new QueueHttpClient([
            new HttpResponse(200, [], '<html><head><title>Fallback</title><meta property="og:title" content="OG title"><meta content="https://img.bilibili.com/c.png" property="og:image"></head></html>', 'text/html'),
            new HttpResponse(200, [], $png, 'image/png'),
        ]);
        $service = $this->service($http, $repository, $store);

        $result = $service->preview($this->context(), 'https://bilibili.com/video/BV1#fragment');

        self::assertTrue($result['available']);
        self::assertSame('OG title', $result['title']);
        self::assertSame('https://bilibili.com/video/BV1', $result['normalized_url']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $repository->rows[1]['token_hash']);
        self::assertNotSame($result['token'], $repository->rows[1]['token_hash']);
        self::assertSame(['https://bilibili.com/video/BV1', 'https://img.bilibili.com/c.png'], $http->requestedUrls);
    }

    public function testTemporaryImageIsServedOnlyToCreatorWithPrivateMimeHeaders(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $repository = new MemoryPreviewRepository();
        $store = new MemoryImageStore();
        $service = $this->service(new QueueHttpClient([
            new HttpResponse(200, [], '<meta property="og:title" content="Dish"><meta property="og:image" content="https://img.bilibili.com/c.png">', 'text/html'),
            new HttpResponse(200, [], $png, 'image/png'),
        ]), $repository, $store);
        $preview = $service->preview($this->context(), 'https://bilibili.com/video/BV1');

        self::assertSame('/api/v1/link-previews/' . $preview['token'] . '/image', $preview['image_url']);
        self::assertStringNotContainsString('temp.png', json_encode($preview, JSON_THROW_ON_ERROR));
        $request = new Request('GET', $preview['image_url'], [], [], null, ['auth' => $this->context()], ['token' => $preview['token']]);
        $response = (new LinkPreviewController($service))->image($request);
        self::assertSame($png, $response->body());
        self::assertSame('image/png', $response->headers()['Content-Type']);
        self::assertSame('private, max-age=300', $response->headers()['Cache-Control']);
        $this->assertApiError(fn () => $service->image(new AuthContext(11, 1, 'member'), $preview['token']), 'LINK_PREVIEW_NOT_FOUND', 404);
    }

    public function testAdoptionIsOwnerScopedExpiresAndCanOnlyHappenOnce(): void
    {
        $repository = new MemoryPreviewRepository();
        $store = new MemoryImageStore();
        $service = $this->service(new QueueHttpClient([]), $repository, $store);
        $token = str_repeat('a', 64);
        $repository->rows[1] = $this->previewRow(hash('sha256', $token));
        $store->temporary['2026/09/temp.png'] = 'png bytes';

        $this->assertApiError(fn () => $service->adopt(new AuthContext(11, 1, 'member'), $token), 'LINK_PREVIEW_NOT_FOUND', 404);
        $result = $service->adopt($this->context(), $token);
        self::assertSame('/uploads/2026/09/adopted.png', $result['cover_url']);
        $this->assertApiError(fn () => $service->adopt($this->context(), $token), 'LINK_PREVIEW_UNAVAILABLE', 409);

        $expiredToken = str_repeat('b', 64);
        $repository->rows[2] = $this->previewRow(hash('sha256', $expiredToken), '2026-09-12T23:59:59+00:00');
        $this->assertApiError(fn () => $service->adopt($this->context(), $expiredToken), 'LINK_PREVIEW_UNAVAILABLE', 409);
    }

    public function testCommitFailureCompensatesPermanentCopyAndLeavesTemporaryRetryable(): void
    {
        $repository = new MemoryPreviewRepository();
        $store = new MemoryImageStore();
        $token = str_repeat('c', 64);
        $repository->rows[1] = $this->previewRow(hash('sha256', $token));
        $store->temporary['2026/09/temp.png'] = 'png bytes';
        $transactions = new FailCommitOnceTransactionManager($repository);
        $service = $this->service(new QueueHttpClient([]), $repository, $store, null, $transactions);

        try {
            $service->adopt($this->context(), $token);
            self::fail('Expected simulated commit failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('commit failed', $exception->getMessage());
        }
        self::assertArrayHasKey('2026/09/temp.png', $store->temporary);
        self::assertSame([], $store->permanent);
        self::assertNull($repository->rows[1]['adopted_at']);

        self::assertSame('/uploads/2026/09/adopted.png', $service->adopt($this->context(), $token)['cover_url']);
        self::assertSame([], $store->temporary);
        self::assertSame(['2026/09/adopted.png' => 'png bytes'], $store->permanent);
    }

    public function testDatabaseExpiryIsParsedAsUtcUnderNonUtcProcessTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        try {
            $repository = new MemoryPreviewRepository();
            $store = new MemoryImageStore();
            $token = str_repeat('d', 64);
            $repository->rows[1] = $this->previewRow(hash('sha256', $token), '2026-09-13 00:30:00.000000');
            $store->temporary['2026/09/temp.png'] = 'png bytes';
            $result = $this->service(new QueueHttpClient([]), $repository, $store)->image($this->context(), $token);
            self::assertSame('png bytes', $result['bytes']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testTitleIsWhitespaceNormalizedAndCappedBeforePersistence(): void
    {
        $repository = new MemoryPreviewRepository();
        $title = "  " . str_repeat('菜', 513) . "\n name  ";
        $result = $this->service(new QueueHttpClient([
            new HttpResponse(200, [], '<meta property="og:title" content="' . $title . '">', 'text/html'),
        ]), $repository)->preview($this->context(), 'https://bilibili.com/video/BV1');

        self::assertSame(512, mb_strlen($result['title'], 'UTF-8'));
        self::assertSame($result['title'], $repository->rows[1]['title']);
        self::assertStringNotContainsString("\n", $result['title']);
    }

    public function testPreviewCreateFailureDeletesStoredTemporaryImage(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $repository = new MemoryPreviewRepository();
        $repository->failCreate = true;
        $store = new MemoryImageStore();
        $service = $this->service(new QueueHttpClient([
            new HttpResponse(200, [], '<meta property="og:title" content="Dish"><meta property="og:image" content="https://img.bilibili.com/c.png">', 'text/html'),
            new HttpResponse(200, [], $png, 'image/png'),
        ]), $repository, $store);

        try {
            $service->preview($this->context(), 'https://bilibili.com/video/BV1');
            self::fail('Expected preview persistence failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('preview create failed', $exception->getMessage());
        }
        self::assertSame([], $store->temporary);
    }

    public function testDnsTimeoutIsGracefulAndReceivesTotalDeadlineBudget(): void
    {
        $result = $this->service(new QueueHttpClient([]), null, null, new TimeoutDnsResolver())->preview($this->context(), 'https://bilibili.com/video/BV1');
        self::assertFalse($result['available']);
        self::assertSame('fetch_failed', $result['reason']);
        self::assertSame('bilibili', $result['platform']);
    }

    private function service(
        QueueHttpClient $http,
        ?MemoryPreviewRepository $repository = null,
        ?MemoryImageStore $store = null,
        ?DnsResolver $dns = null,
        ?TransactionManager $transactions = null,
    ): LinkPreviewService
    {
        $dns ??= new MapDnsResolver([
            'b23.tv' => ['93.184.216.34'],
            'bilibili.com' => ['93.184.216.34'],
            'img.bilibili.com' => ['93.184.216.34'],
        ]);
        return new LinkPreviewService(
            $transactions ?? new InMemoryTransactionManager(),
            $repository ?? new MemoryPreviewRepository(),
            new UrlSafetyPolicy($dns),
            $http,
            $store ?? new MemoryImageStore(),
            new FixedClock(new DateTimeImmutable('2026-09-13T00:00:00+00:00')),
            new TenantGuard(),
        );
    }

    /** @return array<string,mixed> */
    private function previewRow(string $hash, string $expires = '2026-09-14T00:00:00+00:00'): array
    {
        return ['id' => count([$hash]), 'household_id' => 1, 'user_id' => 10, 'token_hash' => $hash, 'temp_image_path' => '2026/09/temp.png', 'image_mime_type' => 'image/png', 'expires_at' => $expires, 'adopted_at' => null];
    }

    private function context(): AuthContext
    {
        return new AuthContext(10, 1, 'member');
    }

    private function assertApiError(callable $action, string $code, int $status): void
    {
        try {
            $action();
            self::fail('Expected ' . $code);
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status());
            self::assertSame($code, $exception->errorCode());
        }
    }
}

final class QueueHttpClient implements HttpClient
{
    /** @var list<string> */
    public array $requestedUrls = [];
    /** @param list<HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function get(string $url, array $resolvedAddresses, int $connectionTimeoutMilliseconds, int $totalTimeoutMilliseconds, int $bodyLimit): HttpResponse
    {
        $this->requestedUrls[] = $url;
        return array_shift($this->responses) ?? new HttpResponse(500, [], '', 'text/plain');
    }
}

final class MemoryPreviewRepository implements LinkPreviewRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public bool $failCreate = false;

    public function create(array $preview): int
    {
        if ($this->failCreate) throw new RuntimeException('preview create failed');
        $id = count($this->rows) + 1;
        $this->rows[$id] = ['id' => $id, 'adopted_at' => null, 'image_url' => null] + $preview;
        return $id;
    }

    public function findForAdoption(string $tokenHash, int $householdId, int $userId): ?array
    {
        foreach ($this->rows as $row) if ($row['token_hash'] === $tokenHash && $row['household_id'] === $householdId && $row['user_id'] === $userId) return $row;
        return null;
    }

    public function findForImage(string $tokenHash, int $householdId, int $userId): ?array
    {
        foreach ($this->rows as $row) if ($row['token_hash'] === $tokenHash && $row['household_id'] === $householdId && $row['user_id'] === $userId) return $row;
        return null;
    }

    public function markAdopted(int $id, string $adoptedAt, string $publicUrl): bool
    {
        if ($this->rows[$id]['adopted_at'] !== null) return false;
        $this->rows[$id]['adopted_at'] = $adoptedAt;
        $this->rows[$id]['image_url'] = $publicUrl;
        return true;
    }

    public function clearTemporaryPath(int $id): void
    {
        $this->rows[$id]['temp_image_path'] = null;
    }
}

final class MemoryImageStore implements PreviewImageStore
{
    /** @var array<string,string> */
    public array $temporary = [];
    /** @var array<string,string> */
    public array $permanent = [];

    public function storeTemporary(string $bytes, string $extension): array
    {
        $path = '2026/09/temp.' . $extension;
        $this->temporary[$path] = $bytes;
        return ['path' => $path];
    }

    public function readTemporary(string $temporaryPath): string
    {
        return $this->temporary[$temporaryPath];
    }

    public function stageAdoption(string $temporaryPath): array
    {
        $path = '2026/09/adopted.png';
        $this->permanent[$path] = $this->temporary[$temporaryPath];
        return ['path' => $path, 'url' => '/uploads/' . $path];
    }

    public function deleteTemporary(string $temporaryPath): void
    {
        unset($this->temporary[$temporaryPath]);
    }

    public function removePermanent(string $permanentPath): void
    {
        unset($this->permanent[$permanentPath]);
    }
}

final class FailCommitOnceTransactionManager implements TransactionManager
{
    private bool $fail = true;
    public function __construct(private readonly MemoryPreviewRepository $repository)
    {
    }
    public function transaction(callable $callback): mixed
    {
        $snapshot = $this->repository->rows;
        $result = $callback();
        if ($this->fail) {
            $this->fail = false;
            $this->repository->rows = $snapshot;
            throw new RuntimeException('commit failed');
        }
        return $result;
    }
}

final class TimeoutDnsResolver implements DnsResolver
{
    public function resolve(string $host, int $timeoutMilliseconds): array
    {
        self::assertPositiveBudget($timeoutMilliseconds);
        throw new DnsLookupTimedOut();
    }

    private static function assertPositiveBudget(int $budget): void
    {
        if ($budget < 1 || $budget > 8000) throw new RuntimeException('invalid DNS budget');
    }
}

final class FixedClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

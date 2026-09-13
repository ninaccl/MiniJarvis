<?php

declare(strict_types=1);

namespace App\LinkPreview;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use finfo;
use Throwable;
use App\Support\Text;

final class LinkPreviewService
{
    private const PAGE_LIMIT = 1024 * 1024;
    private const IMAGE_LIMIT = 5 * 1024 * 1024;
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly LinkPreviewRepository $previews,
        private readonly UrlSafetyPolicy $urls,
        private readonly HttpClient $http,
        private readonly PreviewImageStore $images,
        private readonly Clock $clock,
        private readonly TenantGuard $guard,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(AuthContext $context, string $url): array
    {
        $householdId = $this->guard->requireMembership($context);
        $deadline = hrtime(true) + 8_000_000_000;
        try {
            $initial = $this->urls->validatePage($url, $this->remainingMilliseconds($deadline));
            [$page, $final] = $this->fetch($initial, false, self::PAGE_LIMIT, $deadline);
        } catch (DnsResolutionFailed $exception) {
            return ['available' => false, 'platform' => $exception->platform, 'normalized_url' => $exception->normalizedUrl, 'reason' => 'fetch_failed'];
        } catch (ApiException $exception) {
            throw $exception;
        } catch (ResponseTooLarge) {
            return $this->unavailable($initial, 'response_too_large');
        } catch (Throwable) {
            return $this->unavailable($initial, 'fetch_failed');
        }
        if (strlen($page->body) > self::PAGE_LIMIT) return $this->unavailable($initial, 'response_too_large');
        if ($page->status < 200 || $page->status >= 300) return $this->unavailable($initial, 'fetch_failed');
        $metadata = $this->parseHtml($page->body);
        if ($metadata['title'] === null) return $this->unavailable($initial, 'unparseable');
        $metadata['title'] = $this->normalizeTitle($metadata['title']);

        $temporary = null;
        if ($metadata['image'] !== null) {
            $imageUrl = $this->absoluteUrl($final->url, $metadata['image']);
            try {
                $validatedImage = $this->urls->validateImage($imageUrl, $this->remainingMilliseconds($deadline));
                [$image] = $this->fetch($validatedImage, true, self::IMAGE_LIMIT, $deadline);
                if ($image->status >= 200 && $image->status < 300 && strlen($image->body) <= self::IMAGE_LIMIT) {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($image->body);
                    if (is_string($mime) && isset(self::IMAGE_TYPES[$mime])) $temporary = $this->images->storeTemporary($image->body, self::IMAGE_TYPES[$mime]);
                }
            } catch (ApiException $exception) {
                throw $exception;
            } catch (Throwable) {
                $temporary = null;
            }
        }

        $token = bin2hex(random_bytes(32));
        $previewImageUrl = $temporary === null ? null : '/api/v1/link-previews/' . $token . '/image';
        $now = $this->clock->now();
        try {
            $this->previews->create([
                'household_id' => $householdId,
                'user_id' => $context->userId,
                'token_hash' => hash('sha256', $token),
                'url_hash' => hash('sha256', $final->url),
                'platform' => $initial->platform,
                'normalized_url' => $final->url,
                'title' => $metadata['title'],
                'image_url' => $previewImageUrl,
                'temp_image_path' => $temporary['path'] ?? null,
                'image_mime_type' => $temporary === null ? null : $mime,
                'fetched_at' => $this->databaseTime($now),
                'expires_at' => $this->databaseTime($now->add(new DateInterval('PT24H'))),
            ]);
        } catch (Throwable $exception) {
            if ($temporary !== null) {
                try { $this->images->deleteTemporary($temporary['path']); } catch (Throwable) {}
            }
            throw $exception;
        }
        return [
            'available' => true, 'platform' => $initial->platform, 'normalized_url' => $final->url,
            'title' => $metadata['title'], 'image_url' => $previewImageUrl, 'token' => $token,
            'expires_at' => $now->add(new DateInterval('PT24H'))->format(DATE_ATOM),
        ];
    }

    /** @return array{cover_url:string} */
    public function adopt(AuthContext $context, string $token): array
    {
        $householdId = $this->guard->requireMembership($context);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) throw $this->notFound();
        $staged = null;
        $temporaryPath = null;
        $previewId = null;
        try {
            $result = $this->transactions->transaction(function () use ($context, $householdId, $token, &$staged, &$temporaryPath, &$previewId): array {
                $row = $this->previews->findForAdoption(hash('sha256', $token), $householdId, $context->userId);
                if ($row === null || $row['household_id'] !== $householdId || $row['user_id'] !== $context->userId) throw $this->notFound();
                $now = $this->clock->now();
                if ($row['adopted_at'] !== null || $row['temp_image_path'] === null || $this->expiresAt($row['expires_at']) <= $now) throw $this->unavailableError();
                $temporaryPath = $row['temp_image_path'];
                $previewId = $row['id'];
                $staged = $this->images->stageAdoption($temporaryPath);
                if (!$this->previews->markAdopted($previewId, $this->databaseTime($now), $staged['url'])) throw $this->unavailableError();
                return ['cover_url' => $staged['url']];
            });
        } catch (Throwable $exception) {
            if ($staged !== null) {
                try { $this->images->removePermanent($staged['path']); } catch (Throwable) {}
            }
            throw $exception;
        }
        try {
            $this->images->deleteTemporary($temporaryPath);
            $this->previews->clearTemporaryPath($previewId);
        } catch (Throwable $exception) {
            error_log('Preview adoption cleanup failed: ' . $exception->getMessage());
        }
        return $result;
    }

    /** @return array{bytes:string,mime_type:string,max_age:int} */
    public function image(AuthContext $context, string $token): array
    {
        $householdId = $this->guard->requireMembership($context);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) throw $this->notFound();
        $row = $this->previews->findForImage(hash('sha256', $token), $householdId, $context->userId);
        $now = $this->clock->now();
        if ($row === null || $row['household_id'] !== $householdId || $row['user_id'] !== $context->userId || $row['adopted_at'] !== null || $row['temp_image_path'] === null || $row['image_mime_type'] === null || $this->expiresAt($row['expires_at']) <= $now) throw $this->notFound();
        return [
            'bytes' => $this->images->readTemporary($row['temp_image_path']),
            'mime_type' => $row['image_mime_type'],
            'max_age' => min(300, max(0, $this->expiresAt($row['expires_at'])->getTimestamp() - $now->getTimestamp())),
        ];
    }

    /** @return array{0:HttpResponse,1:ValidatedUrl} */
    private function fetch(ValidatedUrl $current, bool $image, int $limit, int $deadline): array
    {
        for ($redirects = 0; ; $redirects++) {
            $remaining = (int) max(0, ceil(($deadline - hrtime(true)) / 1_000_000));
            if ($remaining < 1) throw new \RuntimeException('Remote request timed out.');
            $response = $this->http->get($current->url, $current->addresses, min(3000, $remaining), $remaining, $limit);
            if (!in_array($response->status, [301, 302, 303, 307, 308], true)) return [$response, $current];
            $location = $response->header('location');
            if ($location === null || $redirects >= 3) throw new \RuntimeException('Remote redirect limit exceeded.');
            $next = $this->absoluteUrl($current->url, $location);
            $remaining = $this->remainingMilliseconds($deadline);
            $current = $image ? $this->urls->validateImage($next, $remaining) : $this->urls->validatePage($next, $remaining);
        }
    }

    /** @return array{title:?string,image:?string} */
    private function parseHtml(string $html): array
    {
        $openGraph = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                $attributes = [];
                if (preg_match_all('/(?:^|\s)([a-zA-Z_:.-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER) !== false) {
                    foreach ($matches as $match) $attributes[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $property = strtolower((string) ($attributes['property'] ?? $attributes['name'] ?? ''));
                if (str_starts_with($property, 'og:') && isset($attributes['content'])) $openGraph[$property] = trim($attributes['content']);
            }
        }
        $title = $openGraph['og:title'] ?? null;
        if (($title === null || $title === '') && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) === 1) $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return ['title' => $title === '' ? null : $title, 'image' => ($openGraph['og:image'] ?? '') === '' ? null : $openGraph['og:image']];
    }

    private function absoluteUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location) === 1) return $location;
        $parts = parse_url($base);
        $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) return 'https:' . $location;
        if (str_starts_with($location, '/')) return $origin . $location;
        $path = (string) ($parts['path'] ?? '/');
        return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
    }

    /** @return array{available:false,platform:string,normalized_url:string,reason:string} */
    private function unavailable(ValidatedUrl $url, string $reason): array
    {
        return ['available' => false, 'platform' => $url->platform, 'normalized_url' => $url->url, 'reason' => $reason];
    }

    private function notFound(): ApiException
    {
        return new ApiException(404, 'LINK_PREVIEW_NOT_FOUND', 'The link preview was not found.');
    }

    private function unavailableError(): ApiException
    {
        return new ApiException(409, 'LINK_PREVIEW_UNAVAILABLE', 'The link preview was already adopted or has expired.');
    }

    private function databaseTime(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.u');
    }

    private function expiresAt(string $databaseValue): DateTimeImmutable
    {
        return new DateTimeImmutable($databaseValue, new DateTimeZone('UTC'));
    }

    private function remainingMilliseconds(int $deadline): int
    {
        $remaining = (int) ceil(($deadline - hrtime(true)) / 1_000_000);
        if ($remaining < 1) throw new DnsLookupTimedOut();
        return $remaining;
    }

    private function normalizeTitle(string $title): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($title));
        return Text::truncate(trim($normalized ?? $title), 512);
    }
}

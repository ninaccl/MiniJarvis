<?php

namespace App\LinkPreview;

use App\Auth\AuthContext;
use App\Database\TransactionManager;
use App\Household\TenantGuard;
use App\Http\ApiException;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use finfo;
use Exception;
use App\Support\Text;
final class LinkPreviewService
{
    private $transactions;
    private $previews;
    private $urls;
    private $http;
    private $images;
    private $clock;
    private $guard;
    const PAGE_LIMIT = 1048576;
    const IMAGE_LIMIT = 5242880;
    private static function imageTypes()
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    }
    public function __construct(TransactionManager $transactions, LinkPreviewRepository $previews, UrlSafetyPolicy $urls, HttpClient $http, PreviewImageStore $images, Clock $clock, TenantGuard $guard)
    {
        $this->transactions = $transactions;
        $this->previews = $previews;
        $this->urls = $urls;
        $this->http = $http;
        $this->images = $images;
        $this->clock = $clock;
        $this->guard = $guard;
    }
    /** @return array<string,mixed> */
    public function preview(AuthContext $context, $url)
    {
        $householdId = $this->guard->requireMembership($context);
        $deadline = \App\Support\Compat::nowSeconds() + 8;
        try {
            $initial = $this->urls->validatePage($url, $this->remainingMilliseconds($deadline));
            list($page, $final) = $this->fetch($initial, false, self::PAGE_LIMIT, $deadline);
        } catch (DnsResolutionFailed $exception) {
            return ['available' => false, 'platform' => $exception->platform, 'normalized_url' => $exception->normalizedUrl, 'reason' => 'fetch_failed'];
        } catch (ApiException $exception) {
            throw $exception;
        } catch (ResponseTooLarge $ignored) {
            return $this->unavailable($initial, 'response_too_large');
        } catch (Exception $ignored) {
            return $this->unavailable($initial, 'fetch_failed');
        }
        if (strlen($page->body) > self::PAGE_LIMIT) {
            return $this->unavailable($initial, 'response_too_large');
        }
        if ($page->status < 200 || $page->status >= 300) {
            return $this->unavailable($initial, 'fetch_failed');
        }
        $metadata = $this->parseHtml($page->body);
        if ($metadata['title'] === null) {
            return $this->unavailable($initial, 'unparseable');
        }
        $metadata['title'] = $this->normalizeTitle($metadata['title']);
        $temporary = null;
        if ($metadata['image'] !== null) {
            $imageUrl = $this->absoluteUrl($final->url, $metadata['image']);
            try {
                $validatedImage = $this->urls->validateImage($imageUrl, $this->remainingMilliseconds($deadline));
                list($image) = $this->fetch($validatedImage, true, self::IMAGE_LIMIT, $deadline);
                if ($image->status >= 200 && $image->status < 300 && strlen($image->body) <= self::IMAGE_LIMIT) {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($image->body);
                    if (is_string($mime) && isset(self::imageTypes()[$mime])) {
                        $temporary = $this->images->storeTemporary($image->body, self::imageTypes()[$mime]);
                    }
                }
            } catch (ApiException $exception) {
                throw $exception;
            } catch (Exception $ignored) {
                $temporary = null;
            }
        }
        $token = bin2hex(\App\Support\Compat::randomBytes(32));
        $previewImageUrl = $temporary === null ? null : '/api/v1/link-previews/' . $token . '/image';
        $now = $this->clock->now();
        try {
            $this->previews->create(['household_id' => $householdId, 'user_id' => $context->userId, 'token_hash' => hash('sha256', $token), 'url_hash' => hash('sha256', $final->url), 'platform' => $initial->platform, 'normalized_url' => $final->url, 'title' => $metadata['title'], 'image_url' => $previewImageUrl, 'temp_image_path' => isset($temporary['path']) ? $temporary['path'] : null, 'image_mime_type' => $temporary === null ? null : $mime, 'fetched_at' => $this->databaseTime($now), 'expires_at' => $this->databaseTime($now->add(new DateInterval('PT24H')))]);
        } catch (Exception $exception) {
            if ($temporary !== null) {
                try {
                    $this->images->deleteTemporary($temporary['path']);
                } catch (Exception $ignored) {
                }
            }
            throw $exception;
        }
        return ['available' => true, 'platform' => $initial->platform, 'normalized_url' => $final->url, 'title' => $metadata['title'], 'image_url' => $previewImageUrl, 'token' => $token, 'expires_at' => $now->add(new DateInterval('PT24H'))->format(DATE_ATOM)];
    }
    /** @return array{cover_url:string} */
    public function adopt(AuthContext $context, $token)
    {
        $householdId = $this->guard->requireMembership($context);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw $this->notFound();
        }
        $staged = null;
        $temporaryPath = null;
        $previewId = null;
        try {
            $result = $this->transactions->transaction(function () use ($context, $householdId, $token, &$staged, &$temporaryPath, &$previewId) {
                $row = $this->previews->findForAdoption(hash('sha256', $token), $householdId, $context->userId);
                if ($row === null || $row['household_id'] !== $householdId || $row['user_id'] !== $context->userId) {
                    throw $this->notFound();
                }
                $now = $this->clock->now();
                if ($row['adopted_at'] !== null || $row['temp_image_path'] === null || $this->expiresAt($row['expires_at']) <= $now) {
                    throw $this->unavailableError();
                }
                $temporaryPath = $row['temp_image_path'];
                $previewId = $row['id'];
                $staged = $this->images->stageAdoption($temporaryPath);
                if (!$this->previews->markAdopted($previewId, $this->databaseTime($now), $staged['url'])) {
                    throw $this->unavailableError();
                }
                return ['cover_url' => $staged['url']];
            });
        } catch (Exception $exception) {
            if ($staged !== null) {
                try {
                    $this->images->removePermanent($staged['path']);
                } catch (Exception $ignored) {
                }
            }
            throw $exception;
        }
        try {
            $this->images->deleteTemporary($temporaryPath);
            $this->previews->clearTemporaryPath($previewId);
        } catch (Exception $exception) {
            error_log('Preview adoption cleanup failed: ' . $exception->getMessage());
        }
        return $result;
    }
    /** @return array{bytes:string,mime_type:string,max_age:int} */
    public function image(AuthContext $context, $token)
    {
        $householdId = $this->guard->requireMembership($context);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw $this->notFound();
        }
        $row = $this->previews->findForImage(hash('sha256', $token), $householdId, $context->userId);
        $now = $this->clock->now();
        if ($row === null || $row['household_id'] !== $householdId || $row['user_id'] !== $context->userId || $row['adopted_at'] !== null || $row['temp_image_path'] === null || $row['image_mime_type'] === null || $this->expiresAt($row['expires_at']) <= $now) {
            throw $this->notFound();
        }
        return ['bytes' => $this->images->readTemporary($row['temp_image_path']), 'mime_type' => $row['image_mime_type'], 'max_age' => min(300, max(0, $this->expiresAt($row['expires_at'])->getTimestamp() - $now->getTimestamp()))];
    }
    /** @return array{0:HttpResponse,1:ValidatedUrl} */
    private function fetch(ValidatedUrl $current, $image, $limit, $deadline)
    {
        for ($redirects = 0;; $redirects++) {
            $remaining = (int) max(0, ceil(($deadline - \App\Support\Compat::nowSeconds()) * 1000));
            if ($remaining < 1) {
                throw new \RuntimeException('Remote request timed out.');
            }
            $response = $this->http->get($current->url, $current->addresses, min(3000, $remaining), $remaining, $limit);
            if (!in_array($response->status, [301, 302, 303, 307, 308], true)) {
                return [$response, $current];
            }
            $location = $response->header('location');
            if ($location === null || $redirects >= 3) {
                throw new \RuntimeException('Remote redirect limit exceeded.');
            }
            $next = $this->absoluteUrl($current->url, $location);
            $remaining = $this->remainingMilliseconds($deadline);
            $current = $image ? $this->urls->validateImage($next, $remaining) : $this->urls->validatePage($next, $remaining);
        }
    }
    /** @return array{title:?string,image:?string} */
    private function parseHtml($html)
    {
        $openGraph = [];
        if (preg_match_all('/<meta\b[^>]*>/i', $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                $attributes = [];
                if (preg_match_all('/(?:^|\s)([a-zA-Z_:.-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER) !== false) {
                    foreach ($matches as $match) {
                        $attributes[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
                $property = strtolower((string) (isset($attributes['property']) ? $attributes['property'] : (isset($attributes['name']) ? $attributes['name'] : '')));
                if (\App\Support\Compat::startsWith($property, 'og:') && isset($attributes['content'])) {
                    $openGraph[$property] = trim($attributes['content']);
                }
            }
        }
        $title = isset($openGraph['og:title']) ? $openGraph['og:title'] : null;
        if (($title === null || $title === '') && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $match) === 1) {
            $title = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return ['title' => $title === '' ? null : $title, 'image' => (isset($openGraph['og:image']) ? $openGraph['og:image'] : '') === '' ? null : $openGraph['og:image']];
    }
    private function absoluteUrl($base, $location)
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (\App\Support\Compat::startsWith($location, '//')) {
            return 'https:' . $location;
        }
        if (\App\Support\Compat::startsWith($location, '/')) {
            return $origin . $location;
        }
        $path = (string) (isset($parts['path']) ? $parts['path'] : '/');
        return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
    }
    /** @return array{available:false,platform:string,normalized_url:string,reason:string} */
    private function unavailable(ValidatedUrl $url, $reason)
    {
        return ['available' => false, 'platform' => $url->platform, 'normalized_url' => $url->url, 'reason' => $reason];
    }
    private function notFound()
    {
        return new ApiException(404, 'LINK_PREVIEW_NOT_FOUND', 'The link preview was not found.');
    }
    private function unavailableError()
    {
        return new ApiException(409, 'LINK_PREVIEW_UNAVAILABLE', 'The link preview was already adopted or has expired.');
    }
    private function databaseTime(DateTimeImmutable $time)
    {
        return $time->format('Y-m-d H:i:s.u');
    }
    private function expiresAt($databaseValue)
    {
        return new DateTimeImmutable($databaseValue, new DateTimeZone('UTC'));
    }
    private function remainingMilliseconds($deadline)
    {
        $remaining = (int) ceil(($deadline - \App\Support\Compat::nowSeconds()) * 1000);
        if ($remaining < 1) {
            throw new DnsLookupTimedOut();
        }
        return $remaining;
    }
    private function normalizeTitle($title)
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($title));
        return Text::truncate(trim(isset($normalized) ? $normalized : $title), 512);
    }
}

<?php

declare(strict_types=1);

namespace App\LinkPreview;

interface HttpClient
{
    /** @param list<string> $resolvedAddresses */
    public function get(string $url, array $resolvedAddresses, int $connectionTimeoutMilliseconds, int $totalTimeoutMilliseconds, int $bodyLimit): HttpResponse;
}

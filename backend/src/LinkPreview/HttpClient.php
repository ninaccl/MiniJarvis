<?php

namespace App\LinkPreview;

interface HttpClient
{
    /** @param list<string> $resolvedAddresses */
    public function get($url, array $resolvedAddresses, $connectionTimeoutMilliseconds, $totalTimeoutMilliseconds, $bodyLimit);
}

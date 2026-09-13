<?php

declare(strict_types=1);

namespace App\LinkPreview;

interface DnsResolver
{
    /** @return list<string> */
    public function resolve(string $host, int $timeoutMilliseconds): array;
}

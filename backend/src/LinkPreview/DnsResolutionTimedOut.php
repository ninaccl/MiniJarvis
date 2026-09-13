<?php

declare(strict_types=1);

namespace App\LinkPreview;

final class DnsResolutionTimedOut extends DnsResolutionFailed
{
    public function __construct(string $normalizedUrl, string $platform)
    {
        parent::__construct($normalizedUrl, $platform);
    }
}

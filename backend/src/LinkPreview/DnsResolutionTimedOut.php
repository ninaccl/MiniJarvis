<?php

namespace App\LinkPreview;

final class DnsResolutionTimedOut extends DnsResolutionFailed
{
    public function __construct($normalizedUrl, $platform)
    {
        parent::__construct($normalizedUrl, $platform);
    }
}

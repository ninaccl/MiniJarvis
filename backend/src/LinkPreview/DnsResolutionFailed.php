<?php

declare(strict_types=1);

namespace App\LinkPreview;

use RuntimeException;

class DnsResolutionFailed extends RuntimeException
{
    public function __construct(public readonly string $normalizedUrl, public readonly string $platform)
    {
        parent::__construct('DNS resolution failed.');
    }
}

<?php

declare(strict_types=1);

namespace App\LinkPreview;

final class ValidatedUrl
{
    /** @param list<string> $addresses */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly string $platform,
        public readonly array $addresses,
    ) {
    }
}

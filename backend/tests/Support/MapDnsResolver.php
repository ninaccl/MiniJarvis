<?php

declare(strict_types=1);

namespace Tests\Support;

use App\LinkPreview\DnsResolver;

final class MapDnsResolver implements DnsResolver
{
    /** @var list<int> */
    public array $budgets = [];
    /** @param array<string,list<string>> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public function resolve(string $host, int $timeoutMilliseconds): array
    {
        $this->budgets[] = $timeoutMilliseconds;
        return $this->answers[$host] ?? [];
    }
}

<?php

declare(strict_types=1);

namespace App\LinkPreview;

final class DnsCommandFactory
{
    private const PROGRAM = <<<'PHP'
$records = dns_get_record($argv[1], DNS_A | DNS_AAAA);
$addresses = [];
if (is_array($records)) {
    foreach ($records as $record) {
        if (isset($record['ip'])) $addresses[] = (string) $record['ip'];
        if (isset($record['ipv6'])) $addresses[] = (string) $record['ipv6'];
    }
}
fwrite(STDOUT, json_encode(array_values(array_unique($addresses)), JSON_THROW_ON_ERROR));
PHP;

    public function __construct(private readonly string $cliBinary)
    {
    }

    public function binary(): string
    {
        return $this->cliBinary;
    }

    /** @return list<string> */
    public function forHost(string $host): array
    {
        return [$this->cliBinary, '-r', self::PROGRAM, $host];
    }
}

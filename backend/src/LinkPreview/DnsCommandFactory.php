<?php

namespace App\LinkPreview;

final class DnsCommandFactory
{
    private $cliBinary;
    const PROGRAM = <<<'PHP'
$records = dns_get_record($argv[1], DNS_A | DNS_AAAA);
$addresses = [];
if (is_array($records)) {
    foreach ($records as $record) {
        if (isset($record['ip'])) $addresses[] = (string) $record['ip'];
        if (isset($record['ipv6'])) $addresses[] = (string) $record['ipv6'];
    }
}
fwrite(STDOUT, json_encode(array_values(array_unique($addresses))));
PHP;
    public function __construct($cliBinary)
    {
        $this->cliBinary = $cliBinary;
    }
    public function binary()
    {
        return $this->cliBinary;
    }
    /** @return list<string> */
    public function forHost($host)
    {
        return escapeshellarg($this->cliBinary) . ' -r ' . escapeshellarg(self::PROGRAM) . ' ' . escapeshellarg($host);
    }
}

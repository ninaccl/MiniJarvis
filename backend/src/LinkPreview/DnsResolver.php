<?php

namespace App\LinkPreview;

interface DnsResolver
{
    /** @return list<string> */
    public function resolve($host, $timeoutMilliseconds);
}

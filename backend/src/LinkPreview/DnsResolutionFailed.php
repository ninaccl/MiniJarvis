<?php

namespace App\LinkPreview;

use RuntimeException;
class DnsResolutionFailed extends RuntimeException
{
    public $normalizedUrl;
    public $platform;
    public function __construct($normalizedUrl, $platform)
    {
        $this->normalizedUrl = $normalizedUrl;
        $this->platform = $platform;
        parent::__construct('DNS resolution failed.');
    }
}

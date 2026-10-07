<?php

namespace App\LinkPreview;

final class ValidatedUrl
{
    public $url;
    public $host;
    public $platform;
    public $addresses;
    /** @param list<string> $addresses */
    public function __construct($url, $host, $platform, array $addresses)
    {
        $this->url = $url;
        $this->host = $host;
        $this->platform = $platform;
        $this->addresses = $addresses;
    }
}

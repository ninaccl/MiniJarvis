<?php

namespace App\LinkPreview;

final class HttpResponse
{
    public $status;
    public $headers;
    public $body;
    public $contentType;
    /** @param array<string,string> $headers */
    public function __construct($status, array $headers, $body, $contentType)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
        $this->contentType = $contentType;
    }
    public function header($name)
    {
        return isset($this->headers[strtolower($name)]) ? $this->headers[strtolower($name)] : null;
    }
}

<?php

namespace App\Http;

final class Response
{
    private $status;
    private $payload;
    private $body;
    private $headers;
    /** @param array<string, mixed> $payload */
    private function __construct($status, array $payload = [], $body = null, array $headers = [])
    {
        $this->status = $status;
        $this->payload = $payload;
        $this->body = $body;
        $this->headers = $headers;
    }
    /** @param array<string, mixed>|list<mixed>|null $data @param array<string, mixed>|null $meta */
    public static function success($data, $status = 200, $meta = null)
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return new self($status, $payload);
    }
    public static function fromException(ApiException $exception)
    {
        $error = ['code' => $exception->errorCode(), 'message' => $exception->getMessage()];
        if ($exception->fields() !== []) {
            $error['fields'] = $exception->fields();
        }
        return new self($exception->status(), ['success' => false, 'error' => $error]);
    }
    public static function internalError()
    {
        return new self(500, ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']]);
    }
    public static function binary($body, $mimeType, $maxAgeSeconds = 300)
    {
        return new self(200, [], $body, ['Content-Type' => $mimeType, 'Content-Length' => (string) strlen($body), 'Cache-Control' => 'private, max-age=' . max(0, min(300, $maxAgeSeconds)), 'X-Content-Type-Options' => 'nosniff']);
    }
    public function status()
    {
        return $this->status;
    }
    /** @return array<string, mixed> */
    public function payload()
    {
        return $this->payload;
    }
    public function body()
    {
        return $this->body;
    }
    /** @return array<string,string> */
    public function headers()
    {
        return $this->headers;
    }
    public function send()
    {
        http_response_code($this->status);
        if ($this->body !== null) {
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            echo $this->body;
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo \App\Support\Compat::jsonEncode($this->payload);
        exit;
    }
}

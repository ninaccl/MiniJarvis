<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        private readonly int $status,
        private readonly array $payload = [],
        private readonly ?string $body = null,
        private readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed>|list<mixed>|null $data @param array<string, mixed>|null $meta */
    public static function success(array|null $data, int $status = 200, ?array $meta = null): self
    {
        $payload = ['success' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return new self($status, $payload);
    }

    public static function fromException(ApiException $exception): self
    {
        $error = [
            'code' => $exception->errorCode(),
            'message' => $exception->getMessage(),
        ];
        if ($exception->fields() !== []) {
            $error['fields'] = $exception->fields();
        }
        return new self($exception->status(), ['success' => false, 'error' => $error]);
    }

    public static function internalError(): self
    {
        return new self(500, [
            'success' => false,
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'An unexpected error occurred.',
            ],
        ]);
    }

    public static function binary(string $body, string $mimeType, int $maxAgeSeconds = 300): self
    {
        return new self(200, [], $body, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($body),
            'Cache-Control' => 'private, max-age=' . max(0, min(300, $maxAgeSeconds)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): never
    {
        http_response_code($this->status);
        if ($this->body !== null) {
            foreach ($this->headers as $name => $value) header($name . ': ' . $value);
            echo $this->body;
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $attributes
     * @param array<string, string> $routeParams
     * @param array<string, array<string, mixed>> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly ?array $body = null,
        private readonly array $attributes = [],
        private readonly array $routeParams = [],
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        $body = null;
        $rawBody = file_get_contents('php://input');
        $contentType = strtolower($headers['content-type'] ?? '');
        if ($rawBody !== false && trim($rawBody) !== '' && str_starts_with($contentType, 'application/json')) {
            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiException(422, 'INVALID_JSON', 'Request body must be valid JSON.');
            }
            if (!is_array($decoded) || !str_starts_with(ltrim($rawBody), '{')) {
                throw new ApiException(422, 'INVALID_JSON', 'Request body must be a JSON object.');
            }
            $body = $decoded;
        }

        /** @var array<string, string> $query */
        $query = array_map(static fn (mixed $value): string => (string) $value, $_GET);
        /** @var array<string, array<string, mixed>> $files */
        $files = $_FILES;
        return new self($method, $path, $headers, $query, $body, [], [], $files);
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        $normalized = '/' . ltrim($this->path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return $this->body ?? [];
    }

    public function query(string $name, ?string $default = null): ?string
    {
        return $this->query[$name] ?? $default;
    }

    public function attribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function routeParam(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function file(string $name): ?array
    {
        return $this->files[$name] ?? null;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->headers,
            $this->query,
            $this->body,
            [...$this->attributes, $name => $value],
            $this->routeParams,
            $this->files,
        );
    }

    /** @param array<string, string> $routeParams */
    public function withRouteParams(array $routeParams): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->headers,
            $this->query,
            $this->body,
            $this->attributes,
            $routeParams,
            $this->files,
        );
    }
}

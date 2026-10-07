<?php

namespace App\Http;

final class Request
{
    private $method;
    private $path;
    private $headers;
    private $query;
    private $body;
    private $attributes;
    private $routeParams;
    private $files;
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $attributes
     * @param array<string, string> $routeParams
     * @param array<string, array<string, mixed>> $files
     */
    public function __construct($method, $path, array $headers = [], array $query = [], $body = null, array $attributes = [], array $routeParams = [], array $files = [])
    {
        $this->method = $method;
        $this->path = $path;
        $this->headers = $headers;
        $this->query = $query;
        $this->body = $body;
        $this->attributes = $attributes;
        $this->routeParams = $routeParams;
        $this->files = $files;
    }
    public static function fromGlobals()
    {
        $method = strtoupper((string) (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET'));
        $uri = (string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: '/'));
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (\App\Support\Compat::startsWith($key, 'HTTP_')) {
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
        $contentType = strtolower(isset($headers['content-type']) ? $headers['content-type'] : '');
        if ($rawBody !== false && trim($rawBody) !== '' && \App\Support\Compat::startsWith($contentType, 'application/json')) {
            try {
                $decoded = \App\Support\Compat::jsonDecode($rawBody);
            } catch (\RuntimeException $ignored) {
                throw new ApiException(422, 'INVALID_JSON', 'Request body must be valid JSON.');
            }
            if (!is_array($decoded) || !\App\Support\Compat::startsWith(ltrim($rawBody), '{')) {
                throw new ApiException(422, 'INVALID_JSON', 'Request body must be a JSON object.');
            }
            $body = $decoded;
        }
        /** @var array<string, string> $query */
        $query = array_map(static function ($value) {
            return (string) $value;
        }, $_GET);
        /** @var array<string, array<string, mixed>> $files */
        $files = $_FILES;
        return new self($method, $path, $headers, $query, $body, [], [], $files);
    }
    public function method()
    {
        return strtoupper($this->method);
    }
    public function path()
    {
        $normalized = '/' . ltrim($this->path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
    public function header($name)
    {
        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }
    /** @return array<string, mixed> */
    public function json()
    {
        return isset($this->body) ? $this->body : [];
    }
    public function query($name, $default = null)
    {
        return isset($this->query[$name]) ? $this->query[$name] : $default;
    }
    public function attribute($name)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : null;
    }
    public function routeParam($name)
    {
        return isset($this->routeParams[$name]) ? $this->routeParams[$name] : null;
    }
    /** @return array<string,mixed>|null */
    public function file($name)
    {
        return isset($this->files[$name]) ? $this->files[$name] : null;
    }
    public function withAttribute($name, $value)
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;
        return new self($this->method, $this->path, $this->headers, $this->query, $this->body, $attributes, $this->routeParams, $this->files);
    }
    /** @param array<string, string> $routeParams */
    public function withRouteParams(array $routeParams)
    {
        return new self($this->method, $this->path, $this->headers, $this->query, $this->body, $this->attributes, $routeParams, $this->files);
    }
}

<?php

declare(strict_types=1);

namespace App\Notification;

final class AccessTokenCache
{
    public function __construct(private readonly string $path)
    {
    }

    public function get(): ?string
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) return null;
        try {
            if (!flock($handle, LOCK_SH)) return null;
            $raw = stream_get_contents($handle);
            $value = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            return is_array($value) && is_string($value['token'] ?? null) && (int) ($value['expires_at'] ?? 0) > time() + 60 ? $value['token'] : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function put(string $token, int $ttlSeconds): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create WeChat token cache directory.');
        $handle = fopen($this->path, 'c+');
        if ($handle === false) throw new \RuntimeException('Cannot open WeChat token cache.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock WeChat token cache.');
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode(['token' => $token, 'expires_at' => time() + max(0, $ttlSeconds)], JSON_THROW_ON_ERROR));
            fflush($handle);
            @chmod($this->path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

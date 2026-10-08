<?php

namespace App\Notification;

final class AccessTokenCache
{
    private $path;
    public function __construct($path)
    {
        $this->path = $path;
    }
    public function get()
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            return null;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return null;
            }
            $raw = stream_get_contents($handle);
            $value = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            return is_array($value) && is_string(isset($value['token']) ? $value['token'] : null) && (int) (isset($value['expires_at']) ? $value['expires_at'] : 0) > time() + 60 ? $value['token'] : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
    public function put($token, $ttlSeconds)
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create WeChat token cache directory.');
        }
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open WeChat token cache.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock WeChat token cache.');
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, \App\Support\Compat::jsonEncode(['token' => $token, 'expires_at' => time() + max(0, $ttlSeconds)]));
            fflush($handle);
            @chmod($this->path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

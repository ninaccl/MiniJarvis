<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

final class Compat
{
    public static function randomBytes($length)
    {
        if (!is_int($length) || $length < 1) throw new InvalidArgumentException('Random length must be positive.');
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes($length, $strong);
            if ($strong && is_string($bytes) && strlen($bytes) === $length) return $bytes;
        }
        $handle = @fopen('/dev/urandom', 'rb');
        if ($handle !== false) {
            $bytes = '';
            while (strlen($bytes) < $length && !feof($handle)) {
                $part = fread($handle, $length - strlen($bytes));
                if ($part === false || $part === '') break;
                $bytes .= $part;
            }
            fclose($handle);
            if (strlen($bytes) === $length) return $bytes;
        }
        throw new RuntimeException('Secure random source is unavailable.');
    }

    public static function startsWith($value, $prefix)
    {
        return substr($value, 0, strlen($prefix)) === $prefix;
    }

    public static function endsWith($value, $suffix)
    {
        return $suffix === '' || substr($value, -strlen($suffix)) === $suffix;
    }

    public static function contains($value, $needle)
    {
        return $needle === '' || strpos($value, $needle) !== false;
    }

    public static function isList($value)
    {
        if (!is_array($value)) return false;
        $next = 0;
        foreach ($value as $key => $ignored) {
            if ($key !== $next++) return false;
        }
        return true;
    }

    public static function nowSeconds()
    {
        return microtime(true);
    }

    public static function jsonEncode($value)
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON encoding failed.');
        return $encoded;
    }

    public static function jsonDecode($value)
    {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON decoding failed.');
        return $decoded;
    }

    public static function valueOrThrow($value, $exception)
    {
        if ($value === null) throw $exception;
        return $value;
    }
}

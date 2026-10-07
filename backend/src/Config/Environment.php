<?php

namespace App\Config;

use RuntimeException;

final class Environment
{
    public static function load($root)
    {
        $path = rtrim($root, '/\\') . '/.env';
        if (!is_file($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) throw new RuntimeException('Environment file could not be read.');

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
                throw new RuntimeException('Environment file contains an invalid assignment.');
            }
            $key = $matches[1];
            if (array_key_exists($key, $_ENV) || array_key_exists($key, $_SERVER) || getenv($key) !== false) continue;
            $value = trim($matches[2]);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (strlen($value) < 2 || substr($value, -1) !== $quote) {
                    throw new RuntimeException('Environment file contains an unterminated quote.');
                }
                $value = substr($value, 1, -1);
                if ($quote === '"') $value = stripcslashes($value);
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Inventory;

use InvalidArgumentException;

final class Quantity
{
    private const SCALE = 10000;

    public static function format(float $value): string
    {
        if (!is_finite($value) || abs($value) >= 100000000000000) {
            throw new InvalidArgumentException('Base quantity is outside DECIMAL(18,4).');
        }
        $formatted = number_format(round($value, 4), 4, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    public static function add(string $left, string $right): string
    {
        return self::format((float) $left + (float) $right);
    }

    public static function subtract(string $left, string $right): string
    {
        return self::format((float) $left - (float) $right);
    }

    public static function compare(string $left, string $right): int
    {
        $difference = (int) round(((float) $left - (float) $right) * self::SCALE);
        return $difference <=> 0;
    }

    public static function negative(string $value): string
    {
        return self::format(-(float) $value);
    }
}

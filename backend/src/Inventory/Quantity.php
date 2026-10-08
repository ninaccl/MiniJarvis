<?php

namespace App\Inventory;

use InvalidArgumentException;
final class Quantity
{
    const SCALE = 10000;
    public static function format($value)
    {
        if (!is_finite($value) || abs($value) >= 100000000000000) {
            throw new InvalidArgumentException('Base quantity is outside DECIMAL(18,4).');
        }
        $formatted = number_format(round($value, 4), 4, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
    public static function add($left, $right)
    {
        return self::format((float) $left + (float) $right);
    }
    public static function subtract($left, $right)
    {
        return self::format((float) $left - (float) $right);
    }
    public static function compare($left, $right)
    {
        $difference = (int) round(((float) $left - (float) $right) * self::SCALE);
        return $difference < 0 ? -1 : ($difference > 0 ? 1 : 0);
    }
    public static function negative($value)
    {
        return self::format(-(float) $value);
    }
}

<?php

namespace App\Recipe;

use InvalidArgumentException;
final class DecimalQuantity
{
    public static function forStorage($value)
    {
        if (is_string($value)) {
            $encoded = $value;
        } elseif (is_int($value) || is_float($value)) {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Quantity must be finite.');
            }
            $encoded = \App\Support\Compat::jsonEncode($value);
        } else {
            throw new InvalidArgumentException('Quantity must be numeric.');
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $encoded, $match) !== 1 || $match[1] === '-') {
            throw new InvalidArgumentException('Quantity must be positive.');
        }
        $integerDigits = $match[2];
        $fractionDigits = isset($match[3]) ? $match[3] : '';
        $exponent = isset($match[4]) ? (int) $match[4] : 0;
        $digits = $integerDigits . $fractionDigits;
        $decimalPosition = strlen($integerDigits) + $exponent;
        if ($decimalPosition <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $integer = $digits . str_repeat('0', $decimalPosition - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $decimalPosition);
            $fraction = substr($digits, $decimalPosition);
        }
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        if ($integer === '0' && $fraction === '' || strlen($integer) > 10 || strlen($fraction) > 4) {
            throw new InvalidArgumentException('Quantity is outside DECIMAL(14,4).');
        }
        if (strlen($integer) === 10 && strcmp($integer, '9999999999') > 0) {
            throw new InvalidArgumentException('Quantity is outside DECIMAL(14,4).');
        }
        return $integer . ($fraction === '' ? '' : '.' . $fraction);
    }
}

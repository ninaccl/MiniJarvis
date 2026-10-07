<?php

namespace App\Support;

final class Text
{
    public static function length($value)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $matches = [];
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : $count;
    }

    public static function truncate($value, $characters)
    {
        if (function_exists('mb_substr')) return mb_substr($value, 0, $characters, 'UTF-8');
        $matches = [];
        return preg_match_all('/./us', $value, $matches) === false
            ? substr($value, 0, $characters)
            : implode('', array_slice($matches[0], 0, $characters));
    }
}

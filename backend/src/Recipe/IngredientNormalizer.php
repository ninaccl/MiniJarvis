<?php

namespace App\Recipe;

final class IngredientNormalizer
{
    public static function normalize($name)
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));
        $collapsed = $collapsed === null ? trim($name) : $collapsed;
        return strtr(trim($collapsed), array_combine(range('A', 'Z'), range('a', 'z')) ?: []);
    }
}

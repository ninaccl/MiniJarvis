<?php

declare(strict_types=1);

namespace App\Recipe;

final class IngredientNormalizer
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name));
        $collapsed = $collapsed === null ? trim($name) : $collapsed;
        return strtr(trim($collapsed), array_combine(range('A', 'Z'), range('a', 'z')) ?: []);
    }
}

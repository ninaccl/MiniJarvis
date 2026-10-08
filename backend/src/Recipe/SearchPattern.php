<?php

namespace App\Recipe;

final class SearchPattern
{
    public static function contains($literal)
    {
        return '%' . strtr($literal, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
    }
}

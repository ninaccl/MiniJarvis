<?php

declare(strict_types=1);

namespace App\Recipe;

final class SearchPattern
{
    public static function contains(string $literal): string
    {
        return '%' . strtr($literal, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }
}

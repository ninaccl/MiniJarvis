<?php

declare(strict_types=1);

namespace App\Upload;

interface FileMover
{
    public function move(string $source, string $destination): bool;
}

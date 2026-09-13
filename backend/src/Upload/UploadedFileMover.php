<?php

declare(strict_types=1);

namespace App\Upload;

final class UploadedFileMover implements FileMover
{
    public function move(string $source, string $destination): bool
    {
        return is_uploaded_file($source) && move_uploaded_file($source, $destination);
    }
}

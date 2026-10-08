<?php

namespace App\Upload;

final class UploadedFileMover implements FileMover
{
    public function move($source, $destination)
    {
        return is_uploaded_file($source) && move_uploaded_file($source, $destination);
    }
}

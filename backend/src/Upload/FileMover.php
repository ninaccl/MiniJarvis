<?php

namespace App\Upload;

interface FileMover
{
    public function move($source, $destination);
}

<?php

namespace App\LinkPreview;

interface PreviewImageStore
{
    /** @return array{path:string} */
    public function storeTemporary($bytes, $extension);
    public function readTemporary($temporaryPath);
    /** @return array{path:string,url:string} */
    public function stageAdoption($temporaryPath);
    public function deleteTemporary($temporaryPath);
    public function removePermanent($permanentPath);
}

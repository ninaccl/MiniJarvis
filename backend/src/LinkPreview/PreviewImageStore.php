<?php

declare(strict_types=1);

namespace App\LinkPreview;

interface PreviewImageStore
{
    /** @return array{path:string} */
    public function storeTemporary(string $bytes, string $extension): array;
    public function readTemporary(string $temporaryPath): string;
    /** @return array{path:string,url:string} */
    public function stageAdoption(string $temporaryPath): array;
    public function deleteTemporary(string $temporaryPath): void;
    public function removePermanent(string $permanentPath): void;
}

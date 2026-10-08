<?php

namespace App\LinkPreview;

use RuntimeException;
final class LocalPreviewImageStore implements PreviewImageStore
{
    private $previewRoot;
    private $uploadRoot;
    private $uploadPublicPrefix;
    public function __construct($previewRoot, $uploadRoot, $uploadPublicPrefix)
    {
        $this->previewRoot = $previewRoot;
        $this->uploadRoot = $uploadRoot;
        $this->uploadPublicPrefix = $uploadPublicPrefix;
    }
    public function storeTemporary($bytes, $extension)
    {
        $relative = gmdate('Y/m') . '/' . bin2hex(\App\Support\Compat::randomBytes(16)) . '.' . $extension;
        $destination = $this->path($this->previewRoot, $relative);
        $this->ensureDirectory(dirname($destination));
        if (file_put_contents($destination, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Preview image could not be stored.');
        }
        return ['path' => $relative];
    }
    public function readTemporary($temporaryPath)
    {
        $source = $this->temporaryPath($temporaryPath);
        $bytes = file_get_contents($source);
        if ($bytes === false) {
            throw new RuntimeException('Preview image is missing.');
        }
        return $bytes;
    }
    public function stageAdoption($temporaryPath)
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#', $temporaryPath, $match) !== 1) {
            throw new RuntimeException('Preview path is invalid.');
        }
        $source = $this->path($this->previewRoot, $temporaryPath);
        if (!is_file($source)) {
            throw new RuntimeException('Preview image is missing.');
        }
        $relative = gmdate('Y/m') . '/' . bin2hex(\App\Support\Compat::randomBytes(16)) . '.' . $match[1];
        $destination = $this->path($this->uploadRoot, $relative);
        $this->ensureDirectory(dirname($destination));
        if (!copy($source, $destination)) {
            throw new RuntimeException('Preview image could not be staged.');
        }
        return ['path' => $relative, 'url' => rtrim($this->uploadPublicPrefix, '/') . '/' . $relative];
    }
    public function deleteTemporary($temporaryPath)
    {
        $source = $this->temporaryPath($temporaryPath);
        if (is_file($source) && !unlink($source)) {
            throw new RuntimeException('Temporary preview image could not be deleted.');
        }
    }
    public function removePermanent($permanentPath)
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#', $permanentPath) !== 1) {
            throw new RuntimeException('Permanent image path is invalid.');
        }
        $destination = $this->path($this->uploadRoot, $permanentPath);
        if (is_file($destination) && !unlink($destination)) {
            throw new RuntimeException('Staged permanent image could not be removed.');
        }
    }
    private function temporaryPath($temporaryPath)
    {
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#', $temporaryPath) !== 1) {
            throw new RuntimeException('Preview path is invalid.');
        }
        return $this->path($this->previewRoot, $temporaryPath);
    }
    private function path($root, $relative)
    {
        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
    private function ensureDirectory($directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Image directory could not be created.');
        }
    }
}

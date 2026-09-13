<?php

declare(strict_types=1);

namespace App\Upload;

use App\Http\ApiException;
use finfo;

final class UploadService
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private readonly string $publicRoot,
        private readonly string $publicPrefix,
        private readonly FileMover $mover,
    ) {
    }

    /** @param array<string,mixed> $file @return array{url:string,mime_type:string,size:int} */
    public function store(array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $source = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($source) || !str_starts_with($source, DIRECTORY_SEPARATOR) || !is_file($source) || !is_readable($source)) {
            throw new ApiException(422, 'UPLOAD_INVALID', 'A valid uploaded image is required.');
        }
        $size = filesize($source);
        if ($size === false || $size > self::MAX_BYTES || (isset($file['size']) && (!is_int($file['size']) || $file['size'] > self::MAX_BYTES))) {
            throw new ApiException(422, 'UPLOAD_TOO_LARGE', 'Images may be at most 5 MiB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new ApiException(422, 'UPLOAD_INVALID_TYPE', 'Only JPEG, PNG, and WebP images are accepted.');
        }

        $relativeDirectory = gmdate('Y/m');
        $directory = rtrim($this->publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Upload directory could not be created.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!$this->mover->move($source, $directory . DIRECTORY_SEPARATOR . $filename)) {
            throw new ApiException(422, 'UPLOAD_INVALID', 'The uploaded image could not be stored.');
        }
        return [
            'url' => rtrim($this->publicPrefix, '/') . '/' . $relativeDirectory . '/' . $filename,
            'mime_type' => $mime,
            'size' => $size,
        ];
    }
}

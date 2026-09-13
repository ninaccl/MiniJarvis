<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Connection;
use App\LinkPreview\PdoLinkPreviewRepository;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
if (is_file($root . '/.env')) Dotenv\Dotenv::createImmutable($root)->safeLoad();

$config = Config::fromEnvironment();
$repository = new PdoLinkPreviewRepository(Connection::fromConfig($config)->pdo());
$previewRoot = $root . '/' . ltrim($config->get('PREVIEW_ROOT', 'var/link-previews'), '/');
$before = gmdate('Y-m-d H:i:s.u');
$deleted = 0;

do {
    $rows = $repository->temporaryFilesForCleanup($before);
    $progress = 0;
    foreach ($rows as $row) {
        $path = $row['temp_image_path'];
        if ($path === null) {
            if (!$row['adopted'] && $repository->deleteExpired($row['id'], $before)) $deleted++;
            $progress++;
            continue;
        }
        if (preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#', $path) !== 1) {
            if ($row['adopted']) $repository->clearTemporaryPath($row['id']);
            elseif ($repository->deleteExpired($row['id'], $before)) $deleted++;
            $progress++;
            continue;
        }
        $absolute = rtrim($previewRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($absolute) && !unlink($absolute)) continue;
        if ($row['adopted']) $repository->clearTemporaryPath($row['id']);
        elseif ($repository->deleteExpired($row['id'], $before)) $deleted++;
        $progress++;
    }
} while (count($rows) === 500 && $progress > 0);

fwrite(STDOUT, "Deleted {$deleted} expired link previews.\n");

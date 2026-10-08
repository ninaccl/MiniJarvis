<?php

require dirname(dirname(__DIR__)) . '/bootstrap.php';

$root = dirname(dirname(__DIR__)) . '/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$count = 0;
foreach ($iterator as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') continue;
    $relative = substr($file->getPathname(), strlen($root) + 1, -4);
    $name = 'App\\' . str_replace('/', '\\', $relative);
    if (!class_exists($name) && !interface_exists($name)) {
        fwrite(STDERR, "Could not load $name\n");
        exit(1);
    }
    $count++;
}
fwrite(STDOUT, "Loaded $count production classes and interfaces\n");

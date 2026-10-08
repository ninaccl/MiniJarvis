<?php

$jarvisRoot = __DIR__;
spl_autoload_register(function ($class) use ($jarvisRoot) {
    if (strpos($class, 'App\\') !== 0) return;
    $relative = substr($class, 4);
    $path = $jarvisRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require $path;
});

App\Config\Environment::load($jarvisRoot);
date_default_timezone_set('UTC');

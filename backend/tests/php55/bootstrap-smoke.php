<?php

$root = dirname(dirname(__DIR__));
require $root . '/bootstrap.php';

function expect55($actual, $expected, $label)
{
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

function throws55($callback, $label)
{
    try {
        call_user_func($callback);
    } catch (Exception $exception) {
        return;
    }
    fwrite(STDERR, $label . ": expected exception\n");
    exit(1);
}

if (getenv('JARVIS_TEST_ENTROPY_FAIL') === '1') {
    throws55(function () { App\Support\Compat::randomBytes(16); }, 'secure random source unavailable');
    fwrite(STDOUT, "entropy failure smoke passed\n");
    exit(0);
}

expect55(class_exists('App\\Support\\Text'), true, 'autoload');
$temp = sys_get_temp_dir() . '/jarvis-env-' . getmypid();
mkdir($temp);
file_put_contents($temp . '/.env', "EXISTING=wrong\nQUOTED=\"hello world\"\nEMPTY=\n# ignored\n");
$_ENV['EXISTING'] = 'kept';
App\Config\Environment::load($temp);
expect55($_ENV['EXISTING'], 'kept', 'environment precedence');
expect55($_ENV['QUOTED'], 'hello world', 'quoted environment value');
expect55($_ENV['EMPTY'], '', 'empty environment value');
unlink($temp . '/.env');
rmdir($temp);

$bytes = App\Support\Compat::randomBytes(32);
expect55(strlen($bytes), 32, 'random byte count');
throws55(function () { App\Support\Compat::randomBytes(0); }, 'invalid random length');
expect55(App\Support\Compat::startsWith('abcdef', 'abc'), true, 'startsWith');
expect55(App\Support\Compat::endsWith('abcdef', 'def'), true, 'endsWith');
expect55(App\Support\Compat::contains('abcdef', 'cd'), true, 'contains');
expect55(App\Support\Compat::isList(array('a', 'b')), true, 'list');
expect55(App\Support\Compat::isList(array(1 => 'b')), false, 'non-list');
expect55(App\Support\Compat::jsonDecode('{"x":1}'), array('x' => 1), 'json decode');
expect55(App\Support\Compat::jsonEncode(array('x' => 1)), '{"x":1}', 'json encode');
throws55(function () { App\Support\Compat::jsonDecode('{'); }, 'invalid JSON');
throws55(function () { App\Support\Compat::jsonEncode(INF); }, 'unencodable JSON');

fwrite(STDOUT, "bootstrap smoke passed\n");

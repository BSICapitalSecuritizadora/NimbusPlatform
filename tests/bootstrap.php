<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

$filesystem = new Filesystem;
$compiledViewsRoot = dirname(__DIR__).'/storage/framework/views';
$effectiveUserId = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'user';
$processId = getmypid() ?: 'process';
$executionId = bin2hex(random_bytes(8));
$compiledViewsPath = $compiledViewsRoot
    .DIRECTORY_SEPARATOR
    ."testing-{$effectiveUserId}-{$processId}-{$executionId}";

$filesystem->ensureDirectoryExists($compiledViewsPath);
clearstatcache(true, $compiledViewsPath);

if (! is_writable($compiledViewsPath)) {
    throw new RuntimeException("The isolated compiled view directory is not writable: {$compiledViewsPath}");
}

putenv("VIEW_COMPILED_PATH={$compiledViewsPath}");
$_ENV['VIEW_COMPILED_PATH'] = $compiledViewsPath;
$_SERVER['VIEW_COMPILED_PATH'] = $compiledViewsPath;

register_shutdown_function(static function () use ($filesystem, $compiledViewsPath): void {
    $filesystem->deleteDirectory($compiledViewsPath);
});

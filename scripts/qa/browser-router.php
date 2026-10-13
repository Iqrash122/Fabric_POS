<?php

// Only for the isolated browser-test server; never used by the normal application.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains((string) config('database.connections.sqlite.database'), 'browser')) {
    throw new RuntimeException('The browser-test server requires an isolated SQLite browser database.');
}
Illuminate\Support\Facades\Vite::useHotFile(storage_path('framework/testing/browser-no-hot'));
$app->handleRequest(Illuminate\Http\Request::capture());

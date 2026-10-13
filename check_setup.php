<?php
$results = [
    'vendor/autoload.php' => file_exists(__DIR__ . '/vendor/autoload.php'),
    'node_modules'        => is_dir(__DIR__ . '/node_modules'),
    '.env'                => file_exists(__DIR__ . '/.env'),
    'APP_KEY set'         => trim(getenv('APP_KEY') ?: '') !== '',
];

foreach ($results as $k => $v) {
    echo ($v ? '✅' : '❌') . " $k\n";
}

// Read APP_KEY from .env directly
$env = file_get_contents(__DIR__ . '/.env');
preg_match('/^APP_KEY=(.*)$/m', $env, $m);
echo "\nAPP_KEY value: " . ($m[1] ?? '(empty)') . "\n";

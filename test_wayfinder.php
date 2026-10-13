<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$status = $kernel->call('wayfinder:generate', ['--with-form' => true]);
echo "EXIT CODE: $status\n";
echo "OUTPUT:\n" . $kernel->output() . "\n";

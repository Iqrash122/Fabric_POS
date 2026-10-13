<?php

use App\Models\BusinessSetting;
use App\Models\User;
use Database\Seeders\FabricSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

// Explicitly isolated browser fixture. Never invoked by normal installation.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || ! str_contains((string) config('database.connections.'.config('database.default').'.database'), 'browser')) {
    throw new RuntimeException('Use a separate browser-test database.');
}
Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('db:seed', ['--class' => FabricSeeder::class, '--force' => true]);
$credentials = ['email' => 'owner@example.invalid', 'password' => bin2hex(random_bytes(18))];
$user = User::firstOrNew(['email' => $credentials['email']]);
$user->name = 'Test owner';
$user->password = $credentials['password'];
$user->is_owner = true;
$user->fabric_role = 'owner';
$user->email_verified_at = now();
$user->save();
BusinessSetting::findOrFail(1)->update(['name' => 'Fabric POS']);
$credentialPath = getenv('FABRIC_TEST_CREDENTIALS') ?: sys_get_temp_dir().'/fabric-browser-credentials.json';
file_put_contents($credentialPath, json_encode($credentials));
chmod($credentialPath, 0600);
echo "Isolated Fabric POS browser fixture ready.\n";

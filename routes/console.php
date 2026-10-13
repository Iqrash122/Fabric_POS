<?php

use App\Domain\FabricInventory;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('fabric:owner {email} {--recover}', function () {
    $email = $this->argument('email');
    if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid login email.');
        return 1;
    }
    $password = $this->secret('Owner password (at least 12 characters)');
    if (strlen($password ?? '') < 12) {
        $this->error('Password must contain at least 12 characters.');
        return 1;
    }
    DB::transaction(function () use ($password, $email) {
        DB::table('number_sequences')->where('kind', 'OWNER')->lockForUpdate()->firstOrFail();
        $owner = User::where('is_owner', true)->first();
        if ($owner && ! $this->option('recover')) {
            throw new RuntimeException('Owner exists. Use --recover to reset the password.');
        }
        if ($owner && $owner->email !== $this->argument('email')) {
            throw new RuntimeException('Recovery email must match the existing owner.');
        }
        $owner ??= new User;
        $owner->name = 'Owner';
        $owner->email = $email;
        $owner->password = Hash::make($password);
        $owner->is_owner = true;
        $owner->fabric_role = 'owner';
        $owner->email_verified_at = now();
        $owner->save();
        DB::table('sessions')->where('user_id', $owner->id)->delete();
        $owner->remember_token = null;
        $owner->save();
    });
    $this->info('Fabric POS owner credentials saved.');
});

Schedule::call([FabricInventory::class, 'expireReservations'])->hourly()->name('fabric.expire-reservations')->withoutOverlapping();

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'fabric_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('fabric_role')->nullable()->index();
            });
        }

        if (Schema::hasColumn('users', 'studio_role')) {
            DB::table('users')->whereNull('fabric_role')->update(['fabric_role' => DB::raw('studio_role')]);
        }
        DB::table('users')->where('is_owner', true)->update(['fabric_role' => 'owner']);
    }

    public function down(): void
    {
        // Non-destructive for upgrades from the former combined application.
    }
};

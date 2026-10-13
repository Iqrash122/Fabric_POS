<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class FabricSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('business_settings')->insertOrIgnore([
            'id' => 1, 'name' => 'Fabric POS', 'currency' => 'PKR', 'timezone' => 'Asia/Karachi', 'country' => 'PK',
            'credit_delivery' => false, 'cost_reporting' => false, 'recent_days' => 30, 'refresh_seconds' => 60,
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('number_sequences')->insertOrIgnore([
            'kind' => 'OWNER', 'next_number' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Seeders are intentionally non-destructive: rerunning them must never reset
        // configured settings, opening cash/bank values, or historical master data.
        DB::table('fabric_settings')->insertOrIgnore([
            'id' => 1,
            'reservations_enabled' => false,
            'credit_sales_enabled' => true,
            'barcode_prefix' => 'FS',
            'default_low_stock_milli' => 5000,
            'default_low_roll_milli' => 2000,
            'walk_in_name' => 'Walk-in Customer',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([['Cash', 'cash'], ['Bank', 'bank']] as [$name, $kind]) {
            if (! DB::table('fabric_accounts')->where('name', $name)->exists()) {
                DB::table('fabric_accounts')->insert([
                    'name' => $name, 'kind' => $kind, 'opening_balance' => 0, 'active' => true,
                    'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('fabric_categories')->insertOrIgnore([
            'name' => 'General Fabric', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fabric_colors')->insertOrIgnore([
            'name' => 'Unspecified', 'hex' => null, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

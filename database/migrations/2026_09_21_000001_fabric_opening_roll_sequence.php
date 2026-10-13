<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $nextNumber = 1;
        foreach (DB::table('fabric_rolls')->where('roll_number', 'like', 'OR-%')->pluck('roll_number') as $rollNumber) {
            if (preg_match('/^OR-(\d+)$/', $rollNumber, $matches)) {
                $nextNumber = max($nextNumber, (int) $matches[1] + 1);
            }
        }

        DB::table('number_sequences')->insertOrIgnore([
            'kind' => 'OR',
            'next_number' => $nextNumber,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve numbering because opening rolls may already reference it.
    }
};

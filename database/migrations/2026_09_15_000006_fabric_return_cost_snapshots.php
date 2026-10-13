<?php

use App\Domain\Pricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_return_lines', function (Blueprint $table) {
            $table->bigInteger('cost_restored')->default(0)->after('credit_amount');
        });

        $roundHalfUp = static fn (string $value): int => Pricing::round($value);

        // Preserve deterministic COGS restoration for any rows created before this
        // migration. Only resalable returns restore inventory cost.
        DB::table('fabric_sale_lines')->orderBy('id')->chunkById(200, function ($saleLines) use ($roundHalfUp): void {
            foreach ($saleLines as $saleLine) {
                $returned = 0;
                $restored = 0;
                $lines = DB::table('fabric_return_lines')
                    ->where('sale_line_id', $saleLine->id)
                    ->orderBy('id')
                    ->get();

                foreach ($lines as $line) {
                    $current = 0;
                    if ($line->condition === 'resalable') {
                        $returned += (int) $line->quantity_milli;
                        $target = $returned >= (int) $saleLine->quantity_milli
                            ? (int) $saleLine->cost
                            : $roundHalfUp(bcdiv(
                                bcmul(Pricing::decimal($saleLine->cost), (string) $returned, 0),
                                (string) $saleLine->quantity_milli,
                                8
                            ));
                        $current = max(0, $target - $restored);
                        $restored += $current;
                    }
                    DB::table('fabric_return_lines')->where('id', $line->id)->update(['cost_restored' => $current]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('fabric_return_lines', function (Blueprint $table) {
            $table->dropColumn('cost_restored');
        });
    }
};

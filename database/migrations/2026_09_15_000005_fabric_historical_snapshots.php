<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_purchase_lines', function (Blueprint $table) {
            $table->string('product_name_snapshot')->nullable()->after('line_total');
            $table->string('color_name_snapshot')->nullable()->after('product_name_snapshot');
            $table->string('unit_snapshot', 16)->nullable()->after('color_name_snapshot');
        });
        Schema::table('fabric_sale_lines', function (Blueprint $table) {
            $table->string('product_name_snapshot')->nullable()->after('promotion_snapshot');
            $table->string('color_name_snapshot')->nullable()->after('product_name_snapshot');
            $table->string('roll_number_snapshot')->nullable()->after('color_name_snapshot');
            $table->string('unit_snapshot', 16)->nullable()->after('roll_number_snapshot');
        });

        DB::table('fabric_purchase_lines')->orderBy('id')->chunkById(200, function ($lines): void {
            foreach ($lines as $line) {
                $product = DB::table('fabric_products')->where('id', $line->product_id)->first();
                $color = DB::table('fabric_colors')->where('id', $line->color_id)->first();
                DB::table('fabric_purchase_lines')->where('id', $line->id)->update([
                    'product_name_snapshot' => $product?->name,
                    'color_name_snapshot' => $color?->name,
                    'unit_snapshot' => $product?->unit,
                ]);
            }
        });
        DB::table('fabric_sale_lines')->orderBy('id')->chunkById(200, function ($lines): void {
            foreach ($lines as $line) {
                $product = DB::table('fabric_products')->where('id', $line->product_id)->first();
                $color = DB::table('fabric_colors')->where('id', $line->color_id)->first();
                $roll = DB::table('fabric_rolls')->where('id', $line->roll_id)->first();
                DB::table('fabric_sale_lines')->where('id', $line->id)->update([
                    'product_name_snapshot' => $product?->name,
                    'color_name_snapshot' => $color?->name,
                    'roll_number_snapshot' => $roll?->roll_number,
                    'unit_snapshot' => $product?->unit,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('fabric_sale_lines', function (Blueprint $table) {
            $table->dropColumn(['product_name_snapshot', 'color_name_snapshot', 'roll_number_snapshot', 'unit_snapshot']);
        });
        Schema::table('fabric_purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['product_name_snapshot', 'color_name_snapshot', 'unit_snapshot']);
        });
    }
};

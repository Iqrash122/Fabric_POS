<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('thermal_width_mm')->default(80);
            $table->unsignedSmallInteger('barcode_width_mm')->default(70);
            $table->unsignedSmallInteger('barcode_height_mm')->default(40);
            $table->string('invoice_title')->default('Fabric Sale Invoice');
            $table->text('receipt_footer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fabric_settings', function (Blueprint $table) {
            $table->dropColumn(['thermal_width_mm', 'barcode_width_mm', 'barcode_height_mm', 'invoice_title', 'receipt_footer']);
        });
    }
};

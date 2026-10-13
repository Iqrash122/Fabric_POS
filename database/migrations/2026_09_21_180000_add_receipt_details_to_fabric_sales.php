<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_sales', function (Blueprint $table) {
            // Historical receipts did not capture tendered cash. Keep that unknown.
            $table->unsignedBigInteger('tendered')->nullable();
            $table->string('cashier_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fabric_sales', function (Blueprint $table) {
            $table->dropColumn(['tendered', 'cashier_name']);
        });
    }
};

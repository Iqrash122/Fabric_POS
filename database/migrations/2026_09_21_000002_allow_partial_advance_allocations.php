<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each advance application retains its own date and audit entry. A receipt
        // or payment can be applied to the same invoice in several installments.
        Schema::table('fabric_customer_receipt_allocations', function (Blueprint $table) {
            $table->index(['customer_receipt_id', 'sale_id'], 'fcra_receipt_sale_index');
            $table->dropUnique('fcra_receipt_sale_unique');
        });
        Schema::table('fabric_vendor_payment_allocations', function (Blueprint $table) {
            $table->index(['vendor_payment_id', 'purchase_bill_id'], 'fvpa_payment_bill_index');
            $table->dropUnique('fvpa_payment_bill_unique');
        });
    }

    public function down(): void
    {
        // Keep allocation history intact; merging it would lose application dates.
    }
};

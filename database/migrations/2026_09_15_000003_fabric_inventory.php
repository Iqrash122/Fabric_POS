<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fabric_role')->nullable()->after('is_owner')->index();
        });
        DB::table('users')->where('is_owner', true)->update(['fabric_role' => 'owner']);

        Schema::create('fabric_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('reservations_enabled')->default(false);
            $table->boolean('credit_sales_enabled')->default(true);
            $table->string('barcode_prefix', 12)->default('FS');
            $table->unsignedBigInteger('default_low_stock_milli')->default(5000);
            $table->unsignedBigInteger('default_low_roll_milli')->default(2000);
            $table->string('walk_in_name')->default('Walk-in Customer');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('fabric_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind')->index(); // cash | bank
            $table->bigInteger('opening_balance')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('fabric_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('fabric_colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('hex', 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('fabric_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('fabric_categories');
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('unit')->default('meter'); // meter | piece
            $table->bigInteger('default_selling_price')->default(0);
            $table->unsignedBigInteger('low_stock_milli')->default(5000);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['category_id', 'active']);
        });

        Schema::create('fabric_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('shop_name');
            $table->string('owner_name')->nullable();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index('shop_name');
        });

        Schema::create('fabric_purchase_bills', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('fabric_vendors');
            $table->string('supplier_reference')->nullable();
            $table->dateTimeTz('bill_date')->index();
            $table->string('state')->default('draft')->index();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('additional_cost')->default(0);
            $table->bigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->dateTimeTz('posted_at')->nullable()->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('fabric_purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_bill_id')->constrained('fabric_purchase_bills');
            $table->foreignId('product_id')->constrained('fabric_products');
            $table->foreignId('color_id')->constrained('fabric_colors');
            $table->unsignedBigInteger('ordered_qty_milli');
            $table->unsignedBigInteger('received_qty_milli')->default(0);
            $table->bigInteger('purchase_rate');
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('allocated_cost')->default(0);
            $table->bigInteger('line_total');
            $table->timestampsTz();
            $table->index(['purchase_bill_id', 'product_id']);
        });

        Schema::create('fabric_goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('fabric_vendors');
            $table->foreignId('purchase_bill_id')->nullable()->constrained('fabric_purchase_bills');
            $table->dateTimeTz('received_at')->index();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_rolls', function (Blueprint $table) {
            $table->id();
            $table->string('roll_number')->unique();
            $table->string('barcode')->unique();
            $table->foreignId('product_id')->constrained('fabric_products');
            $table->foreignId('color_id')->constrained('fabric_colors');
            $table->foreignId('vendor_id')->nullable()->constrained('fabric_vendors');
            $table->foreignId('goods_receipt_id')->nullable()->constrained('fabric_goods_receipts');
            $table->foreignId('purchase_line_id')->nullable()->constrained('fabric_purchase_lines');
            $table->foreignId('parent_roll_id')->nullable()->constrained('fabric_rolls');
            $table->unsignedBigInteger('original_qty_milli');
            $table->bigInteger('current_qty_milli');
            $table->unsignedBigInteger('reserved_qty_milli')->default(0);
            $table->bigInteger('unit_cost')->default(0);
            $table->bigInteger('selling_price')->default(0);
            $table->string('condition')->default('sellable')->index(); // sellable, damaged, remnant, returned
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['product_id', 'color_id', 'active']);
        });

        Schema::create('fabric_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('fabric_goods_receipts');
            $table->foreignId('purchase_line_id')->nullable()->constrained('fabric_purchase_lines');
            $table->foreignId('roll_id')->unique()->constrained('fabric_rolls');
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('unit_cost');
            $table->timestampsTz();
        });

        Schema::create('fabric_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_id')->constrained('fabric_rolls');
            $table->foreignId('product_id')->constrained('fabric_products');
            $table->foreignId('color_id')->constrained('fabric_colors');
            $table->string('kind')->index();
            $table->bigInteger('qty_delta_milli');
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->string('event_key')->unique();
            $table->text('reason')->nullable();
            $table->dateTimeTz('effective_at')->index();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampsTz();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('fabric_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->string('party_type')->index(); // customer | vendor
            $table->unsignedBigInteger('party_id')->index();
            $table->string('kind')->index(); // receivable | payable | advance
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->text('reason');
            $table->timestampsTz();
            $table->index(['party_type', 'party_id', 'kind']);
        });

        Schema::create('fabric_opening_balance_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_balance_id')->constrained('fabric_opening_balances');
            $table->string('target_type'); // sale | purchase_bill | customer_receipt | vendor_payment
            $table->unsignedBigInteger('target_id');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->index(['target_type', 'target_id'], 'foba_target_index');
        });

        Schema::create('fabric_vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('fabric_vendors');
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->string('idempotency_key')->unique();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_vendor_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_payment_id')->constrained('fabric_vendor_payments');
            $table->foreignId('purchase_bill_id')->constrained('fabric_purchase_bills');
            $table->bigInteger('amount');
            $table->timestampsTz();
            $table->unique(['vendor_payment_id', 'purchase_bill_id'], 'fvpa_payment_bill_unique');
        });

        Schema::create('fabric_vendor_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('fabric_vendors');
            $table->foreignId('purchase_bill_id')->nullable()->constrained('fabric_purchase_bills');
            $table->string('cadence'); // daily, weekly, monthly
            $table->bigInteger('amount');
            $table->dateTimeTz('due_at')->index();
            $table->string('status')->default('due')->index();
            $table->foreignId('payment_id')->nullable()->constrained('fabric_vendor_payments');
            $table->unsignedInteger('sequence')->default(1);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->dateTimeTz('sale_at')->index();
            $table->string('state')->default('posted')->index();
            $table->bigInteger('gross')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->bigInteger('paid')->default(0);
            $table->bigInteger('balance')->default(0);
            $table->boolean('credit_authorized')->default(false);
            $table->text('manual_discount_reason')->nullable();
            $table->string('idempotency_key')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('fabric_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('fabric_sales');
            $table->foreignId('roll_id')->constrained('fabric_rolls');
            $table->foreignId('product_id')->constrained('fabric_products');
            $table->foreignId('color_id')->constrained('fabric_colors');
            $table->string('line_type')->default('fabric'); // fabric | piece
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('unit_price');
            $table->bigInteger('gross');
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('net');
            $table->bigInteger('cost');
            $table->json('promotion_snapshot')->nullable();
            $table->unsignedBigInteger('returned_qty_milli')->default(0);
            $table->timestampsTz();
            $table->index(['sale_id', 'roll_id']);
        });

        Schema::create('fabric_sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('fabric_sales');
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->bigInteger('amount');
            $table->string('external_reference')->nullable();
            $table->dateTimeTz('effective_at')->index();
            $table->timestampsTz();
        });

        Schema::create('fabric_customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->string('idempotency_key')->unique();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_customer_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained('fabric_customer_receipts');
            $table->foreignId('sale_id')->constrained('fabric_sales');
            $table->bigInteger('amount');
            $table->timestampsTz();
            $table->unique(['customer_receipt_id', 'sale_id'], 'fcra_receipt_sale_unique');
        });

        Schema::create('fabric_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('sale_id')->constrained('fabric_sales');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->dateTimeTz('effective_at')->index();
            $table->bigInteger('credit_amount')->default(0);
            $table->string('resolution')->default('credit'); // credit | refund | reduce_balance
            $table->text('reason');
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });

        Schema::create('fabric_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('fabric_returns');
            $table->foreignId('sale_line_id')->constrained('fabric_sale_lines');
            $table->unsignedBigInteger('quantity_milli');
            $table->string('condition'); // resalable | damaged
            $table->bigInteger('credit_amount');
            $table->foreignId('new_roll_id')->nullable()->constrained('fabric_rolls');
            $table->timestampsTz();
        });

        Schema::create('fabric_customer_credits', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->foreignId('return_id')->constrained('fabric_returns');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->timestampsTz();
        });

        Schema::create('fabric_credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_id')->constrained('fabric_customer_credits');
            $table->foreignId('sale_id')->constrained('fabric_sales');
            $table->bigInteger('amount');
            $table->timestampsTz();
        });

        Schema::create('fabric_customer_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('credit_id')->constrained('fabric_customer_credits');
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });

        Schema::create('fabric_vendor_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('fabric_vendors');
            $table->foreignId('purchase_bill_id')->constrained('fabric_purchase_bills');
            $table->dateTimeTz('effective_at')->index();
            $table->bigInteger('credit_amount')->default(0);
            $table->text('reason');
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });

        Schema::create('fabric_vendor_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_return_id')->constrained('fabric_vendor_returns');
            $table->foreignId('roll_id')->constrained('fabric_rolls');
            $table->foreignId('purchase_line_id')->constrained('fabric_purchase_lines');
            $table->unsignedBigInteger('quantity_milli');
            $table->bigInteger('credit_amount');
            $table->timestampsTz();
        });

        Schema::create('fabric_vendor_credits', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_id')->constrained('fabric_vendors');
            $table->foreignId('vendor_return_id')->constrained('fabric_vendor_returns');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->timestampsTz();
        });

        Schema::create('fabric_vendor_credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_credit_id')->constrained('fabric_vendor_credits');
            $table->foreignId('purchase_bill_id')->constrained('fabric_purchase_bills');
            $table->bigInteger('amount');
            $table->timestampsTz();
        });

        Schema::create('fabric_vendor_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('vendor_credit_id')->constrained('fabric_vendor_credits');
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });

        Schema::create('fabric_account_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->string('direction'); // in | out
            $table->bigInteger('amount');
            $table->string('kind')->index();
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id');
            $table->dateTimeTz('effective_at')->index();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('fabric_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('category')->index();
            $table->bigInteger('amount');
            $table->foreignId('account_id')->constrained('fabric_accounts');
            $table->dateTimeTz('effective_at')->index();
            $table->text('note')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });

        Schema::create('fabric_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('from_account_id')->constrained('fabric_accounts');
            $table->foreignId('to_account_id')->constrained('fabric_accounts');
            $table->bigInteger('amount');
            $table->dateTimeTz('effective_at')->index();
            $table->text('note')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestampsTz();
        });

        Schema::create('fabric_promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('percent', 8, 4);
            $table->dateTimeTz('starts_at')->index();
            $table->dateTimeTz('ends_at')->index();
            $table->integer('priority')->default(100);
            $table->boolean('stackable')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('fabric_promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('fabric_promotions');
            $table->string('target_type'); // product | category | color
            $table->unsignedBigInteger('target_id');
            $table->timestampsTz();
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('fabric_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_id')->constrained('fabric_rolls');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->unsignedBigInteger('quantity_milli');
            $table->string('status')->default('active')->index();
            $table->dateTimeTz('expires_at')->nullable()->index();
            $table->text('note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('state')->default('draft')->index();
            $table->dateTimeTz('counted_at')->index();
            $table->foreignId('created_by')->constrained('users');
            $table->dateTimeTz('posted_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('fabric_stock_counts');
            $table->foreignId('roll_id')->constrained('fabric_rolls');
            $table->bigInteger('system_qty_milli');
            $table->bigInteger('counted_qty_milli');
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->unique(['stock_count_id', 'roll_id']);
        });

        Schema::create('fabric_marketing_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('name')->nullable();
            $table->boolean('whatsapp_consent')->default(false);
            $table->dateTimeTz('whatsapp_consent_at')->nullable();
            $table->string('whatsapp_consent_source')->nullable();
            $table->boolean('sms_consent')->default(false);
            $table->dateTimeTz('sms_consent_at')->nullable();
            $table->string('sms_consent_source')->nullable();
            $table->boolean('opted_out')->default(false)->index();
            $table->dateTimeTz('opted_out_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fabric_marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel'); // whatsapp | sms
            $table->text('message');
            $table->string('state')->default('draft')->index();
            $table->string('provider')->nullable();
            $table->dateTimeTz('approved_at')->nullable();
            $table->dateTimeTz('launched_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('fabric_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('fabric_marketing_campaigns');
            $table->foreignId('contact_id')->constrained('fabric_marketing_contacts');
            $table->string('state')->default('pending');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->dateTimeTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['campaign_id', 'contact_id']);
        });

        Schema::create('fabric_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('key');
            $table->string('payload_hash');
            $table->string('result_type')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestampsTz();
            $table->unique(['scope', 'key']);
        });

        foreach (['FB', 'GR', 'FP', 'FS', 'FR', 'CR', 'RF', 'VR', 'VF', 'EX', 'TR', 'SC'] as $kind) {
            DB::table('number_sequences')->updateOrInsert(
                ['kind' => $kind],
                ['next_number' => 1, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        DB::table('fabric_settings')->insert([
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
    }

    public function down(): void
    {
        $tables = [
            'fabric_idempotency', 'fabric_campaign_recipients', 'fabric_marketing_campaigns',
            'fabric_marketing_contacts', 'fabric_stock_count_lines',
            'fabric_stock_counts', 'fabric_reservations', 'fabric_promotion_targets', 'fabric_promotions',
            'fabric_transfers', 'fabric_expenses', 'fabric_account_entries', 'fabric_vendor_refunds',
            'fabric_vendor_credit_allocations', 'fabric_vendor_credits', 'fabric_vendor_return_lines',
            'fabric_vendor_returns', 'fabric_customer_refunds', 'fabric_credit_allocations',
            'fabric_customer_credits', 'fabric_return_lines', 'fabric_returns',
            'fabric_customer_receipt_allocations', 'fabric_customer_receipts', 'fabric_sale_payments',
            'fabric_sale_lines', 'fabric_sales', 'fabric_vendor_installments',
            'fabric_vendor_payment_allocations', 'fabric_vendor_payments', 'fabric_opening_balance_applications', 'fabric_opening_balances',
            'fabric_stock_movements', 'fabric_receipt_lines', 'fabric_rolls', 'fabric_goods_receipts',
            'fabric_purchase_lines', 'fabric_purchase_bills', 'fabric_vendors', 'fabric_products',
            'fabric_colors', 'fabric_categories', 'fabric_accounts', 'fabric_settings',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fabric_role');
        });
    }
};

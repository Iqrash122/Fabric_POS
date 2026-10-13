<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricInventory;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private int $categoryId;
    private int $colorId;
    private int $productId;
    private int $vendorId;
    private int $cashId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['is_owner' => true, 'fabric_role' => 'owner'])->save();
        $this->actingAs($this->owner);
        $this->categoryId = (int) DB::table('fabric_categories')->where('name', 'General Fabric')->value('id');
        $this->colorId = (int) DB::table('fabric_colors')->where('name', 'Unspecified')->value('id');
        $this->cashId = (int) DB::table('fabric_accounts')->where('name', 'Cash')->value('id');
        $this->productId = FabricInventory::saveProduct([
            'category_id' => $this->categoryId,
            'name' => 'Premium Cotton',
            'sku' => 'COT-001',
            'barcode' => 'PROD-COT-001',
            'unit' => 'meter',
            'default_selling_price' => '20.00',
            'low_stock' => '5.000',
            'active' => true,
        ]);
        $this->vendorId = FabricInventory::saveVendor([
            'shop_name' => 'Textile House',
            'owner_name' => 'Vendor Owner',
            'phone' => '+923001111111',
            'active' => true,
        ]);
    }

    private function customer(string $name = 'Ayesha Fabric'): Customer
    {
        return Customer::create([
            'name' => $name,
            'phone' => '03001234567',
            'normalized_phone' => '+923001234567',
            'active' => true,
        ]);
    }

    /** @return array{bill:int,line:int} */
    private function purchase(string $qty = '100.000', string $rate = '10.00', bool $post = true): array
    {
        $bill = FabricInventory::createPurchase([
            'vendor_id' => $this->vendorId,
            'supplier_reference' => 'SUP-'.Str::random(6),
            'bill_date' => now()->subHour()->toIso8601String(),
            'lines' => [[
                'product_id' => $this->productId,
                'color_id' => $this->colorId,
                'quantity' => $qty,
                'purchase_rate' => $rate,
                'discount' => '0',
                'additional_cost' => '0',
            ]],
        ]);
        if ($post) {
            FabricInventory::postPurchase($bill);
        }

        return ['bill' => $bill, 'line' => (int) DB::table('fabric_purchase_lines')->where('purchase_bill_id', $bill)->value('id')];
    }

    private function receive(int $billId, int $lineId, string $qty = '40.000', ?string $rollNumber = null): int
    {
        $receipt = FabricInventory::receivePurchase([
            'purchase_bill_id' => $billId,
            'vendor_id' => $this->vendorId,
            'received_at' => now()->toIso8601String(),
            'lines' => [[
                'purchase_line_id' => $lineId,
                'quantity' => $qty,
                'roll_number' => $rollNumber,
                'selling_price' => '20.00',
            ]],
        ]);

        return (int) DB::table('fabric_rolls')->where('goods_receipt_id', $receipt)->value('id');
    }

    /** @param array<int,array<string,mixed>> $payments */
    private function sale(int $rollId, string $qty, ?int $customerId = null, array $payments = [], bool $credit = false, string $role = 'owner'): int
    {
        return FabricInventory::postSale([
            'customer_id' => $customerId,
            'sale_at' => now()->addMinute()->toIso8601String(),
            'credit_authorized' => $credit,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'roll_id' => $rollId,
                'quantity' => $qty,
                'unit_price' => '20.00',
                'manual_discount' => '0',
            ]],
            'payments' => $payments,
        ], $role);
    }

    public function test_purchase_bill_does_not_add_stock_and_partial_receipt_adds_only_received_quantity(): void
    {
        $purchase = $this->purchase('100.000', '10.00', false);
        $this->assertSame(0, DB::table('fabric_rolls')->count());
        FabricInventory::postPurchase($purchase['bill']);
        $this->assertSame(0, DB::table('fabric_rolls')->count());

        $rollId = $this->receive($purchase['bill'], $purchase['line'], '60.000', 'ROLL-60');
        $this->assertSame(60000, (int) DB::table('fabric_rolls')->where('id', $rollId)->value('current_qty_milli'));
        $this->assertSame(60000, (int) DB::table('fabric_purchase_lines')->where('id', $purchase['line'])->value('received_qty_milli'));
        $this->assertSame(1, DB::table('fabric_stock_movements')->where('kind', 'receipt')->count());
    }

    public function test_catalogue_product_needs_opening_stock_before_barcode_or_name_can_add_it_to_pos(): void
    {
        $codes = ['PROD-COT-001', 'Premium Cotton'];
        foreach ($codes as $code) {
            $this->getJson('/fabric/barcode/'.rawurlencode($code))
                ->assertOk()
                ->assertJsonPath('record.id', $this->productId)
                ->assertJsonPath('has_rolls', false)
                ->assertJsonCount(0, 'rolls');
        }
        $this->assertDatabaseCount('fabric_rolls', 0);

        $this->from('/fabric/pos')->post('/fabric/action/opening-stock', [
            'product_id' => $this->productId,
            'color_id' => $this->colorId,
            'quantity' => '2.500',
            'unit_cost' => '10.00',
            'selling_price' => '20.00',
            'effective_at' => now()->toIso8601String(),
            'reason' => 'Existing fabric on hand',
        ])->assertSessionHasNoErrors()->assertRedirect('/fabric/pos');

        $rollId = (int) DB::table('fabric_rolls')->where('product_id', $this->productId)->value('id');
        foreach ($codes as $code) {
            $this->getJson('/fabric/barcode/'.rawurlencode($code))
                ->assertOk()
                ->assertJsonPath('has_rolls', true)
                ->assertJsonCount(1, 'rolls')
                ->assertJsonPath('rolls.0.id', $rollId)
                ->assertJsonPath('rolls.0.available_qty_milli', 2500);
        }

        $this->sale($rollId, '2.500', payments: [['account_id' => $this->cashId, 'amount' => '50.00']]);
        $this->getJson('/fabric/barcode/PROD-COT-001')
            ->assertOk()
            ->assertJsonPath('has_rolls', true)
            ->assertJsonCount(0, 'rolls');
        $this->assertDatabaseHas('fabric_stock_movements', ['roll_id' => $rollId, 'kind' => 'opening_stock', 'qty_delta_milli' => 2500]);
        $this->assertDatabaseHas('fabric_stock_movements', ['roll_id' => $rollId, 'kind' => 'sale', 'qty_delta_milli' => -2500]);
    }

    public function test_pos_posting_is_idempotent_and_deducts_exact_roll_once(): void
    {
        $purchase = $this->purchase();
        $roll = $this->receive($purchase['bill'], $purchase['line'], '40.000', 'ROLL-IDEMP');
        $key = (string) Str::uuid();
        $payload = [
            'sale_at' => now()->addMinute()->toIso8601String(),
            'idempotency_key' => $key,
            'lines' => [['roll_id' => $roll, 'quantity' => '5.000', 'unit_price' => '20.00', 'manual_discount' => '0']],
            'payments' => [['account_id' => $this->cashId, 'amount' => '100.00']],
        ];
        $first = FabricInventory::postSale($payload, 'owner');
        $second = FabricInventory::postSale($payload, 'owner');

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('fabric_sales')->count());
        $this->assertSame(35000, (int) DB::table('fabric_rolls')->where('id', $roll)->value('current_qty_milli'));
        $this->assertSame(1, DB::table('fabric_stock_movements')->where('kind', 'sale')->count());
        $this->assertSame(1, DB::table('fabric_sale_payments')->count());
    }

    public function test_promotion_with_split_overpayment_records_only_net_collection(): void
    {
        $purchase = $this->purchase('10.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'PROMO-CHANGE');
        FabricInventory::savePromotion([
            'name' => 'Checkout discount', 'percent' => '10', 'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(), 'priority' => 1, 'stackable' => false, 'active' => true,
            'targets' => [['type' => 'product', 'id' => $this->productId]],
        ]);
        $bank = (int) DB::table('fabric_accounts')->where('kind', 'bank')->value('id');
        $sale = $this->sale($roll, '5.000', payments: [
            ['account_id' => $this->cashId, 'amount' => '50.00'],
            ['account_id' => $bank, 'amount' => '60.00'],
        ]);
        $this->assertDatabaseHas('fabric_sales', ['id' => $sale, 'gross' => 10000, 'discount' => 1000, 'total' => 9000, 'paid' => 9000, 'balance' => 0]);
        $this->assertDatabaseHas('fabric_sale_payments', ['sale_id' => $sale, 'account_id' => $bank, 'amount' => 6000]);
        $this->assertDatabaseHas('fabric_sale_payments', ['sale_id' => $sale, 'account_id' => $this->cashId, 'amount' => 3000]);
        $this->assertSame(9000, (int) DB::table('fabric_account_entries')->where('kind', 'sale_receipt')->sum('amount'));
    }

    public function test_barcode_and_exact_name_take_priority_over_similar_unstocked_product_names(): void
    {
        $purchase = $this->purchase();
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'LOOKUP-ROLL');
        DB::table('fabric_products')->where('id', $this->productId)->update(['name' => 'Cotton']);
        $other = FabricInventory::saveProduct([
            'category_id' => $this->categoryId, 'name' => 'Premium Cotton', 'sku' => 'EXACT-SKU', 'barcode' => 'Cotton',
            'unit' => 'meter', 'default_selling_price' => '20.00', 'low_stock' => '1',
        ]);
        $this->getJson('/fabric/barcode/Cotton')->assertOk()->assertJsonPath('record.id', $other);
        $this->getJson('/fabric/barcode/Premium%20Cotton')->assertOk()->assertJsonPath('record.id', $other);
        $this->getJson('/fabric/barcode/LOOKUP-ROLL')->assertOk()->assertJsonPath('record.id', $roll);
    }

    public function test_unavailable_roll_barcodes_are_rejected_before_entering_the_cart(): void
    {
        $purchase = $this->purchase();
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'UNAVAILABLE-ROLL');
        foreach ([['current_qty_milli' => 0], ['reserved_qty_milli' => 10000], ['condition' => 'damaged'], ['active' => false]] as $changes) {
            DB::table('fabric_rolls')->where('id', $roll)->update(['current_qty_milli' => 10000, 'reserved_qty_milli' => 0, 'condition' => 'sellable', 'active' => true, ...$changes]);
            $this->getJson('/fabric/barcode/UNAVAILABLE-ROLL')->assertUnprocessable()->assertJsonStructure(['message']);
        }
    }

    public function test_same_roll_cannot_be_added_twice_to_bypass_stock_limit(): void
    {
        $purchase = $this->purchase('10.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'ROLL-DUP');
        try {
            FabricInventory::postSale([
                'sale_at' => now()->toIso8601String(),
                'idempotency_key' => (string) Str::uuid(),
                'lines' => [
                    ['roll_id' => $roll, 'quantity' => '6.000', 'unit_price' => '20.00'],
                    ['roll_id' => $roll, 'quantity' => '6.000', 'unit_price' => '20.00'],
                ],
                'payments' => [['account_id' => $this->cashId, 'amount' => '240.00']],
            ], 'owner');
            $this->fail('Expected duplicate roll rejection.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('fabric_sales')->count());
            $this->assertSame(10000, (int) DB::table('fabric_rolls')->where('id', $roll)->value('current_qty_milli'));
        }
    }

    public function test_split_payments_post_atomically_and_zero_or_negative_payment_is_rejected(): void
    {
        $bank = DB::table('fabric_accounts')->where('name', 'Bank')->value('id');
        $purchase = $this->purchase('10.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'ROLL-SPLIT');
        $sale = $this->sale($roll, '5.000', null, [
            ['account_id' => $this->cashId, 'amount' => '60.00'],
            ['account_id' => (int) $bank, 'amount' => '40.00'],
        ]);
        $this->assertSame(2, DB::table('fabric_sale_payments')->where('sale_id', $sale)->count());
        $this->assertSame(10000, (int) DB::table('fabric_sales')->where('id', $sale)->value('paid'));

        $this->expectException(ValidationException::class);
        FabricInventory::postSale([
            'sale_at' => now()->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $roll, 'quantity' => '1.000', 'unit_price' => '20.00']],
            'payments' => [['account_id' => $this->cashId, 'amount' => '0.00']],
        ], 'owner');
    }

    public function test_credit_sale_requires_identified_customer_and_privileged_role(): void
    {
        $purchase = $this->purchase('10.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'ROLL-CREDIT');
        try {
            $this->sale($roll, '2.000', null, [], true, 'owner');
            $this->fail('Expected identified customer requirement.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('fabric_sales')->count());
        }

        $customer = $this->customer();
        $this->expectException(ValidationException::class);
        $this->sale($roll, '2.000', $customer->id, [], true, 'cashier');
    }

    public function test_customer_receipt_allocates_debt_and_keeps_excess_as_advance(): void
    {
        $customer = $this->customer();
        $purchase = $this->purchase('20.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '20.000', 'ROLL-CUST');
        $sale = $this->sale($roll, '10.000', $customer->id, [], true);
        $receipt = FabricInventory::customerReceipt([
            'customer_id' => $customer->id,
            'account_id' => $this->cashId,
            'amount' => '100.00',
            'effective_at' => now()->addMinutes(2)->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['sale_id' => $sale, 'amount' => '60.00']],
        ]);
        $this->assertSame(14000, FabricInventory::saleOutstanding($sale));
        $this->assertSame(4000, FabricInventory::customerReceiptAvailable($receipt));
        FabricInventory::applyCustomerAdvance(['receipt_id' => $receipt, 'sale_id' => $sale, 'amount' => '40.00']);
        $this->assertSame(10000, FabricInventory::saleOutstanding($sale));
        $this->assertSame(0, FabricInventory::customerReceiptAvailable($receipt));
    }

    public function test_vendor_payment_allocates_bill_and_keeps_excess_as_advance(): void
    {
        $purchase = $this->purchase('100.000', '10.00');
        $payment = FabricInventory::vendorPayment([
            'vendor_id' => $this->vendorId,
            'account_id' => $this->cashId,
            'amount' => '400.00',
            'effective_at' => now()->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['purchase_bill_id' => $purchase['bill'], 'amount' => '300.00']],
        ]);
        $this->assertSame(70000, FabricInventory::vendorOutstanding($purchase['bill']));
        $this->assertSame(10000, FabricInventory::vendorPaymentAvailable($payment));
        FabricInventory::applyVendorAdvance(['payment_id' => $payment, 'purchase_bill_id' => $purchase['bill'], 'amount' => '100.00']);
        $this->assertSame(60000, FabricInventory::vendorOutstanding($purchase['bill']));
        $this->assertSame(0, FabricInventory::vendorPaymentAvailable($payment));
    }

    public function test_resalable_return_creates_separate_remnant_and_refunds_original_discounted_amount(): void
    {
        $customer = $this->customer();
        $purchase = $this->purchase('20.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '20.000', 'ROLL-RETURN');
        FabricInventory::savePromotion([
            'name' => 'Ten percent', 'percent' => '10', 'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addDay()->toIso8601String(), 'priority' => 1, 'stackable' => false, 'active' => true,
            'targets' => [['type' => 'product', 'id' => $this->productId]],
        ]);
        $sale = $this->sale($roll, '10.000', $customer->id, [['account_id' => $this->cashId, 'amount' => '180.00']]);
        $line = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        $return = FabricInventory::customerReturn([
            'sale_id' => $sale,
            'effective_at' => now()->addMinutes(3)->toIso8601String(),
            'resolution' => 'refund',
            'reason' => 'Customer returned cut fabric',
            'account_id' => $this->cashId,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['sale_line_id' => $line->id, 'quantity' => '2.000', 'condition' => 'resalable']],
        ]);
        $returned = DB::table('fabric_return_lines')->where('return_id', $return)->firstOrFail();
        $remnant = DB::table('fabric_rolls')->where('id', $returned->new_roll_id)->firstOrFail();
        $this->assertSame('remnant', $remnant->condition);
        $this->assertSame((int) $roll, (int) $remnant->parent_roll_id);
        $this->assertSame(2000, (int) $remnant->current_qty_milli);
        $this->assertSame(3600, (int) $returned->credit_amount);
        $this->assertSame(1, DB::table('fabric_customer_refunds')->count());
    }

    public function test_walk_in_return_cannot_be_retained_as_anonymous_credit(): void
    {
        $purchase = $this->purchase('10.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '10.000', 'ROLL-WALKIN');
        $sale = $this->sale($roll, '2.000', null, [['account_id' => $this->cashId, 'amount' => '40.00']]);
        $line = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        $this->expectException(ValidationException::class);
        FabricInventory::customerReturn([
            'sale_id' => $sale, 'effective_at' => now()->addMinute()->toIso8601String(),
            'resolution' => 'credit', 'reason' => 'Walk in return', 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'condition' => 'resalable']],
        ]);
    }

    public function test_vendor_return_reduces_physical_stock_and_creates_credit_against_exact_purchase(): void
    {
        $purchase = $this->purchase('20.000');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '20.000', 'ROLL-VRET');
        $return = FabricInventory::vendorReturn([
            'vendor_id' => $this->vendorId,
            'purchase_bill_id' => $purchase['bill'],
            'effective_at' => now()->addMinute()->toIso8601String(),
            'reason' => 'Supplier quality issue',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $roll, 'quantity' => '5.000']],
        ]);
        $this->assertSame(15000, (int) DB::table('fabric_rolls')->where('id', $roll)->value('current_qty_milli'));
        $this->assertDatabaseHas('fabric_vendor_credits', ['vendor_return_id' => $return, 'amount' => 5000]);
        $this->assertSame(15000, FabricInventory::vendorOutstanding($purchase['bill']));
    }

    public function test_opening_debt_and_advance_are_not_silently_netted(): void
    {
        $customer = $this->customer();
        $receivable = FabricInventory::createOpeningBalance([
            'party_type' => 'customer', 'party_id' => $customer->id, 'kind' => 'receivable',
            'amount' => '100.00', 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Legacy debt',
        ]);
        $advance = FabricInventory::createOpeningBalance([
            'party_type' => 'customer', 'party_id' => $customer->id, 'kind' => 'advance',
            'amount' => '25.00', 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Legacy advance',
        ]);
        $summary = FabricInventory::customerSummary($customer->id);
        $this->assertSame(10000, $summary['outstanding']);
        $this->assertSame(2500, $summary['advance']);

        FabricInventory::customerReceipt([
            'customer_id' => $customer->id, 'account_id' => $this->cashId, 'amount' => '30.00',
            'effective_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'opening_allocations' => [['opening_balance_id' => $receivable, 'amount' => '30.00']],
        ]);
        $this->assertSame(7000, FabricInventory::openingRemaining($receivable));
        $this->assertSame(2500, FabricInventory::openingRemaining($advance));
    }

    public function test_opening_stock_creates_audited_roll_without_counting_as_purchase(): void
    {
        $roll = FabricInventory::openingStock([
            'product_id' => $this->productId,
            'color_id' => $this->colorId,
            'vendor_id' => $this->vendorId,
            'quantity' => '2.750',
            'unit_cost' => '12.00',
            'selling_price' => '20.00',
            'roll_number' => 'OPEN-2750',
            'barcode' => 'OPEN-BAR-2750',
            'effective_at' => now()->subHour()->toIso8601String(),
            'reason' => 'Physical stock on hand at system go-live',
        ]);

        $this->assertDatabaseHas('fabric_rolls', [
            'id' => $roll,
            'roll_number' => 'OPEN-2750',
            'barcode' => 'OPEN-BAR-2750',
            'original_qty_milli' => 2750,
            'current_qty_milli' => 2750,
            'unit_cost' => 1200,
            'selling_price' => 2000,
        ]);
        $this->assertDatabaseHas('fabric_stock_movements', [
            'roll_id' => $roll,
            'kind' => 'opening_stock',
            'qty_delta_milli' => 2750,
            'reference_type' => 'opening_stock',
            'reference_id' => $roll,
        ]);
        $this->assertSame(0, DB::table('fabric_purchase_bills')->count());
        $this->assertSame(0, DB::table('fabric_goods_receipts')->count());

        $dashboard = FabricInventory::dashboard(now()->subDay(), now()->addDay());
        $this->assertSame(0, $dashboard['purchases']);
        $this->assertSame(3300, $dashboard['stock_value']);
    }

    public function test_profit_uses_exact_roll_cost_and_only_resalable_returns_restore_cogs(): void
    {
        $customer = $this->customer();
        $purchase = $this->purchase('20.000', '10.00');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '20.000', 'ROLL-PROFIT');
        $sale = $this->sale($roll, '10.000', $customer->id, [['account_id' => $this->cashId, 'amount' => '200.00']]);
        $line = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        FabricInventory::customerReturn([
            'sale_id' => $sale, 'effective_at' => now()->addMinutes(2)->toIso8601String(), 'resolution' => 'refund',
            'reason' => 'One resalable', 'account_id' => $this->cashId, 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['sale_line_id' => $line->id, 'quantity' => '2.000', 'condition' => 'resalable']],
        ]);
        FabricInventory::customerReturn([
            'sale_id' => $sale, 'effective_at' => now()->addMinutes(3)->toIso8601String(), 'resolution' => 'refund',
            'reason' => 'One damaged', 'account_id' => $this->cashId, 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'condition' => 'damaged']],
        ]);
        $dashboard = FabricInventory::dashboard(now()->subDay(), now()->addDay());
        $this->assertSame(14000, $dashboard['net_sales']);
        $this->assertSame(8000, $dashboard['cogs']);
        $this->assertSame(6000, $dashboard['gross_profit']);
    }

    public function test_draft_purchase_can_be_edited_before_receipt_but_not_after_goods_are_received(): void
    {
        $purchase = $this->purchase('10.000', '10.00', false);

        FabricInventory::updatePurchaseDraft($purchase['bill'], [
            'vendor_id' => $this->vendorId,
            'supplier_reference' => 'EDITED-SUPPLIER-REF',
            'bill_date' => now()->subMinutes(30)->toIso8601String(),
            'notes' => 'Corrected before receipt',
            'lines' => [[
                'product_id' => $this->productId,
                'color_id' => $this->colorId,
                'quantity' => '12.000',
                'purchase_rate' => '11.00',
                'discount' => '0.00',
                'additional_cost' => '0.00',
            ]],
        ]);

        $bill = DB::table('fabric_purchase_bills')->where('id', $purchase['bill'])->firstOrFail();
        $line = DB::table('fabric_purchase_lines')->where('purchase_bill_id', $purchase['bill'])->firstOrFail();
        $this->assertSame('EDITED-SUPPLIER-REF', $bill->supplier_reference);
        $this->assertSame('Corrected before receipt', $bill->notes);
        $this->assertSame(13200, (int) $bill->total);
        $this->assertSame(12000, (int) $line->ordered_qty_milli);
        $this->assertSame(1100, (int) $line->purchase_rate);

        $this->receive($purchase['bill'], (int) $line->id, '1.000', 'ROLL-DRAFT-LOCK');

        $this->expectException(ValidationException::class);
        FabricInventory::updatePurchaseDraft($purchase['bill'], [
            'vendor_id' => $this->vendorId,
            'bill_date' => now()->toIso8601String(),
            'lines' => [[
                'product_id' => $this->productId,
                'color_id' => $this->colorId,
                'quantity' => '13.000',
                'purchase_rate' => '11.00',
            ]],
        ]);
    }

    public function test_repeated_partial_returns_reconcile_exact_frozen_credit_and_cogs_rounding(): void
    {
        $customer = $this->customer('Rounding Customer');
        $purchase = $this->purchase('3.000', '0.01');
        $roll = $this->receive($purchase['bill'], $purchase['line'], '3.000', 'ROLL-ROUNDING');
        $sale = FabricInventory::postSale([
            'customer_id' => $customer->id,
            'sale_at' => now()->addMinute()->toIso8601String(),
            'manual_discount_reason' => 'One cent approved rounding test discount',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'roll_id' => $roll,
                'quantity' => '3.000',
                'unit_price' => '0.01',
                'manual_discount' => '0.01',
            ]],
            'payments' => [['account_id' => $this->cashId, 'amount' => '0.02']],
        ], 'owner');
        $line = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        $this->assertSame(2, (int) $line->net);
        $this->assertSame(3, (int) $line->cost);

        foreach ([2, 3, 4] as $minutes) {
            FabricInventory::customerReturn([
                'sale_id' => $sale,
                'effective_at' => now()->addMinutes($minutes)->toIso8601String(),
                'resolution' => 'credit',
                'reason' => 'One of three equal partial returns',
                'idempotency_key' => (string) Str::uuid(),
                'lines' => [['sale_line_id' => $line->id, 'quantity' => '1.000', 'condition' => 'resalable']],
            ]);
        }

        $this->assertSame(3000, (int) DB::table('fabric_sale_lines')->where('id', $line->id)->value('returned_qty_milli'));
        $this->assertSame(2, (int) DB::table('fabric_return_lines')->where('sale_line_id', $line->id)->sum('credit_amount'));
        $this->assertSame(3, (int) DB::table('fabric_return_lines')->where('sale_line_id', $line->id)->sum('cost_restored'));
        $this->assertSame(2, (int) DB::table('fabric_customer_credits')->where('customer_id', $customer->id)->sum('amount'));
    }

}

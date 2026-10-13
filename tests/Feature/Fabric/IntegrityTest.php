<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricInventory;
use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class IntegrityTest extends TestCase
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
            'category_id' => $this->categoryId, 'name' => 'Cotton Original', 'sku' => 'INT-001', 'barcode' => 'INT-PROD-001',
            'unit' => 'meter', 'default_selling_price' => '20.00', 'low_stock' => '5.000', 'active' => true,
        ]);
        $this->vendorId = FabricInventory::saveVendor(['shop_name' => 'Integrity Textiles', 'active' => true]);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Integrity Customer', 'phone' => '03009999999', 'normalized_phone' => '+923009999999', 'active' => true]);
    }

    /** @return array{bill:int,line:int,roll:int} */
    private function stocked(string $qty = '20.000'): array
    {
        $bill = FabricInventory::createPurchase([
            'vendor_id' => $this->vendorId, 'bill_date' => now()->subHour()->toIso8601String(),
            'lines' => [['product_id' => $this->productId, 'color_id' => $this->colorId, 'quantity' => $qty, 'purchase_rate' => '10.00']],
        ]);
        FabricInventory::postPurchase($bill);
        $line = (int) DB::table('fabric_purchase_lines')->where('purchase_bill_id', $bill)->value('id');
        $receipt = FabricInventory::receivePurchase([
            'purchase_bill_id' => $bill, 'vendor_id' => $this->vendorId, 'received_at' => now()->toIso8601String(),
            'lines' => [['purchase_line_id' => $line, 'quantity' => $qty, 'roll_number' => 'INT-'.Str::random(6), 'selling_price' => '20.00']],
        ]);
        $roll = (int) DB::table('fabric_rolls')->where('goods_receipt_id', $receipt)->value('id');
        return compact('bill', 'line', 'roll');
    }

    public function test_product_unit_cannot_change_after_inventory_history_exists(): void
    {
        $this->stocked();
        $this->expectException(ValidationException::class);
        FabricInventory::saveProduct([
            'category_id' => $this->categoryId, 'name' => 'Cotton Original', 'sku' => 'INT-001', 'barcode' => 'INT-PROD-001',
            'unit' => 'piece', 'default_selling_price' => '20.00', 'low_stock' => '5', 'active' => true,
        ], $this->productId);
    }

    public function test_sale_line_snapshots_survive_master_data_rename(): void
    {
        $stock = $this->stocked();
        $sale = FabricInventory::postSale([
            'sale_at' => now()->addMinute()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $stock['roll'], 'quantity' => '2.000', 'unit_price' => '20.00']],
            'payments' => [['account_id' => $this->cashId, 'amount' => '40.00']],
        ], 'owner');
        $before = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        FabricInventory::saveProduct([
            'category_id' => $this->categoryId, 'name' => 'Cotton Renamed', 'sku' => 'INT-001', 'barcode' => 'INT-PROD-001',
            'unit' => 'meter', 'default_selling_price' => '22.00', 'low_stock' => '5.000', 'active' => true,
        ], $this->productId);
        DB::table('fabric_colors')->where('id', $this->colorId)->update(['name' => 'Renamed color', 'updated_at' => now()]);
        DB::table('fabric_rolls')->where('id', $stock['roll'])->update(['roll_number' => 'RENAMED-ROLL', 'updated_at' => now()]);
        $after = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        $this->assertSame('Cotton Original', $before->product_name_snapshot);
        $this->assertSame('Cotton Original', $after->product_name_snapshot);
        $this->assertSame('Unspecified', $after->color_name_snapshot);
        $this->assertNotSame('RENAMED-ROLL', $after->roll_number_snapshot);
    }

    public function test_reservation_reduces_available_stock_and_damaged_roll_cannot_be_reserved(): void
    {
        $stock = $this->stocked('10.000');
        DB::table('fabric_settings')->where('id', 1)->update(['reservations_enabled' => true]);
        $reservation = FabricInventory::reserve(['roll_id' => $stock['roll'], 'quantity' => '4.000']);
        $this->assertSame(4000, (int) DB::table('fabric_rolls')->where('id', $stock['roll'])->value('reserved_qty_milli'));
        try {
            FabricInventory::postSale([
                'sale_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
                'lines' => [['roll_id' => $stock['roll'], 'quantity' => '7.000', 'unit_price' => '20.00']],
                'payments' => [['account_id' => $this->cashId, 'amount' => '140.00']],
            ], 'owner');
            $this->fail('Expected reservation to protect available stock.');
        } catch (ValidationException) {
            $this->assertSame(10000, (int) DB::table('fabric_rolls')->where('id', $stock['roll'])->value('current_qty_milli'));
        }
        FabricInventory::releaseReservation($reservation);
        DB::table('fabric_rolls')->where('id', $stock['roll'])->update(['condition' => 'damaged']);
        $this->expectException(ValidationException::class);
        FabricInventory::reserve(['roll_id' => $stock['roll'], 'quantity' => '1.000']);
    }

    public function test_stale_physical_count_cannot_overwrite_later_stock_change(): void
    {
        $stock = $this->stocked('10.000');
        $count = FabricInventory::physicalCount([
            'counted_at' => now()->toIso8601String(),
            'lines' => [['roll_id' => $stock['roll'], 'quantity' => '9.000', 'reason' => 'Measured shortage']],
        ]);
        FabricInventory::adjustStock(['roll_id' => $stock['roll'], 'quantity' => '8.000', 'effective_at' => now()->addMinute()->toIso8601String(), 'reason' => 'Separate verified adjustment']);
        $this->expectException(ValidationException::class);
        FabricInventory::postPhysicalCount($count);
    }

    public function test_vendor_return_rejects_roll_from_different_purchase_bill_even_for_same_vendor(): void
    {
        $first = $this->stocked('10.000');
        $secondBill = FabricInventory::createPurchase([
            'vendor_id' => $this->vendorId, 'bill_date' => now()->toIso8601String(),
            'lines' => [['product_id' => $this->productId, 'color_id' => $this->colorId, 'quantity' => '10.000', 'purchase_rate' => '10.00']],
        ]);
        FabricInventory::postPurchase($secondBill);
        $this->expectException(ValidationException::class);
        FabricInventory::vendorReturn([
            'vendor_id' => $this->vendorId, 'purchase_bill_id' => $secondBill, 'effective_at' => now()->addMinute()->toIso8601String(),
            'reason' => 'Wrong bill attempt', 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $first['roll'], 'quantity' => '1.000']],
        ]);
    }

    public function test_backdated_direct_allocations_are_rejected_but_can_remain_advances(): void
    {
        $customer = $this->customer();
        $stock = $this->stocked('10.000');
        $futureSaleAt = now()->addDay();
        $sale = FabricInventory::postSale([
            'customer_id' => $customer->id, 'sale_at' => $futureSaleAt->toIso8601String(), 'credit_authorized' => true,
            'idempotency_key' => (string) Str::uuid(), 'lines' => [['roll_id' => $stock['roll'], 'quantity' => '2.000', 'unit_price' => '20.00']],
        ], 'owner');
        $this->expectException(ValidationException::class);
        FabricInventory::customerReceipt([
            'customer_id' => $customer->id, 'account_id' => $this->cashId, 'amount' => '20.00',
            'effective_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['sale_id' => $sale, 'amount' => '20.00']],
        ]);
    }

    public function test_account_opening_balance_and_kind_are_immutable_after_money_movement(): void
    {
        FabricInventory::expense([
            'category' => 'Transport', 'amount' => '10.00', 'account_id' => $this->cashId,
            'effective_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->post('/fabric/action/account-save/'.$this->cashId, [
            'name' => 'Cash', 'kind' => 'bank', 'opening_balance' => '99.00', 'active' => true,
        ])->assertSessionHasErrors('opening_balance');
        $this->assertDatabaseHas('fabric_accounts', ['id' => $this->cashId, 'kind' => 'cash', 'opening_balance' => 0]);
    }

    public function test_promotion_edit_does_not_change_historical_sale_snapshot(): void
    {
        $stock = $this->stocked('10.000');
        $promotion = FabricInventory::savePromotion([
            'name' => 'Launch', 'percent' => '10', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(),
            'priority' => 1, 'stackable' => false, 'active' => true, 'targets' => [['type' => 'product', 'id' => $this->productId]],
        ]);
        $sale = FabricInventory::postSale([
            'sale_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $stock['roll'], 'quantity' => '1.000', 'unit_price' => '20.00']],
            'payments' => [['account_id' => $this->cashId, 'amount' => '18.00']],
        ], 'owner');
        $line = DB::table('fabric_sale_lines')->where('sale_id', $sale)->firstOrFail();
        $snapshot = json_decode((string) $line->promotion_snapshot, true, 512, JSON_THROW_ON_ERROR);
        FabricInventory::savePromotion([
            'name' => 'Launch changed', 'percent' => '50', 'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(),
            'priority' => 1, 'stackable' => false, 'active' => true, 'targets' => [['type' => 'product', 'id' => $this->productId]],
        ], $promotion);
        $this->assertSame(10.0, (float) $snapshot[0]['percent']);
        $this->assertSame(1800, (int) $line->net);
    }

    public function test_cashier_cannot_open_sensitive_sections_or_receive_supplier_cost_data(): void
    {
        $stock = $this->stocked('10.000');
        $cashier = User::factory()->create();
        $cashier->forceFill(['is_owner' => false, 'fabric_role' => 'cashier'])->save();
        $this->actingAs($cashier);
        $this->get('/fabric')->assertOk();
        $this->get('/fabric/inventory')->assertOk();
        $this->get('/fabric/vendors')->assertForbidden();
        $this->get('/fabric/purchases')->assertForbidden();
        $this->get('/fabric/reports')->assertForbidden();
        $this->get('/fabric/settings')->assertForbidden();
        $response = $this->get('/fabric/barcode/'.DB::table('fabric_rolls')->where('id', $stock['roll'])->value('barcode'))->assertOk();
        $record = $response->json('record');
        $this->assertArrayNotHasKey('unit_cost', $record);
        $this->assertArrayNotHasKey('vendor_id', $record);
    }

    public function test_manager_cannot_open_owner_settings_but_can_use_operational_sections(): void
    {
        $manager = User::factory()->create();
        $manager->forceFill(['is_owner' => false, 'fabric_role' => 'manager'])->save();
        $this->actingAs($manager);
        $this->get('/fabric/vendors')->assertOk();
        $this->get('/fabric/reports')->assertOk();
        $this->get('/fabric/settings')->assertForbidden();
    }

    public function test_marketing_csv_import_deduplicates_normalized_phone_without_granting_or_revoking_consent(): void
    {
        $this->post('/fabric/action/marketing-contact', [
            'phone' => '+923001234567', 'name' => 'Existing', 'whatsapp_consent' => true, 'whatsapp_consent_source' => 'Written form',
        ])->assertRedirect();
        $csv = "phone,name\n0300 1234567,Imported Name\n+923009999999,New Person\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);
        $this->post('/fabric/action/marketing-import', ['file' => $file])->assertRedirect();
        $this->assertSame(2, DB::table('fabric_marketing_contacts')->count());
        $existing = DB::table('fabric_marketing_contacts')->where('phone', '+923001234567')->firstOrFail();
        $this->assertTrue((bool) $existing->whatsapp_consent);
        $new = DB::table('fabric_marketing_contacts')->where('phone', '+923009999999')->firstOrFail();
        $this->assertFalse((bool) $new->whatsapp_consent);
        $this->assertFalse((bool) $new->sms_consent);
    }

    public function test_zero_customer_receipt_is_rejected_before_ledger_posting(): void
    {
        $customer = $this->customer();
        $this->expectException(ValidationException::class);
        FabricInventory::customerReceipt([
            'customer_id' => $customer->id, 'account_id' => $this->cashId, 'amount' => '0.00',
            'effective_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_vendor_with_unsettled_payable_cannot_be_archived_but_settled_vendor_can(): void
    {
        $stock = $this->stocked('10.000');
        try {
            FabricInventory::saveVendor(['shop_name' => 'Integrity Textiles', 'active' => false], $this->vendorId);
            $this->fail('Expected unsettled vendor archive protection.');
        } catch (ValidationException) {
            $this->assertTrue((bool) DB::table('fabric_vendors')->where('id', $this->vendorId)->value('active'));
        }
        FabricInventory::vendorPayment([
            'vendor_id' => $this->vendorId, 'account_id' => $this->cashId, 'amount' => '100.00',
            'effective_at' => now()->addMinute()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['purchase_bill_id' => $stock['bill'], 'amount' => '100.00']],
        ]);
        FabricInventory::saveVendor(['shop_name' => 'Integrity Textiles', 'active' => false], $this->vendorId);
        $this->assertFalse((bool) DB::table('fabric_vendors')->where('id', $this->vendorId)->value('active'));
    }

    public function test_product_with_stock_on_hand_cannot_be_archived(): void
    {
        $stock = $this->stocked('2.000');
        $this->post('/fabric/action/product-toggle/'.$this->productId)->assertStatus(422);
        $this->assertTrue((bool) DB::table('fabric_products')->where('id', $this->productId)->value('active'));
        FabricInventory::adjustStock(['roll_id' => $stock['roll'], 'quantity' => '0', 'effective_at' => now()->addMinute()->toIso8601String(), 'reason' => 'Verified write-off before archival']);
        $this->post('/fabric/action/product-toggle/'.$this->productId)->assertRedirect();
        $this->assertFalse((bool) DB::table('fabric_products')->where('id', $this->productId)->value('active'));
    }

    public function test_dashboard_separates_outstanding_advances_returns_and_refunds(): void
    {
        $customer = $this->customer();
        FabricInventory::createOpeningBalance(['party_type' => 'customer', 'party_id' => $customer->id, 'kind' => 'receivable', 'amount' => '25.00', 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Opening debt']);
        FabricInventory::createOpeningBalance(['party_type' => 'customer', 'party_id' => $customer->id, 'kind' => 'advance', 'amount' => '10.00', 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Opening advance']);
        $data = FabricInventory::dashboard(now()->subDays(2), now()->addDay());
        $this->assertSame(2500, $data['customer_outstanding']);
        $this->assertSame(1000, $data['customer_advances']);
        $this->assertArrayHasKey('customer_refunds', $data);
        $this->assertArrayHasKey('vendor_returns', $data);
        $this->assertArrayHasKey('low_stock_products', $data);
        $this->assertArrayHasKey('low_stock_rolls', $data);
    }


    public function test_product_edit_form_cannot_bypass_archive_stock_protection(): void
    {
        $this->stocked('2.000');

        $this->expectException(ValidationException::class);
        FabricInventory::saveProduct([
            'category_id' => $this->categoryId,
            'name' => 'Cotton Original',
            'sku' => 'INT-001',
            'barcode' => 'INT-PROD-001',
            'unit' => 'meter',
            'default_selling_price' => '20.00',
            'low_stock' => '5.000',
            'active' => false,
        ], $this->productId);
    }

    public function test_non_zero_cash_or_bank_account_cannot_be_archived(): void
    {
        FabricInventory::expense([
            'category' => 'Archive guard test',
            'amount' => '10.00',
            'account_id' => $this->cashId,
            'effective_at' => now()->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->post('/fabric/action/account-save/'.$this->cashId, [
            'name' => 'Cash',
            'kind' => 'cash',
            'opening_balance' => '0.00',
            'active' => false,
        ])->assertSessionHasErrors('active');

        $this->assertTrue((bool) DB::table('fabric_accounts')->where('id', $this->cashId)->value('active'));
    }


    public function test_vendor_with_open_draft_purchase_cannot_be_archived(): void
    {
        FabricInventory::createPurchase([
            'vendor_id' => $this->vendorId,
            'bill_date' => now()->toIso8601String(),
            'lines' => [[
                'product_id' => $this->productId,
                'color_id' => $this->colorId,
                'quantity' => '1.000',
                'purchase_rate' => '10.00',
            ]],
        ]);

        $this->expectException(ValidationException::class);
        FabricInventory::saveVendor([
            'shop_name' => 'Integrity Textiles',
            'active' => false,
        ], $this->vendorId);
    }


    public function test_dashboard_period_dates_are_serialized_in_business_timezone_for_report_drilldowns(): void
    {
        DB::table('business_settings')->where('id', 1)->update(['timezone' => 'Asia/Karachi']);
        Carbon::setTestNow(Carbon::parse('2026-09-14 20:30:00', 'UTC'));
        try {
            $this->get('/fabric?period=today')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.period', 'today')
                ->where('period.from_date', '2026-09-15')
                ->where('period.to_date', '2026-09-15'));
        } finally {
            Carbon::setTestNow();
        }
    }

}

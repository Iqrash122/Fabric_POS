<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricInventory;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private int $roll;
    private int $cash;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $owner = User::factory()->create(['name' => 'Receipt Cashier', 'is_owner' => true, 'fabric_role' => 'owner']);
        $this->actingAs($owner);
        $this->cash = (int) DB::table('fabric_accounts')->where('kind', 'cash')->value('id');
        $product = FabricInventory::saveProduct([
            'category_id' => DB::table('fabric_categories')->value('id'), 'name' => 'Premium Cotton',
            'sku' => 'RECEIPT', 'barcode' => 'RECEIPT-TEST', 'unit' => 'meter',
            'default_selling_price' => '200.00', 'low_stock' => '1', 'active' => true,
        ]);
        $this->roll = FabricInventory::openingStock([
            'product_id' => $product, 'color_id' => DB::table('fabric_colors')->value('id'),
            'quantity' => '50', 'unit_cost' => '100', 'selling_price' => '200',
            'roll_number' => 'RECEIPT-ROLL', 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Test fixture',
        ]);
        $this->payload = [
            'sale_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $this->roll, 'quantity' => '2.5', 'unit_price' => '200', 'manual_discount' => '50']],
            'manual_discount_reason' => 'Counter discount', 'payments' => [['account_id' => $this->cash, 'amount' => '1000']],
        ];
    }

    public function test_saved_receipt_preserves_tender_change_cashier_and_product_snapshot(): void
    {
        $this->from('/fabric/pos')->post('/fabric/action/sale', $this->payload)->assertSessionHasNoErrors()->assertRedirect('/fabric/pos');
        $sale = DB::table('fabric_sales')->first();
        $this->assertSame(100000, (int) $sale->tendered);
        $this->assertSame(45000, (int) $sale->paid);
        $this->assertSame(45000, (int) DB::table('fabric_account_entries')->where('kind', 'sale_receipt')->sum('amount'));
        DB::table('fabric_products')->update(['name' => 'Renamed product']);
        $this->get('/fabric/document/thermal/'.$sale->id)->assertOk()->assertHeader('content-type', 'text/html; charset=utf-8')
            ->assertSee(\Illuminate\Support\Carbon::parse($this->payload['sale_at'])->timezone(DB::table('business_settings')->value('timezone'))->format('d M Y h:i A'))
            ->assertSee('SALE RECEIPT')->assertSee('Premium Cotton')->assertSee('Receipt Cashier')->assertSee('2.5 m')
            ->assertSee('PKR 1,000.00')->assertSee('PKR 550.00')->assertSee('PKR 450.00')->assertSee('80mm')->assertDontSee('Renamed product');
        $this->get('/fabric/document/invoice/'.$sale->id)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->post('/fabric/action/sale', $this->payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('fabric_sales')->count());
    }

    public function test_bank_overpayment_is_rejected_without_creating_sale_or_changing_stock(): void
    {
        $this->payload['payments'][0]['account_id'] = DB::table('fabric_accounts')->where('kind', 'bank')->value('id');
        $this->post('/fabric/action/sale', $this->payload)->assertSessionHasErrors('payments');
        $this->assertSame(0, DB::table('fabric_sales')->count());
        $this->assertSame(50000, (int) DB::table('fabric_rolls')->where('id', $this->roll)->value('current_qty_milli'));
    }

    public function test_credit_receipt_distinguishes_original_and_current_balance(): void
    {
        $customer = Customer::create(['name' => 'Credit Customer', 'phone' => '03001234567', 'normalized_phone' => '+923001234567', 'active' => true]);
        $this->payload['customer_id'] = $customer->id;
        $this->payload['credit_authorized'] = true;
        $this->payload['payments'][0]['amount'] = '100';
        $sale = FabricInventory::postSale($this->payload, 'owner');
        FabricInventory::customerReceipt([
            'customer_id' => $customer->id, 'account_id' => $this->cash, 'amount' => '50',
            'effective_at' => now()->addMinute()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'allocations' => [['sale_id' => $sale, 'amount' => '50']],
        ]);
        $this->get('/fabric/document/thermal/'.$sale)->assertOk()->assertSee('Balance at sale')->assertSee('PKR 350.00')
            ->assertSee('Current balance')->assertSee('PKR 300.00');
    }

    public function test_legacy_receipt_omits_unknown_tender_and_escapes_shop_text(): void
    {
        $sale = FabricInventory::postSale($this->payload, 'owner');
        DB::table('fabric_sales')->where('id', $sale)->update(['tendered' => null, 'cashier_name' => null]);
        DB::table('business_settings')->update(['name' => '<script>alert(1)</script>']);
        DB::table('fabric_settings')->update(['thermal_width_mm' => 58]);
        $this->get('/fabric/document/thermal/'.$sale)->assertOk()->assertSee('58mm')->assertDontSee('Tendered')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}

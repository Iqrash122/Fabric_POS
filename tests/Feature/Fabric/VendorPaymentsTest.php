<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricInventory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class VendorPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private int $vendorId;
    private int $productId;
    private int $cashId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::factory()->create(['is_owner' => true, 'fabric_role' => 'owner']));
        $this->vendorId = FabricInventory::saveVendor(['shop_name' => 'Payment test vendor', 'active' => true]);
        $this->cashId = (int) DB::table('fabric_accounts')->where('name', 'Cash')->value('id');
        $this->productId = FabricInventory::saveProduct([
            'category_id' => DB::table('fabric_categories')->value('id'), 'name' => 'Payment test cotton',
            'sku' => 'PAY-TEST', 'unit' => 'meter', 'default_selling_price' => '20.00', 'low_stock' => '0', 'active' => true,
        ]);
    }

    private function bill(string $date = '-1 hour', ?int $vendorId = null, bool $post = true): int
    {
        $id = FabricInventory::createPurchase([
            'vendor_id' => $vendorId ?? $this->vendorId, 'bill_date' => now()->modify($date)->toIso8601String(),
            'lines' => [['product_id' => $this->productId, 'color_id' => DB::table('fabric_colors')->value('id'),
                'quantity' => '10', 'purchase_rate' => '10', 'discount' => '0', 'additional_cost' => '0']],
        ]);
        if ($post) FabricInventory::postPurchase($id);
        return $id;
    }

    private function payment(array $overrides = []): array
    {
        return array_replace([
            'vendor_id' => $this->vendorId, 'account_id' => $this->cashId, 'amount' => '50.00',
            'payment_mode' => 'outstanding', 'effective_at' => now()->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(), 'notes' => 'Paid by cash',
        ], $overrides);
    }

    public function test_outstanding_payment_settles_oldest_bills_and_duplicate_retry_posts_only_once(): void
    {
        $newer = $this->bill('-1 hour');
        $older = $this->bill('-2 hours');
        $draft = $this->bill('-3 hours', post: false);
        $payload = $this->payment(['amount' => '150.00']);
        $this->postJson('/fabric/action/vendor-payment', $payload)->assertRedirect();
        $this->postJson('/fabric/action/vendor-payment', $payload)->assertRedirect();
        $this->assertSame(0, FabricInventory::vendorOutstanding($older));
        $this->assertSame(5000, FabricInventory::vendorOutstanding($newer));
        $this->assertDatabaseMissing('fabric_vendor_payment_allocations', ['purchase_bill_id' => $draft]);
        $this->assertDatabaseCount('fabric_vendor_payments', 1);
        $this->assertDatabaseCount('fabric_account_entries', 1);
        $this->assertDatabaseHas('fabric_account_entries', ['account_id' => $this->cashId, 'amount' => 15000, 'direction' => 'out']);
        $this->assertSame(['payable' => 5000, 'advance' => 0, 'credit' => 0], FabricInventory::vendorSummary($this->vendorId));
        $this->get('/fabric/vendors')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('vendor_payments', 1)->where('vendor_payments.0.vendor_name', 'Payment test vendor')
            ->where('vendor_payments.0.account_name', 'Cash')->where('vendor_payments.0.allocated', 15000)
            ->where('vendor_payments.0.available', 0)->where('vendor_payments.0.notes', 'Paid by cash'));
    }

    public function test_outstanding_payment_covers_opening_payable_then_bill_and_keeps_excess_as_advance(): void
    {
        $bill = $this->bill();
        $opening = FabricInventory::createOpeningBalance([
            'party_type' => 'vendor', 'party_id' => $this->vendorId, 'kind' => 'payable',
            'amount' => '25', 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Old payable',
        ]);
        $first = FabricInventory::vendorPayment($this->payment(['amount' => '40']));
        $this->assertSame(0, FabricInventory::openingRemaining($opening));
        $this->assertSame(8500, FabricInventory::vendorOutstanding($bill));
        $this->assertSame(0, FabricInventory::vendorPaymentAvailable($first));
        $second = FabricInventory::vendorPayment($this->payment(['amount' => '100']));
        $this->assertSame(1500, FabricInventory::vendorPaymentAvailable($second));
        $this->assertSame(['payable' => 0, 'advance' => 1500, 'credit' => 0], FabricInventory::vendorSummary($this->vendorId));
    }

    public function test_specific_bill_payment_leaves_other_bills_unchanged_and_keeps_excess(): void
    {
        $older = $this->bill('-2 hours');
        $selected = $this->bill();
        $this->postJson('/fabric/action/vendor-payment', $this->payment([
            'payment_mode' => 'bill', 'purchase_bill_id' => $selected, 'amount' => '120',
        ]))->assertRedirect();
        $this->assertSame(10000, FabricInventory::vendorOutstanding($older));
        $this->assertSame(0, FabricInventory::vendorOutstanding($selected));
        $this->assertSame(['payable' => 10000, 'advance' => 2000, 'credit' => 0], FabricInventory::vendorSummary($this->vendorId));
    }

    public function test_advance_is_explicit_and_can_be_applied_to_a_bill_later(): void
    {
        $this->postJson('/fabric/action/vendor-payment', $this->payment())->assertUnprocessable()->assertJsonValidationErrors('payment_mode');
        $this->assertDatabaseCount('fabric_vendor_payments', 0);
        $payment = FabricInventory::vendorPayment($this->payment(['payment_mode' => 'advance']));
        $bill = $this->bill();
        $this->assertSame(['payable' => 10000, 'advance' => 5000, 'credit' => 0], FabricInventory::vendorSummary($this->vendorId));
        $this->postJson('/fabric/action/vendor-advance-apply', [
            'payment_id' => $payment, 'purchase_bill_id' => $bill, 'amount' => '50',
        ])->assertRedirect();
        $this->assertSame(['payable' => 5000, 'advance' => 0, 'credit' => 0], FabricInventory::vendorSummary($this->vendorId));
        $this->assertDatabaseCount('fabric_account_entries', 1);
    }

    public function test_payment_date_excludes_future_debt_and_specific_future_bill_is_rejected(): void
    {
        $past = $this->bill('-2 days');
        $later = $this->bill('-1 hour');
        $date = now()->subDay()->toIso8601String();
        $this->postJson('/fabric/action/vendor-payment', $this->payment(['amount' => '150', 'effective_at' => $date]))->assertRedirect();
        $this->assertSame(0, FabricInventory::vendorOutstanding($past));
        $this->assertSame(10000, FabricInventory::vendorOutstanding($later));
        $this->postJson('/fabric/action/vendor-payment', $this->payment([
            'payment_mode' => 'bill', 'purchase_bill_id' => $later, 'effective_at' => $date,
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('fabric_vendor_payments', 1);
        $this->assertDatabaseCount('fabric_account_entries', 1);
    }

    public function test_bill_must_be_unpaid_posted_and_belong_to_the_selected_vendor(): void
    {
        $other = FabricInventory::saveVendor(['shop_name' => 'Other vendor', 'active' => true]);
        $wrongVendorBill = $this->bill(vendorId: $other);
        $draft = $this->bill(post: false);
        $paid = $this->bill();
        FabricInventory::vendorPayment($this->payment(['amount' => '100']));
        foreach ([$wrongVendorBill, $draft, $paid, 999999] as $bill) {
            $this->postJson('/fabric/action/vendor-payment', $this->payment([
                'payment_mode' => 'bill', 'purchase_bill_id' => $bill,
            ]))->assertUnprocessable()->assertJsonValidationErrors('purchase_bill_id');
        }
        $this->assertDatabaseCount('fabric_vendor_payments', 1);
    }

    public static function invalidPayments(): array
    {
        return [
            'zero' => [['amount' => '0']], 'negative' => [['amount' => '-2']], 'text' => [['amount' => 'abc']],
            'invalid date' => [['effective_at' => 'bad-date']], 'invalid mode' => [['payment_mode' => 'invalid']],
            'missing bill' => [['payment_mode' => 'bill']],
            'conflicting allocations' => [['allocations' => [['purchase_bill_id' => 1, 'amount' => '1']]]],
            'long note' => [['notes' => str_repeat('x', 2001)]],
        ];
    }

    #[DataProvider('invalidPayments')]
    public function test_invalid_fields_never_post_a_payment(array $changes): void
    {
        $this->bill();
        $this->postJson('/fabric/action/vendor-payment', $this->payment($changes))->assertUnprocessable();
        $this->assertDatabaseCount('fabric_vendor_payments', 0);
        $this->assertDatabaseCount('fabric_account_entries', 0);
    }

    public function test_archived_vendor_or_account_cannot_receive_new_payments(): void
    {
        $this->bill();
        DB::table('fabric_vendors')->where('id', $this->vendorId)->update(['active' => false]);
        $this->postJson('/fabric/action/vendor-payment', $this->payment())->assertNotFound();
        DB::table('fabric_vendors')->where('id', $this->vendorId)->update(['active' => true]);
        DB::table('fabric_accounts')->where('id', $this->cashId)->update(['active' => false]);
        $this->postJson('/fabric/action/vendor-payment', $this->payment())->assertNotFound();
        $this->assertDatabaseCount('fabric_vendor_payments', 0);
        $this->assertDatabaseCount('fabric_vendor_payment_allocations', 0);
        $this->assertDatabaseCount('fabric_account_entries', 0);
    }

    public function test_cashier_cannot_post_vendor_payment(): void
    {
        $this->bill();
        $this->actingAs(User::factory()->create(['is_owner' => false, 'fabric_role' => 'cashier']));
        $this->postJson('/fabric/action/vendor-payment', $this->payment())->assertForbidden();
        $this->assertDatabaseCount('fabric_vendor_payments', 0);
    }
}

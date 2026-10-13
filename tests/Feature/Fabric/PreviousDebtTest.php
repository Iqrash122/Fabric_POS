<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricInventory as Inventory;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PreviousDebtTest extends TestCase
{
    use RefreshDatabase;

    private int $vendor;
    private int $customer;
    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::factory()->create(['is_owner' => true, 'fabric_role' => 'owner']));
        $this->vendor = Inventory::saveVendor(['shop_name' => 'Previous debt vendor', 'active' => true]);
        $this->customer = Customer::create(['name' => 'Previous debt customer', 'phone' => '03001234567', 'normalized_phone' => '+923001234567', 'active' => true])->id;
        $this->cash = (int) DB::table('fabric_accounts')->where('name', 'Cash')->value('id');
    }

    public static function parties(): array
    {
        return [['vendor'], ['customer']];
    }

    private function opening(string $party, string $amount = '50', bool $advance = false): array
    {
        return ['party_type' => $party, 'party_id' => $party === 'vendor' ? $this->vendor : $this->customer,
            'kind' => $advance ? 'advance' : ($party === 'vendor' ? 'payable' : 'receivable'),
            'amount' => $amount, 'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Previous ledger balance', 'idempotency_key' => (string) Str::uuid()];
    }

    private function money(string $party, string $amount = '20', string $mode = 'advance'): array
    {
        return [$party.'_id' => $party === 'vendor' ? $this->vendor : $this->customer,
            $party === 'vendor' ? 'payment_mode' : 'collection_mode' => $mode,
            'account_id' => $this->cash, 'amount' => $amount, 'effective_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid()];
    }

    private function balance(string $party): array
    {
        return $party === 'vendor' ? Inventory::vendorSummary($this->vendor) : Inventory::customerSummary($this->customer);
    }

    #[DataProvider('parties')]
    public function test_previous_udhaar_is_idempotent_and_does_not_invent_stock_or_cash(string $party): void
    {
        $payload = $this->opening($party);
        $this->postJson('/fabric/action/opening-balance', $payload)->assertRedirect();
        $this->postJson('/fabric/action/opening-balance', $payload)->assertRedirect();
        $this->assertDatabaseCount('fabric_opening_balances', 1);
        $this->assertDatabaseCount('fabric_account_entries', 0);
        $this->assertDatabaseCount('fabric_rolls', 0);
        $this->assertSame(5000, $this->balance($party)[$party === 'vendor' ? 'payable' : 'outstanding']);
        $this->get('/fabric/'.$party.'s')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('opening_balances', 1)->where('opening_balances.0.remaining', 5000)->where('opening_balances.0.reason', 'Previous ledger balance'));
        $this->postJson('/fabric/action/opening-balance', array_replace($payload, ['amount' => '90']))->assertUnprocessable();
        $this->assertDatabaseCount('fabric_opening_balances', 1);
    }

    #[DataProvider('parties')]
    public function test_partial_payment_then_overpayment_settles_old_debt_and_keeps_excess(string $party): void
    {
        $debt = Inventory::createOpeningBalance($this->opening($party));
        $url = '/fabric/action/'.($party === 'vendor' ? 'vendor-payment' : 'customer-receipt');
        $this->postJson($url, $this->money($party, '20', 'outstanding'))->assertRedirect();
        $this->assertSame(3000, Inventory::openingRemaining($debt));
        $this->postJson($url, $this->money($party, '40', 'outstanding'))->assertRedirect();
        $this->assertSame(0, Inventory::openingRemaining($debt));
        $this->assertSame(1000, $this->balance($party)['advance']);
        $this->assertDatabaseCount('fabric_account_entries', 2);
        $this->assertSame(6000, (int) DB::table('fabric_account_entries')->where('direction', $party === 'vendor' ? 'out' : 'in')->sum('amount'));
        $this->postJson($url, $this->money($party, '10', 'outstanding'))->assertUnprocessable();
        $this->assertDatabaseCount('fabric_account_entries', 2);
    }

    public static function advanceSources(): array
    {
        return [['vendor', false], ['customer', false], ['vendor', true], ['customer', true]];
    }

    #[DataProvider('advanceSources')]
    public function test_existing_advance_adjustment_reconciles_without_a_second_cash_movement(string $party, bool $openingAdvance): void
    {
        $debt = Inventory::createOpeningBalance($this->opening($party));
        $source = $openingAdvance ? Inventory::createOpeningBalance($this->opening($party, '20', true))
            : ($party === 'vendor' ? Inventory::vendorPayment($this->money($party)) : Inventory::customerReceipt($this->money($party)));
        $payload = ['opening_balance_id' => $debt, 'source_type' => $openingAdvance ? 'opening_advance' : ($party === 'vendor' ? 'vendor_payment' : 'customer_receipt'),
            'source_id' => $source, 'amount' => '15', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/fabric/action/advance-to-previous-debt', $payload)->assertRedirect();
        $this->postJson('/fabric/action/advance-to-previous-debt', $payload)->assertRedirect();
        $this->assertSame(3500, Inventory::openingRemaining($debt));
        $this->assertSame(500, $this->balance($party)['advance']);
        $this->assertDatabaseCount('fabric_opening_balance_applications', $openingAdvance ? 2 : 1);
        $this->assertDatabaseCount('fabric_account_entries', $openingAdvance ? 0 : 1);
        $this->postJson('/fabric/action/advance-to-previous-debt', array_replace($payload, ['amount' => '6', 'idempotency_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->assertSame(3500, Inventory::openingRemaining($debt));
        $this->postJson('/fabric/action/advance-to-previous-debt', array_replace($payload, ['amount' => '5', 'idempotency_key' => (string) Str::uuid()]))->assertRedirect();
        $this->assertSame(3000, Inventory::openingRemaining($debt));
        $this->assertSame(0, $this->balance($party)['advance']);
        $dashboard = Inventory::dashboard(now()->subDays(2), now()->addMinute());
        $this->assertSame(3000, $dashboard[$party === 'vendor' ? 'vendor_outstanding' : 'customer_outstanding']);
        $this->assertSame(0, $dashboard[$party === 'vendor' ? 'vendor_advances' : 'customer_advances']);
        $this->get('/fabric/reports?report='.$party.'s')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('report_rows', 1)->where('report_rows.0.outstanding', 3000));
    }

    public function test_advance_cannot_cross_parties_or_exceed_debt_and_future_entries_cannot_be_adjusted(): void
    {
        $debt = Inventory::createOpeningBalance($this->opening('vendor', '5'));
        $source = Inventory::vendorPayment($this->money('vendor'));
        $payload = ['opening_balance_id' => $debt, 'source_type' => 'vendor_payment', 'source_id' => $source, 'amount' => '5', 'idempotency_key' => (string) Str::uuid()];
        foreach (['0', '-1', '6', 'not money'] as $amount) {
            $this->postJson('/fabric/action/advance-to-previous-debt', array_replace($payload, ['amount' => $amount]))->assertUnprocessable();
        }
        $customerDebt = Inventory::createOpeningBalance($this->opening('customer'));
        $this->postJson('/fabric/action/advance-to-previous-debt', array_replace($payload, ['opening_balance_id' => $customerDebt]))->assertUnprocessable();
        $other = Inventory::saveVendor(['shop_name' => 'Unrelated vendor', 'active' => true]);
        $otherDebt = Inventory::createOpeningBalance(array_replace($this->opening('vendor'), ['party_id' => $other]));
        $this->postJson('/fabric/action/advance-to-previous-debt', array_replace($payload, ['opening_balance_id' => $otherDebt]))->assertUnprocessable();
        DB::table('fabric_vendor_payments')->where('id', $source)->update(['effective_at' => now()->addDay()]);
        $this->postJson('/fabric/action/advance-to-previous-debt', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('fabric_opening_balance_applications', 0);
    }

    public function test_previous_balance_creation_is_owner_only_and_advance_adjustment_requires_manager_access(): void
    {
        $debt = Inventory::createOpeningBalance($this->opening('customer'));
        $source = Inventory::customerReceipt($this->money('customer'));
        $payload = ['opening_balance_id' => $debt, 'source_type' => 'customer_receipt', 'source_id' => $source, 'amount' => '5', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs(User::factory()->create(['is_owner' => false, 'fabric_role' => 'cashier']));
        $this->postJson('/fabric/action/opening-balance', $this->opening('customer'))->assertForbidden();
        $this->postJson('/fabric/action/advance-to-previous-debt', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['is_owner' => false, 'fabric_role' => 'manager']));
        $this->postJson('/fabric/action/opening-balance', $this->opening('customer'))->assertForbidden();
        $this->postJson('/fabric/action/advance-to-previous-debt', $payload)->assertRedirect();
    }

    public function test_customer_collection_settles_old_debt_then_credit_sale_and_is_idempotent(): void
    {
        $product = Inventory::saveProduct(['category_id' => DB::table('fabric_categories')->value('id'), 'name' => 'Credit cloth', 'sku' => 'CREDIT', 'unit' => 'meter', 'default_selling_price' => '10', 'low_stock' => '0', 'active' => true]);
        $roll = Inventory::openingStock(['product_id' => $product, 'color_id' => DB::table('fabric_colors')->value('id'), 'quantity' => '10', 'unit_cost' => '5', 'selling_price' => '10', 'effective_at' => now()->subHour()->toIso8601String(), 'reason' => 'Opening stock']);
        $sale = Inventory::postSale(['customer_id' => $this->customer, 'sale_at' => now()->subMinute()->toIso8601String(), 'credit_authorized' => true, 'idempotency_key' => (string) Str::uuid(), 'lines' => [['roll_id' => $roll, 'quantity' => '10', 'unit_price' => '10']], 'payments' => []], 'owner');
        $debt = Inventory::createOpeningBalance($this->opening('customer', '25'));
        $payload = $this->money('customer', '50', 'outstanding');
        $this->postJson('/fabric/action/customer-receipt', $payload)->assertRedirect();
        $this->postJson('/fabric/action/customer-receipt', $payload)->assertRedirect();
        $this->assertSame(0, Inventory::openingRemaining($debt));
        $this->assertSame(7500, Inventory::saleOutstanding($sale));
        $this->assertDatabaseCount('fabric_customer_receipts', 1);
        $this->postJson('/fabric/action/customer-receipt', array_replace($this->money('customer', '80', 'invoice'), ['sale_id' => $sale]))->assertRedirect();
        $this->assertSame(['outstanding' => 0, 'advance' => 500, 'credit' => 0], $this->balance('customer'));
    }

    #[DataProvider('parties')]
    public function test_payment_cannot_predate_previous_debt_in_shop_timezone(string $party): void
    {
        DB::table('business_settings')->where('id', 1)->update(['timezone' => 'Asia/Karachi']);
        $debt = Inventory::createOpeningBalance(array_replace($this->opening($party), ['effective_at' => now()->toIso8601String()]));
        $data = $this->money($party);
        unset($data[$party === 'vendor' ? 'payment_mode' : 'collection_mode']);
        $data['effective_at'] = now()->subHour()->toIso8601String();
        $data['opening_allocations'] = [['opening_balance_id' => $debt, 'amount' => '20']];
        $this->postJson('/fabric/action/'.($party === 'vendor' ? 'vendor-payment' : 'customer-receipt'), $data)->assertUnprocessable();
        $this->assertDatabaseCount('fabric_account_entries', 0);
        $this->assertSame(5000, Inventory::openingRemaining($debt));
    }

    public function test_customer_collection_rejects_missing_invoice_invalid_mode_and_conflicting_allocations(): void
    {
        foreach ([['collection_mode' => 'invoice'], ['collection_mode' => 'invalid'], ['collection_mode' => 'outstanding', 'allocations' => [['sale_id' => 1, 'amount' => '1']]]] as $override) {
            $this->postJson('/fabric/action/customer-receipt', array_replace($this->money('customer'), $override))->assertUnprocessable();
        }
        $this->assertDatabaseCount('fabric_customer_receipts', 0);
    }
}

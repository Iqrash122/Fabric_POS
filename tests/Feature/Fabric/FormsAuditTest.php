<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricInventory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\ThrottleRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FormsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::factory()->create(['is_owner' => true, 'fabric_role' => 'owner']));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public static function requiredFields(): iterable
    {
        $forms = [
            'customer-save' => 'name phone',
            'vendor-save' => 'shop_name',
            'category-save' => 'name',
            'color-save' => 'name',
            'product-save' => 'category_id name sku unit default_selling_price low_stock',
            'account-save' => 'name kind opening_balance',
            'opening-balance' => 'party_type party_id kind amount effective_at reason',
            'opening-advance-apply' => 'opening_balance_id target_type target_id amount effective_at',
            'advance-to-previous-debt' => 'opening_balance_id source_type source_id amount idempotency_key',
            'purchase-create' => 'vendor_id bill_date lines',
            'purchase-update/1' => 'vendor_id bill_date lines',
            'receipt-create' => 'purchase_bill_id vendor_id received_at lines',
            'vendor-payment' => 'vendor_id account_id amount effective_at idempotency_key',
            'vendor-advance-apply' => 'payment_id purchase_bill_id amount',
            'vendor-credit-apply' => 'credit_id purchase_bill_id amount',
            'vendor-credit-refund' => 'credit_id account_id amount effective_at idempotency_key',
            'installment-create' => 'vendor_id cadence amount first_due_at count',
            'installment-settle/1' => 'payment_id',
            'sale' => 'sale_at idempotency_key lines',
            'customer-receipt' => 'customer_id account_id amount effective_at idempotency_key',
            'customer-advance-apply' => 'receipt_id sale_id amount',
            'customer-credit-apply' => 'credit_id sale_id amount',
            'customer-credit-refund' => 'credit_id account_id amount effective_at idempotency_key',
            'customer-return' => 'sale_id effective_at resolution reason idempotency_key lines',
            'vendor-return' => 'vendor_id purchase_bill_id effective_at reason idempotency_key lines',
            'expense' => 'category amount account_id effective_at idempotency_key',
            'transfer' => 'from_account_id to_account_id amount effective_at idempotency_key',
            'reserve' => 'roll_id quantity',
            'opening-stock' => 'product_id color_id quantity unit_cost selling_price effective_at reason',
            'stock-adjust' => 'roll_id quantity effective_at reason',
            'stock-count' => 'counted_at lines',
            'promotion-save' => 'name percent starts_at ends_at priority',
            'settings' => 'business_name currency timezone country barcode_prefix default_low_stock default_low_roll walk_in_name thermal_width_mm barcode_width_mm barcode_height_mm invoice_title',
            'staff-save' => 'name email role password',
            'marketing-import' => 'file',
            'marketing-contact' => 'phone',
            'campaign-save' => 'name channel message',
        ];
        foreach ($forms as $action => $fields) {
            yield $action => [$action, explode(' ', $fields)];
        }
    }

    #[DataProvider('requiredFields')]
    public function test_each_form_rejects_missing_required_fields(string $action, array $fields): void
    {
        $this->postJson('/fabric/action/'.$action, [])->assertUnprocessable()->assertJsonValidationErrors($fields);
        $this->assertDatabaseCount('fabric_sales', 0);
        $this->assertDatabaseCount('fabric_rolls', 0);
    }

    public static function invalidFields(): iterable
    {
        $cases = [
            ['customer-save', 'name', str_repeat('a', 181)], ['customer-save', 'address', str_repeat('a', 2001)],
            ['customer-save', 'phone', []], ['vendor-save', 'email', 'bad-email'],
            ['vendor-save', 'owner_name', str_repeat('a', 161)], ['vendor-save', 'address', str_repeat('a', 2001)],
            ['vendor-save', 'notes', str_repeat('a', 5001)], ['vendor-save', 'active', 'maybe'],
            ['category-save', 'active', 'maybe'], ['color-save', 'hex', '#XYZ123'],
            ['product-save', 'unit', 'kg'], ['product-save', 'category_id', 'abc'],
            ['product-save', 'sku', str_repeat('a', 81)], ['product-save', 'barcode', str_repeat('a', 121)],
            ['account-save', 'kind', 'credit'], ['account-save', 'active', 'yes'],
            ['opening-balance', 'party_type', 'other'], ['opening-balance', 'kind', 'other'],
            ['opening-advance-apply', 'target_type', 'expense'], ['purchase-create', 'lines', []],
            ['purchase-create', 'lines.0.product_id', 'abc'], ['purchase-create', 'lines.0.color_id', 'abc'],
            ['purchase-create', 'supplier_reference', str_repeat('a', 121)], ['purchase-create', 'notes', str_repeat('a', 3001)],
            ['receipt-create', 'lines.0.roll_number', str_repeat('a', 121)], ['receipt-create', 'lines.0.barcode', str_repeat('a', 121)],
            ['vendor-payment', 'allocations.0.purchase_bill_id', 'abc'], ['vendor-payment', 'notes', str_repeat('a', 2001)],
            ['installment-create', 'cadence', 'yearly'], ['installment-create', 'count', 0], ['installment-create', 'count', 121],
            ['sale', 'credit_authorized', 'yes'], ['sale', 'lines', []], ['sale', 'lines.0.roll_id', 'bad'],
            ['sale', 'payments.0.account_id', 'bad'], ['sale', 'payments.0.external_reference', str_repeat('a', 121)],
            ['customer-receipt', 'allocations.0.sale_id', 'bad'], ['customer-receipt', 'opening_allocations.0.opening_balance_id', 'bad'],
            ['customer-return', 'resolution', 'other'], ['customer-return', 'lines.0.condition', 'other'],
            ['vendor-return', 'lines.0.roll_id', 'bad'], ['expense', 'category', str_repeat('a', 121)],
            ['expense', 'note', str_repeat('a', 3001)], ['transfer', 'note', str_repeat('a', 2001)],
            ['reserve', 'customer_id', 'bad'], ['reserve', 'note', str_repeat('a', 2001)],
            ['opening-stock', 'roll_number', str_repeat('a', 121)], ['opening-stock', 'barcode', str_repeat('a', 191)],
            ['stock-count', 'lines.0.roll_id', 'bad'], ['stock-count', 'lines.0.reason', str_repeat('a', 2001)],
            ['promotion-save', 'priority', 'high'], ['promotion-save', 'targets.0.type', 'vendor'],
            ['promotion-save', 'targets.0.id', 'bad'], ['promotion-save', 'stackable', 'yes'],
            ['settings', 'currency', 'PKRR'], ['settings', 'country', 'PAK'], ['settings', 'timezone', 'Mars/Nowhere'],
            ['settings', 'thermal_width_mm', 57], ['settings', 'barcode_width_mm', 151], ['settings', 'barcode_height_mm', 19],
            ['settings', 'barcode_prefix', str_repeat('a', 13)], ['settings', 'receipt_footer', str_repeat('a', 1001)],
            ['staff-save', 'email', 'bad'], ['staff-save', 'password', 'short'], ['staff-save', 'role', 'owner'],
            ['marketing-contact', 'sms_consent', 'yes'], ['marketing-contact', 'whatsapp_consent', 'yes'],
            ['marketing-contact', 'whatsapp_consent_source', str_repeat('a', 201)],
            ['marketing-contact', 'sms_consent_source', str_repeat('a', 201)], ['marketing-contact', 'opted_out', 'yes'],
            ['campaign-save', 'channel', 'email'], ['campaign-save', 'message', str_repeat('a', 5001)],
            ['campaign-save', 'provider', str_repeat('a', 121)],
        ];
        foreach ($cases as $i => $case) yield $i.' '.$case[0].' '.$case[1] => $case;
    }

    #[DataProvider('invalidFields')]
    public function test_field_formats_lengths_and_choices_are_validated(string $action, string $field, mixed $value): void
    {
        $payload = [];
        data_set($payload, $field, $value);
        $this->postJson('/fabric/action/'.$action, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    private function productPayload(): array
    {
        return ['category_id' => DB::table('fabric_categories')->value('id'), 'name' => 'Audit Cotton', 'sku' => 'AUDIT-1', 'barcode' => 'AUDIT-BC', 'unit' => 'meter', 'default_selling_price' => '50.25', 'low_stock' => '2.500', 'active' => true];
    }

    public function test_product_fields_round_trip_edit_duplicate_and_archive(): void
    {
        $data = $this->productPayload();
        $this->post('/fabric/action/product-save', $data)->assertSessionHasNoErrors()->assertRedirect();
        $id = DB::table('fabric_products')->value('id');
        $this->assertDatabaseHas('fabric_products', ['id' => $id, 'name' => 'Audit Cotton', 'sku' => 'AUDIT-1', 'barcode' => 'AUDIT-BC', 'default_selling_price' => 5025, 'low_stock_milli' => 2500, 'active' => true]);
        $this->postJson('/fabric/action/product-save', $data)->assertUnprocessable()->assertJsonValidationErrors(['sku', 'barcode']);
        $edited = [...$data, 'name' => 'Edited Cotton', 'sku' => 'AUDIT-2', 'barcode' => null, 'unit' => 'piece', 'default_selling_price' => '60.00', 'low_stock' => '3'];
        $this->post('/fabric/action/product-save/'.$id, $edited)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fabric_products', ['id' => $id, 'name' => 'Edited Cotton', 'sku' => 'AUDIT-2', 'barcode' => null, 'unit' => 'piece', 'default_selling_price' => 6000, 'low_stock_milli' => 3000]);
        foreach ([false, true] as $active) {
            $this->post('/fabric/action/product-toggle/'.$id)->assertSessionHasNoErrors();
            $this->assertDatabaseHas('fabric_products', ['id' => $id, 'active' => $active]);
        }
    }

    public function test_vendor_category_color_account_and_staff_fields_round_trip(): void
    {
        $forms = [
            ['vendor-save', 'fabric_vendors', ['shop_name' => 'Audit Vendor', 'owner_name' => 'Owner', 'phone' => '03001234567', 'email' => 'vendor@example.invalid', 'address' => 'Shop 7', 'notes' => 'Optional notes', 'active' => true], ['shop_name' => 'Edited Vendor', 'owner_name' => 'New owner', 'phone' => '', 'email' => null, 'address' => 'Shop 8', 'notes' => 'Edited', 'active' => false]],
            ['category-save', 'fabric_categories', ['name' => 'Audit Category', 'active' => true], ['name' => 'Edited Category', 'active' => false]],
            ['color-save', 'fabric_colors', ['name' => 'Audit Blue', 'hex' => '#123ABC', 'active' => true], ['name' => 'Edited Blue', 'hex' => '#456DEF', 'active' => false]],
            ['account-save', 'fabric_accounts', ['name' => 'Audit Account', 'kind' => 'cash', 'opening_balance' => '0.00', 'active' => true], ['name' => 'Edited Account', 'kind' => 'bank', 'opening_balance' => '0.00', 'active' => false]],
        ];
        foreach ($forms as [$action, $table, $data, $edited]) {
            $this->post('/fabric/action/'.$action, $data)->assertSessionHasNoErrors();
            $id = DB::table($table)->max('id');
            foreach ($data as $key => $value) {
                if ($key === 'opening_balance') $value = 0;
                $this->assertDatabaseHas($table, ['id' => $id, $key => $value]);
            }
            $this->post('/fabric/action/'.$action.'/'.$id, $edited)->assertSessionHasNoErrors();
            foreach ($edited as $key => $value) {
                if ($key === 'opening_balance') $value = 0;
                if ($value === '') $value = null;
                $this->assertDatabaseHas($table, ['id' => $id, $key => $value]);
            }
        }
        $staff = ['name' => 'Audit Staff', 'email' => 'audit@example.invalid', 'role' => 'cashier', 'password' => 'Test-only-password-123'];
        $this->post('/fabric/action/staff-save', $staff)->assertSessionHasNoErrors();
        $user = User::where('email', $staff['email'])->firstOrFail();
        $this->assertTrue(Hash::check($staff['password'], $user->password));
        $this->post('/fabric/action/staff-save/'.$user->id, [...$staff, 'name' => 'Edited Staff', 'email' => 'edited@example.invalid', 'role' => 'manager', 'password' => ''])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Edited Staff', 'email' => 'edited@example.invalid', 'fabric_role' => 'manager']);
        $this->assertTrue(Hash::check($staff['password'], $user->fresh()->password));
    }

    public function test_opening_stock_rejects_invalid_amounts_quantities_and_duplicate_identifiers(): void
    {
        $product = FabricInventory::saveProduct($this->productPayload());
        $valid = ['product_id' => $product, 'color_id' => DB::table('fabric_colors')->value('id'), 'quantity' => '5.250', 'unit_cost' => '20.50', 'selling_price' => '50.25', 'roll_number' => 'AUDIT-ROLL', 'barcode' => 'AUDIT-ROLL-BC', 'effective_at' => now()->toIso8601String(), 'reason' => 'Physical stock counted'];
        foreach (['quantity' => ['0', '-1', '1.1234', 'abc'], 'unit_cost' => ['-1', '1.234', 'abc'], 'selling_price' => ['-1', '1.234', 'abc'], 'effective_at' => ['not-a-date']] as $field => $values) {
            foreach ($values as $value) {
                $this->postJson('/fabric/action/opening-stock', [...$valid, $field => $value])->assertUnprocessable();
                $this->assertDatabaseCount('fabric_rolls', 0);
            }
        }
        $this->post('/fabric/action/opening-stock', $valid)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fabric_rolls', ['roll_number' => 'AUDIT-ROLL', 'current_qty_milli' => 5250, 'unit_cost' => 2050, 'selling_price' => 5025]);
        $this->postJson('/fabric/action/opening-stock', $valid)->assertUnprocessable()->assertJsonValidationErrors('roll_number');
        $this->postJson('/fabric/action/opening-stock', [...$valid, 'roll_number' => 'ANOTHER'])->assertUnprocessable()->assertJsonValidationErrors('barcode');
    }

    public function test_marketing_fields_update_import_optout_and_campaign_approval_without_delivery(): void
    {
        $data = ['phone' => '03001234567', 'name' => 'Audit Contact', 'whatsapp_consent' => true, 'whatsapp_consent_source' => 'Signed form', 'sms_consent' => true, 'sms_consent_source' => 'At counter', 'opted_out' => false];
        $this->post('/fabric/action/marketing-contact', $data)->assertSessionHasNoErrors();
        $contact = DB::table('fabric_marketing_contacts')->first();
        $this->assertSame('+923001234567', $contact->phone);
        $this->assertNotNull($contact->whatsapp_consent_at);
        $this->post('/fabric/action/marketing-contact', [...$data, 'name' => 'Edited Contact', 'sms_consent' => false])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('fabric_marketing_contacts', 1);
        $this->assertDatabaseHas('fabric_marketing_contacts', ['id' => $contact->id, 'name' => 'Edited Contact', 'sms_consent' => false, 'sms_consent_at' => null]);
        $this->post('/fabric/action/marketing-import', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', "phone,name\n03001234567,Existing\n03007654321,Imported\n")])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fabric_marketing_contacts', ['phone' => '+923007654321', 'name' => 'Imported', 'whatsapp_consent' => false]);
        $this->post('/fabric/action/marketing-optout/'.$contact->id)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fabric_marketing_contacts', ['id' => $contact->id, 'opted_out' => true]);
        $this->post('/fabric/action/campaign-save', ['name' => 'Audit Campaign', 'channel' => 'sms', 'message' => 'Test message', 'provider' => ''])->assertSessionHasNoErrors();
        $campaign = DB::table('fabric_marketing_campaigns')->first();
        $this->post('/fabric/action/campaign-approve/'.$campaign->id)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fabric_marketing_campaigns', ['id' => $campaign->id, 'state' => 'approved', 'message' => 'Test message']);
    }

    public function test_expense_file_validation_storage_and_account_transfer(): void
    {
        Storage::fake('local');
        $cash = DB::table('fabric_accounts')->where('kind', 'cash')->value('id');
        $bank = DB::table('fabric_accounts')->where('kind', 'bank')->value('id');
        $expense = ['category' => 'Rent', 'amount' => '125.50', 'account_id' => $cash, 'effective_at' => now()->toIso8601String(), 'note' => 'September rent', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/fabric/action/expense', [...$expense, 'receipt' => UploadedFile::fake()->create('bad.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('receipt');
        $this->post('/fabric/action/expense', [...$expense, 'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nTest receipt")])->assertSessionHasNoErrors();
        $row = DB::table('fabric_expenses')->first();
        $this->assertSame(12550, (int) $row->amount);
        $this->assertSame('September rent', $row->note);
        Storage::disk('local')->assertExists($row->receipt_path);
        $this->get('/fabric/expense-receipt/'.$row->id)->assertOk();
        $transfer = ['from_account_id' => $cash, 'to_account_id' => $bank, 'amount' => '20.75', 'effective_at' => now()->toIso8601String(), 'note' => 'Cash deposit', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/fabric/action/transfer', [...$transfer, 'to_account_id' => $cash])->assertUnprocessable()->assertJsonValidationErrors('to_account_id');
        $this->post('/fabric/action/transfer', $transfer)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fabric_transfers', ['amount' => 2075, 'note' => 'Cash deposit']);
        $this->assertDatabaseHas('fabric_account_entries', ['account_id' => $cash, 'direction' => 'out', 'amount' => 2075]);
        $this->assertDatabaseHas('fabric_account_entries', ['account_id' => $bank, 'direction' => 'in', 'amount' => 2075]);
    }

    public function test_all_page_role_permissions(): void
    {
        $sections = ['dashboard', 'pos', 'customers', 'vendors', 'purchases', 'inventory', 'returns', 'cash-bank', 'expenses', 'promotions', 'marketing', 'reports', 'settings'];
        foreach (['owner', 'manager', 'cashier'] as $role) {
            $this->actingAs(User::factory()->create(['is_owner' => $role === 'owner', 'fabric_role' => $role]));
            foreach ($sections as $section) {
                $allowed = $role === 'owner' || ($role === 'manager' && $section !== 'settings') || ($role === 'cashier' && in_array($section, ['dashboard', 'pos', 'customers', 'inventory']));
                $response = $this->get('/fabric'.($section === 'dashboard' ? '' : '/'.$section));
                $allowed ? $response->assertOk() : $response->assertForbidden();
            }
        }
    }
}

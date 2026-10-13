<?php

namespace Tests\Feature\Fabric;

use App\Domain\FabricDocuments;
use App\Domain\FabricInventory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private int $roll;
    private string $barcode = 'roll_a/01-x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::factory()->create(['is_owner' => true, 'fabric_role' => 'owner']));
        $product = FabricInventory::saveProduct(['category_id' => DB::table('fabric_categories')->value('id'),
            'name' => 'Label cotton', 'sku' => 'LABEL', 'unit' => 'meter', 'default_selling_price' => '20', 'low_stock' => '0', 'active' => true]);
        $this->roll = FabricInventory::openingStock(['product_id' => $product, 'color_id' => DB::table('fabric_colors')->value('id'),
            'quantity' => '10', 'unit_cost' => '5', 'selling_price' => '20', 'barcode' => $this->barcode,
            'effective_at' => now()->subDay()->toIso8601String(), 'reason' => 'Label test']);
    }

    public function test_preview_and_print_label_preserve_the_exact_barcode_and_do_not_change_stock(): void
    {
        $svg = $this->get('/fabric/barcode-image/'.$this->roll)->assertOk()->assertHeader('content-type', 'image/svg+xml')->getContent();
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($svg, LIBXML_NONET));
        $this->assertSame($this->barcode, $document->getElementsByTagName('desc')->item(0)->textContent);
        $this->assertStringStartsWith('-20 0 ', $document->documentElement->getAttribute('viewBox'));
        $this->get('/fabric/document/barcode-label/'.$this->roll)->assertOk()->assertSee('Print label')->assertSee('Download PDF')
            ->assertSee('Label cotton')->assertSee($this->barcode)->assertSee('70mm 40mm')->assertDontSee('unit_cost');
        $pdf = $this->get('/fabric/document/barcode/'.$this->roll)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertSame(10000, (int) DB::table('fabric_rolls')->where('id', $this->roll)->value('current_qty_milli'));
        $this->assertDatabaseCount('fabric_sales', 0);
    }

    public function test_printed_code_scans_exact_stock_and_only_completed_sale_deducts_it_once(): void
    {
        $this->getJson('/fabric/barcode/'.rawurlencode($this->barcode))->assertOk()->assertJsonPath('record.id', $this->roll);
        $this->assertSame(10000, (int) DB::table('fabric_rolls')->where('id', $this->roll)->value('current_qty_milli'));
        $payload = ['sale_at' => now()->toIso8601String(), 'idempotency_key' => (string) Str::uuid(),
            'lines' => [['roll_id' => $this->roll, 'quantity' => '2.5', 'unit_price' => '20']],
            'payments' => [['account_id' => DB::table('fabric_accounts')->where('kind', 'cash')->value('id'), 'amount' => '50']]];
        $this->postJson('/fabric/action/sale', $payload)->assertRedirect();
        $this->postJson('/fabric/action/sale', $payload)->assertRedirect();
        $this->assertSame(7500, (int) DB::table('fabric_rolls')->where('id', $this->roll)->value('current_qty_milli'));
        $this->assertSame(1, DB::table('fabric_stock_movements')->where('kind', 'sale')->where('roll_id', $this->roll)->count());
    }

    public function test_labels_require_login_and_missing_stock_returns_not_found(): void
    {
        $this->get('/fabric/barcode-image/999999')->assertNotFound();
        $this->get('/fabric/document/barcode-label/999999')->assertNotFound();
        auth()->logout();
        $this->get('/fabric/barcode-image/'.$this->roll)->assertRedirect('/login');
        $this->get('/fabric/document/barcode-label/'.$this->roll)->assertRedirect('/login');
    }

    public function test_unencodable_legacy_value_is_never_silently_changed(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        FabricDocuments::barcodeSvg('کپڑا');
    }
}

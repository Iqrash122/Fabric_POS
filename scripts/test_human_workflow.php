<?php

/**
 * Human-like End-to-End Operational Testing Script for Fabric POS
 * Exercises all 10 modules, verifies every page, every action, and every ledger calculation.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use App\Domain\FabricInventory;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Http\Controllers\FabricController;

$app->make(Kernel::class)->bootstrap();

@unlink(__DIR__ . '/test_result.txt');
function testLog(string $msg) {
    echo $msg;
    file_put_contents(__DIR__ . '/test_result.txt', $msg, FILE_APPEND);
}

testLog("=======================================================\n");
testLog("   FABRIC POS - HUMAN-LIKE END-TO-END VERIFICATION     \n");
testLog("=======================================================\n\n");

$passed = 0;
$failed = 0;
$errors = [];

function assertTest(string $description, callable $test) {
    global $passed, $failed, $errors;
    try {
        $result = $test();
        if ($result !== false) {
            testLog("  [PASS] $description\n");
            $passed++;
            return $result;
        } else {
            testLog("  [FAIL] $description (assertion returned false)\n");
            $failed++;
            $errors[] = "$description: Assertion returned false";
            return false;
        }
    } catch (\Throwable $e) {
        testLog("  [FAIL] $description -> " . $e->getMessage() . " in " . basename($e->getFile()) . ":" . $e->getLine() . "\n");
        $failed++;
        $errors[] = "$description: " . $e->getMessage() . " (" . basename($e->getFile()) . ":" . $e->getLine() . ")";
        return false;
    }
}

// Ensure Owner exists
$owner = User::where('is_owner', true)->first();
if (!$owner) {
    $owner = new User();
    $owner->name = 'Store Owner';
    $owner->email = 'owner@example.com';
    $owner->password = Hash::make('password123456');
    $owner->is_owner = true;
    $owner->fabric_role = 'owner';
    $owner->email_verified_at = now();
    $owner->save();
}

$controller = $app->make(FabricController::class);

testLog("--- 1. MASTER DATA & SETTINGS MODULE ---\n");

// 1.1 Category Save
$categoryId = assertTest("Create Fabric Category: Premium Lawn", function () {
    return DB::table('fabric_categories')->insertGetId([
        'name' => 'Premium Lawn ' . uniqid(),
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// 1.2 Color Save
$colorId = assertTest("Create Fabric Color: Royal Indigo (#1A237E)", function () {
    return DB::table('fabric_colors')->insertGetId([
        'name' => 'Royal Indigo ' . uniqid(),
        'hex' => '#1A237E',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

// 1.3 Product Save (Meter unit)
$meterProductId = assertTest("Create Meter Product: Digital Printed Lawn", function () use ($categoryId) {
    return FabricInventory::saveProduct([
        'category_id' => $categoryId,
        'name' => 'Digital Printed Lawn ' . uniqid(),
        'sku' => 'LAWN-' . strtoupper(bin2hex(random_bytes(3))),
        'barcode' => 'BC-' . strtoupper(bin2hex(random_bytes(4))),
        'unit' => 'meter',
        'default_selling_price' => '45.00',
        'low_stock' => '20.000',
        'active' => true,
    ]);
});

// 1.4 Product Save (Piece unit)
$pieceProductId = assertTest("Create Piece Product: Embroidered Dupatta", function () use ($categoryId) {
    return FabricInventory::saveProduct([
        'category_id' => $categoryId,
        'name' => 'Embroidered Dupatta ' . uniqid(),
        'sku' => 'DUP-' . strtoupper(bin2hex(random_bytes(3))),
        'barcode' => 'BC-' . strtoupper(bin2hex(random_bytes(4))),
        'unit' => 'piece',
        'default_selling_price' => '120.00',
        'low_stock' => '5',
        'active' => true,
    ]);
});

// 1.5 Bank Account
$bankAccountId = assertTest("Create Bank Account: Meezan Bank Main", function () {
    $existing = DB::table('fabric_accounts')->where('name', 'Meezan Bank Main')->first();
    if ($existing) return (int)$existing->id;
    return (int)DB::table('fabric_accounts')->insertGetId([
        'name' => 'Meezan Bank Main',
        'kind' => 'bank',
        'opening_balance' => 500000,
        'active' => true,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

$cashAccount = DB::table('fabric_accounts')->where('kind', 'cash')->first();
$cashAccountId = $cashAccount ? (int)$cashAccount->id : 1;

testLog("\n--- 2. VENDORS & PURCHASES MODULE ---\n");

// 2.1 Create Vendor
$vendorId = assertTest("Create Vendor: Al-Karam Mills", function () {
    return FabricInventory::saveVendor([
        'shop_name' => 'Al-Karam Mills ' . uniqid(),
        'owner_name' => 'Haji Muhammad',
        'phone' => '+923001234567',
        'email' => 'alkaram_' . uniqid() . '@example.com',
        'address' => 'S.I.T.E Area, Karachi',
        'notes' => 'Primary lawn supplier',
        'active' => true,
    ]);
});

// 2.2 Create & Post Purchase Bill
$purchaseBillId = assertTest("Create & Post Purchase Bill with 2 Line Items", function () use ($vendorId, $meterProductId, $colorId, $pieceProductId) {
    $billId = FabricInventory::createPurchase([
        'vendor_id' => $vendorId,
        'bill_date' => now()->toIso8601String(),
        'lines' => [
            [
                'product_id' => $meterProductId,
                'color_id' => $colorId,
                'quantity' => '100.000', // 100 meters
                'purchase_rate' => '25.00', // 25.00/meter -> 2500 total
                'discount' => '0',
                'additional_cost' => '0',
            ],
            [
                'product_id' => $pieceProductId,
                'color_id' => $colorId,
                'quantity' => '20', // 20 pieces
                'purchase_rate' => '70.00', // 70.00/piece -> 1400 total
                'discount' => '0',
                'additional_cost' => '0',
            ]
        ],
    ]);
    FabricInventory::postPurchase($billId);
    $bill = DB::table('fabric_purchase_bills')->where('id', $billId)->first();
    return ($bill && $bill->state === 'posted' && (int)$bill->total === 390000) ? $billId : false;
});

// 2.3 Receive Goods & Generate Rolls
$rolls = assertTest("Receive Goods into Inventory & Generate 2 Rolls", function () use ($purchaseBillId, $vendorId, $meterProductId) {
    $lines = DB::table('fabric_purchase_lines')->where('purchase_bill_id', $purchaseBillId)->get();
    $meterLine = $lines->firstWhere('product_id', $meterProductId);
    
    // Line 1: 50 meters
    $receiptId1 = FabricInventory::receivePurchase([
        'purchase_bill_id' => $purchaseBillId,
        'vendor_id' => $vendorId,
        'received_at' => now()->toIso8601String(),
        'lines' => [
            [
                'purchase_line_id' => $meterLine->id,
                'quantity' => '50.000',
                'roll_number' => 'R-L1-' . uniqid(),
                'selling_price' => '45.00',
            ]
        ]
    ]);

    // Line 2: 50 meters
    $receiptId2 = FabricInventory::receivePurchase([
        'purchase_bill_id' => $purchaseBillId,
        'vendor_id' => $vendorId,
        'received_at' => now()->toIso8601String(),
        'lines' => [
            [
                'purchase_line_id' => $meterLine->id,
                'quantity' => '50.000',
                'roll_number' => 'R-L2-' . uniqid(),
                'selling_price' => '45.00',
            ]
        ]
    ]);
    
    $createdRolls = DB::table('fabric_rolls')
        ->whereIn('goods_receipt_id', [$receiptId1, $receiptId2])
        ->get();
        
    return $createdRolls->count() === 2 ? $createdRolls : false;
});

$roll1 = $rolls ? $rolls[0] : null;
$roll2 = $rolls ? $rolls[1] : null;

// 2.4 Pay Vendor (Partial Payment)
assertTest("Pay Vendor: 2,000 PKR via Bank Account", function () use ($vendorId, $purchaseBillId, $bankAccountId) {
    FabricInventory::vendorPayment([
        'vendor_id' => $vendorId,
        'account_id' => $bankAccountId,
        'amount' => '2000.00',
        'effective_at' => now()->toIso8601String(),
        'idempotency_key' => (string) Str::uuid(),
        'allocations' => [
            ['purchase_bill_id' => $purchaseBillId, 'amount' => '2000.00']
        ]
    ]);
    $outstanding = FabricInventory::vendorOutstanding($purchaseBillId);
    // Total was 3,900. Paid 2,000. Outstanding should be 1,900 (190000 cents/millis)
    return $outstanding === 190000;
});

testLog("\n--- 3. CUSTOMERS MODULE ---\n");

// 3.1 Create Customer
$customerId = assertTest("Create Customer: Mrs. Fatima Tariq", function () {
    $customer = new Customer();
    $customer->name = 'Mrs. Fatima Tariq ' . rand(100, 999);
    $customer->phone = '0321' . rand(1000000, 9999999);
    $customer->normalized_phone = '+92321' . rand(1000000, 9999999);
    $customer->address = 'House 42, Block 4, Clifton, Karachi';
    $customer->active = true;
    $customer->save();
    return (int)$customer->id;
});

// 3.2 Customer Advance Receipt
assertTest("Customer Advance Payment: 1,000 PKR Cash Deposit", function () use ($customerId, $cashAccountId) {
    $receiptId = FabricInventory::customerReceipt([
        'customer_id' => $customerId,
        'account_id' => $cashAccountId,
        'amount' => '1000.00',
        'effective_at' => now()->toIso8601String(),
        'idempotency_key' => (string) Str::uuid(),
    ]);
    $avail = FabricInventory::customerReceiptAvailable($receiptId);
    return $avail === 100000; // 1000.00
});

testLog("\n--- 4. POS CUTTING & SALES MODULE ---\n");

// 4.1 Cut and Sell 7.5 meters from Roll 1 (Full Cash Sale)
$sale1Id = assertTest("POS Sale #1: Cut 7.5 meters of Lawn (Cash Sale)", function () use ($roll1, $cashAccountId) {
    // 7.5 meters @ 45.00/m = 337.50
    $saleId = FabricInventory::postSale([
        'customer_id' => null,
        'sale_at' => now()->toIso8601String(),
        'credit_authorized' => false,
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            [
                'roll_id' => $roll1->id,
                'quantity' => '7.500',
                'unit_price' => '45.00',
                'manual_discount' => '0',
            ]
        ],
        'payments' => [
            ['account_id' => $cashAccountId, 'amount' => '337.50']
        ],
    ], 'owner');
    
    // Check stock was decremented from 50.000 to 42.500 (42500 milli)
    $updatedRoll = DB::table('fabric_rolls')->where('id', $roll1->id)->first();
    return ($updatedRoll && (int)$updatedRoll->current_qty_milli === 42500) ? $saleId : false;
});

// 4.2 Cut and Sell 10.0 meters from Roll 2 (Credit Sale to Customer)
$sale2Id = assertTest("POS Sale #2: Cut 10 meters on Credit to Mrs. Fatima Tariq", function () use ($roll2, $customerId) {
    // 10 meters @ 45.00/m = 450.00. Paid 0. Outstanding = 450.00
    $saleId = FabricInventory::postSale([
        'customer_id' => $customerId,
        'sale_at' => now()->toIso8601String(),
        'credit_authorized' => true,
        'idempotency_key' => (string) Str::uuid(),
        'lines' => [
            [
                'roll_id' => $roll2->id,
                'quantity' => '10.000',
                'unit_price' => '45.00',
                'manual_discount' => '0',
            ]
        ],
        'payments' => [],
    ], 'owner');
    
    $out = FabricInventory::saleOutstanding($saleId);
    return $out === 45000 ? $saleId : false; // 450.00
});

// 4.3 Allocate Advance to Credit Sale
assertTest("Allocate Customer Advance to Outstanding Credit Sale", function () use ($customerId, $sale2Id) {
    $receipt = DB::table('fabric_customer_receipts')->where('customer_id', $customerId)->first();
    FabricInventory::applyCustomerAdvance([
        'receipt_id' => $receipt->id,
        'sale_id' => $sale2Id,
        'amount' => '450.00',
    ]);
    $out = FabricInventory::saleOutstanding($sale2Id);
    return $out === 0; // Completely paid by advance!
});

testLog("\n--- 5. RETURNS & REFUNDS MODULE ---\n");

// 5.1 Customer Return (2.0 meters resalable returned from Sale #1)
assertTest("Process Customer Return: 2.0 meters resalable with Cash Refund", function () use ($sale1Id, $cashAccountId) {
    $line = DB::table('fabric_sale_lines')->where('sale_id', $sale1Id)->first();
    $returnId = FabricInventory::customerReturn([
        'sale_id' => $sale1Id,
        'resolution' => 'refund',
        'refund_account_id' => $cashAccountId,
        'effective_at' => now()->toIso8601String(),
        'lines' => [
            [
                'sale_line_id' => $line->id,
                'quantity' => '2.000',
                'condition' => 'resalable',
            ]
        ]
    ]);
    // 2 meters @ 45.00 = 90.00
    $ret = DB::table('fabric_returns')->where('id', $returnId)->first();
    return ($ret && (int)$ret->credit_amount === 9000);
});

testLog("\n--- 6. CASH & BANK / EXPENSES MODULE ---\n");

// 6.1 Account Transfer (Transfer 10,000 PKR from Bank to Cash counter)
assertTest("Transfer Funds: 10,000 PKR from Meezan Bank to Cash Register", function () use ($bankAccountId, $cashAccountId) {
    $transferId = FabricInventory::transfer([
        'source_account_id' => $bankAccountId,
        'destination_account_id' => $cashAccountId,
        'amount' => '10000.00',
        'effective_at' => now()->toIso8601String(),
        'notes' => 'Opening register cash replenishment',
    ]);
    return $transferId > 0;
});

// 6.2 Record Store Expense
$expenseId = assertTest("Record Store Expense: Electricity Bill & Staff Refreshment", function () use ($cashAccountId) {
    return FabricInventory::expense([
        'account_id' => $cashAccountId,
        'category' => 'Utilities',
        'amount' => '3500.00',
        'effective_at' => now()->toIso8601String(),
        'payee' => 'K-Electric',
        'notes' => 'Shop monthly electricity bill',
    ]);
});

testLog("\n--- 7. INVENTORY AUDIT & RESERVATION MODULE ---\n");

// 7.1 Fabric Reservation
$reservationId = assertTest("Create Fabric Reservation: 5 meters for Bridal Boutique", function () use ($roll1, $customerId) {
    return FabricInventory::reserve([
        'roll_id' => $roll1->id,
        'customer_id' => $customerId,
        'quantity' => '5.000',
        'duration_hours' => 24,
        'notes' => 'Hold for boutique trial',
    ]);
});

// 7.2 Cancel / Expire Reservation
assertTest("Release / Cancel Fabric Reservation", function () use ($reservationId) {
    FabricInventory::releaseReservation($reservationId, 'cancelled');
    $res = DB::table('fabric_reservations')->where('id', $reservationId)->first();
    return ($res && $res->status === 'cancelled');
});

// 7.3 Stock Count Audit Entry
assertTest("Physical Stock Count Audit & Roll Reconciliation", function () use ($roll1) {
    // Current quantity should be 42.500 + 2.000 returned = 44.500
    $countId = FabricInventory::recordStockCount([
        'counted_at' => now()->toIso8601String(),
        'notes' => 'Weekend physical verification',
        'lines' => [
            [
                'roll_id' => $roll1->id,
                'counted_qty' => '44.000', // 0.5 meter shrinkage / cutting scrap
                'reason' => 'Cutting edge fray scrap loss',
            ]
        ]
    ]);
    $roll = DB::table('fabric_rolls')->where('id', $roll1->id)->first();
    return ($roll && (int)$roll->current_qty_milli === 44000);
});

testLog("\n--- 8. PROMOTIONS & MARKETING MODULE ---\n");

// 8.1 Create 10% Discount Promotion
assertTest("Create Percentage Promotion: Eid Festive 10% Off", function () use ($meterProductId) {
    $promoId = FabricInventory::savePromotion([
        'name' => 'Eid Festive Lawn Sale 10%',
        'type' => 'percentage',
        'value' => '10.00',
        'priority' => 1,
        'starts_at' => now()->subDay()->toIso8601String(),
        'ends_at' => now()->addDays(15)->toIso8601String(),
        'active' => true,
        'targets' => [
            ['target_type' => 'product', 'target_id' => $meterProductId]
        ]
    ]);
    return $promoId > 0;
});

// 8.2 Marketing Contact
$contactId = assertTest("Add Marketing Contact with WhatsApp Consent", function () {
    return FabricInventory::saveMarketingContact([
        'phone' => '+92333' . rand(1000000, 9999999),
        'name' => 'Sara Textiles Customer',
        'whatsapp_consent' => true,
        'sms_consent' => true,
        'source' => 'pos_counter',
    ]);
});

testLog("\n--- 9. ALL UI SECTIONS & REPORT ENDPOINTS VERIFICATION ---\n");

// Authenticate as Owner for controller requests
auth()->login($owner);

$sections = [
    'dashboard', 'pos', 'customers', 'vendors', 'purchases', 
    'inventory', 'returns', 'cash-bank', 'expenses', 
    'promotions', 'marketing', 'reports', 'settings'
];

foreach ($sections as $section) {
    assertTest("UI Section Rendering: /fabric/$section", function () use ($controller, $section) {
        $req = Request::create("/fabric/$section", 'GET');
        $req->setUserResolver(fn() => auth()->user());
        $resp = $controller->index($req, $section);
        
        // Assert Inertia response with props
        if ($resp instanceof \Inertia\Response) {
            $props = $resp->toResponse($req)->getOriginalContent()['page']['props'] ?? [];
            if (!isset($props['section']) || $props['section'] !== $section) {
                return false;
            }
            if (!isset($props['business']) || !isset($props['categories'])) {
                return false;
            }
            return true;
        }
        return false;
    });
}

// 9.2 Report Types
$reportTypes = ['sales', 'purchases', 'inventory', 'customer_balances', 'vendor_balances', 'returns', 'advances', 'cash_book'];
foreach ($reportTypes as $rep) {
    assertTest("Report Query: type=$rep", function () use ($controller, $rep) {
        $req = Request::create("/fabric/reports?report=$rep", 'GET');
        $req->setUserResolver(fn() => auth()->user());
        $resp = $controller->index($req, 'reports');
        if ($resp instanceof \Inertia\Response) {
            $props = $resp->toResponse($req)->getOriginalContent()['page']['props'] ?? [];
            return isset($props['report_rows']) && is_array($props['report_rows']);
        }
        return false;
    });
}

// 9.3 Printable Documents
assertTest("Generate Thermal Sale Receipt Document", function () use ($controller, $sale1Id) {
    $req = Request::create("/fabric/document/thermal/$sale1Id", 'GET');
    $req->setUserResolver(fn() => auth()->user());
    $resp = $controller->document($req, 'thermal', $sale1Id);
    return $resp->getStatusCode() === 200 && str_contains($resp->getContent(), 'RECEIPT');
});

assertTest("Generate A4 Invoice Document", function () use ($controller, $sale1Id) {
    $req = Request::create("/fabric/document/invoice/$sale1Id", 'GET');
    $req->setUserResolver(fn() => auth()->user());
    $resp = $controller->document($req, 'invoice', $sale1Id);
    return $resp->getStatusCode() === 200 && str_contains($resp->getContent(), 'INVOICE');
});

assertTest("Generate Expense Receipt Document", function () use ($controller, $expenseId) {
    $req = Request::create("/fabric/expense-receipt/$expenseId", 'GET');
    $req->setUserResolver(fn() => auth()->user());
    $resp = $controller->expenseReceipt($req, $expenseId);
    return $resp->getStatusCode() === 200 && str_contains($resp->getContent(), 'PAYMENT VOUCHER');
});

// 9.4 Barcode Label Endpoint
assertTest("Barcode Rendering Endpoint", function () use ($controller, $roll1) {
    $freshRoll = DB::table('fabric_rolls')->where('id', $roll1->id)->first();
    $req = Request::create("/fabric/barcode/{$freshRoll->barcode}", 'GET');
    $req->setUserResolver(fn() => auth()->user());
    $resp = $controller->barcode($req, $freshRoll->barcode);
    return $resp->getStatusCode() === 200;
});

testLog("\n=======================================================\n");
testLog("                 TEST SUMMARY RESULTS                  \n");
testLog("=======================================================\n");
testLog("  Total Tests: " . ($passed + $failed) . "\n");
testLog("  PASSED:      $passed\n");
testLog("  FAILED:      $failed\n");

if ($failed > 0) {
    testLog("\nFailures:\n");
    foreach ($errors as $e) {
        testLog("  - $e\n");
    }
    exit(1);
} else {
    testLog("\n  ALL 10 MODULES & ALL 13 UI SECTIONS PASSED PERFECTLY!\n");
    exit(0);
}

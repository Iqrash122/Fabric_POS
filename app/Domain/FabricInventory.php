<?php

namespace App\Domain;

use App\Models\AuditEvent;
use App\Models\BusinessSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FabricInventory
{
    public static function fail(string $message, string $field = 'form'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function qty(mixed $value, string $unit, bool $allowZero = false): int
    {
        $text = trim((string) $value);
        if (! is_numeric($text) || ! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $text)) {
            self::fail('Enter a quantity with at most three decimal places.', 'quantity');
        }
        if ($unit === 'piece' && str_contains($text, '.') && (int) substr($text, strpos($text, '.') + 1) !== 0) {
            self::fail('Piece quantities must be whole numbers.', 'quantity');
        }
        $milli = (int) bcmul($text, '1000', 0);
        if ((! $allowZero && $milli <= 0) || ($allowZero && $milli < 0)) {
            self::fail($allowZero ? 'Quantity cannot be negative.' : 'Quantity must be greater than zero.', 'quantity');
        }

        return $milli;
    }

    public static function amountForQty(int $unitPrice, int $qtyMilli): int
    {
        if ($unitPrice < 0 || $qtyMilli < 0) {
            self::fail('Invalid quantity or price.');
        }

        return Pricing::round(bcdiv(bcmul((string) $unitPrice, (string) $qtyMilli, 0), '1000', 8));
    }

    private static function validateStockBarcode(string $barcode): void
    {
        if (! preg_match('/^[\x20-\x7E]+$/D', $barcode)) {
            self::fail('Use English letters, numbers or printable symbols for the stock barcode. Enter a custom barcode if the roll number contains other characters.', 'barcode');
        }
    }

    public static function date(mixed $value): CarbonInterface
    {
        try {
            return Carbon::parse((string) $value, BusinessSetting::findOrFail(1)->timezone)->utc();
        } catch (\Throwable) {
            self::fail('Enter a valid date and time.', 'effective_at');
        }
    }

    public static function reference(string $kind): string
    {
        $sequence = DB::table('number_sequences')->where('kind', $kind)->lockForUpdate()->first();
        if (! $sequence) {
            self::fail('Run the application seeders first.');
        }
        DB::table('number_sequences')->where('id', $sequence->id)->increment('next_number');

        return $kind.'-'.str_pad((string) $sequence->next_number, 5, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $changes */
    public static function audit(string $entity, int $id, string $action, array $changes = []): void
    {
        AuditEvent::create([
            'user_id' => auth()->id(),
            'entity' => $entity,
            'entity_id' => $id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable():array{type:string,id:int} $callback
     * @return array{type:string,id:int,replayed:bool}
     */
    private static function idempotent(string $scope, string $key, array $payload, callable $callback): array
    {
        if ($key === '' || strlen($key) > 120) {
            self::fail('A valid request key is required. Refresh the form and try again.');
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($scope, $key, $callback, $hash) {
            $inserted = DB::table('fabric_idempotency')->insertOrIgnore([
                'scope' => $scope,
                'key' => $key,
                'payload_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $record = DB::table('fabric_idempotency')
                ->where('scope', $scope)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();
            if (! $record || ! hash_equals((string) $record->payload_hash, $hash)) {
                self::fail('This request key was already used with different data. Refresh and try again.');
            }
            if (! $inserted && $record->result_id) {
                return ['type' => (string) $record->result_type, 'id' => (int) $record->result_id, 'replayed' => true];
            }

            $result = $callback();
            DB::table('fabric_idempotency')->where('id', $record->id)->update([
                'result_type' => $result['type'],
                'result_id' => $result['id'],
                'updated_at' => now(),
            ]);

            return [...$result, 'replayed' => false];
        }, 3);
    }

    private static function accountEntry(int $accountId, string $direction, int $amount, string $kind, string $referenceType, int $referenceId, CarbonInterface $effectiveAt, ?string $notes = null): void
    {
        if ($amount <= 0 || ! in_array($direction, ['in', 'out'], true)) {
            self::fail('Invalid cash or bank movement.');
        }
        DB::table('fabric_accounts')->where('active', true)->lockForUpdate()->where('id', $accountId)->firstOrFail();
        DB::table('fabric_account_entries')->insert([
            'account_id' => $accountId,
            'direction' => $direction,
            'amount' => $amount,
            'kind' => $kind,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'effective_at' => $effectiveAt,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function stockMovement(\stdClass $roll, string $kind, int $delta, string $referenceType, int $referenceId, CarbonInterface $effectiveAt, ?string $reason = null, ?string $eventSuffix = null): void
    {
        $eventKey = implode(':', [$kind, $referenceType, $referenceId, $roll->id, $eventSuffix ?? '1']);
        $inserted = DB::table('fabric_stock_movements')->insertOrIgnore([
            'roll_id' => $roll->id,
            'product_id' => $roll->product_id,
            'color_id' => $roll->color_id,
            'kind' => $kind,
            'qty_delta_milli' => $delta,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'event_key' => $eventKey,
            'reason' => $reason,
            'effective_at' => $effectiveAt,
            'user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (! $inserted) {
            self::fail('This stock movement was already posted.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function saveVendor(array $data, ?int $id = null): int
    {
        return DB::transaction(function () use ($data, $id): int {
            $existing = $id ? DB::table('fabric_vendors')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            $values = [
                'shop_name' => trim((string) $data['shop_name']),
                'owner_name' => trim((string) ($data['owner_name'] ?? '')) ?: null,
                'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
                'email' => trim((string) ($data['email'] ?? '')) ?: null,
                'address' => trim((string) ($data['address'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) ($existing->active ?? true),
                'updated_at' => now(),
            ];
            if ($values['shop_name'] === '') {
                self::fail('Vendor shop name is required.', 'shop_name');
            }
            $wasExisting = (bool) $id;
            if ($id) {
                if (! $values['active'] && $existing->active) {
                    $summary = self::vendorSummary($id);
                    $hasDraft = DB::table('fabric_purchase_bills')->where('vendor_id', $id)->where('state', 'draft')->exists();
                    if ($summary['payable'] !== 0 || $summary['advance'] !== 0 || $summary['credit'] !== 0 || $hasDraft) {
                        self::fail('Settle vendor payables, advances and credits and finish or remove draft purchases before archiving.', 'active');
                    }
                }
                DB::table('fabric_vendors')->where('id', $id)->update([...$values, 'version' => DB::raw('version + 1')]);
            } else {
                $id = DB::table('fabric_vendors')->insertGetId([...$values, 'version' => 1, 'created_at' => now()]);
            }
            self::audit('fabric_vendors', $id, $wasExisting ? 'saved' : 'created', $values);

            return (int) $id;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public static function saveProduct(array $data, ?int $id = null): int
    {
        return DB::transaction(function () use ($data, $id): int {
            $unit = (string) ($data['unit'] ?? 'meter');
            if (! in_array($unit, ['meter', 'piece'], true)) {
                self::fail('Unit must be meter or piece.', 'unit');
            }
            $existing = $id ? DB::table('fabric_products')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            $values = [
                'category_id' => (int) $data['category_id'],
                'name' => trim((string) $data['name']),
                'sku' => strtoupper(trim((string) $data['sku'])),
                'barcode' => trim((string) ($data['barcode'] ?? '')) ?: null,
                'unit' => $unit,
                'default_selling_price' => Pricing::minor($data['default_selling_price'] ?? '0'),
                'low_stock_milli' => self::qty($data['low_stock'] ?? '0', $unit, true),
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) ($existing->active ?? true),
                'updated_at' => now(),
            ];
            if ($values['name'] === '' || $values['sku'] === '') {
                self::fail('Product name and SKU are required.');
            }
            DB::table('fabric_categories')->where('active', true)->where('id', $values['category_id'])->firstOrFail();
            if ($id) {
                if ($existing->active && ! $values['active']) {
                    $onHand = (int) DB::table('fabric_rolls')->where('product_id', $id)->where('current_qty_milli', '>', 0)->sum('current_qty_milli');
                    $pendingReceipt = DB::table('fabric_purchase_lines')
                        ->join('fabric_purchase_bills', 'fabric_purchase_bills.id', '=', 'fabric_purchase_lines.purchase_bill_id')
                        ->where('fabric_purchase_lines.product_id', $id)
                        ->whereIn('fabric_purchase_bills.state', ['draft', 'posted'])
                        ->whereColumn('fabric_purchase_lines.received_qty_milli', '<', 'fabric_purchase_lines.ordered_qty_milli')
                        ->exists();
                    if ($onHand > 0 || $pendingReceipt) {
                        self::fail('A product with stock on hand or quantity awaiting receipt cannot be archived.', 'active');
                    }
                }
                if ($existing->unit !== $unit) {
                    $hasHistory = DB::table('fabric_rolls')->where('product_id', $id)->exists()
                        || DB::table('fabric_purchase_lines')->where('product_id', $id)->exists()
                        || DB::table('fabric_sale_lines')->where('product_id', $id)->exists();
                    if ($hasHistory) {
                        self::fail('A product unit cannot be changed after inventory or transaction history exists.', 'unit');
                    }
                }
                DB::table('fabric_products')->where('id', $id)->update([...$values, 'version' => DB::raw('version + 1')]);
            } else {
                $id = DB::table('fabric_products')->insertGetId([...$values, 'version' => 1, 'created_at' => now()]);
            }
            self::audit('fabric_products', $id, 'saved', $values);

            return (int) $id;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public static function createOpeningBalance(array $data): int
    {
        if (isset($data['idempotency_key'])) {
            return self::idempotent('opening-balance', (string) $data['idempotency_key'], $data, function () use ($data) {
                unset($data['idempotency_key']);
                return ['type' => 'opening_balance', 'id' => self::createOpeningBalance($data)];
            })['id'];
        }
        $partyType = (string) $data['party_type'];
        $kind = (string) $data['kind'];
        if (! in_array($partyType, ['customer', 'vendor'], true) || ! in_array($kind, ['receivable', 'payable', 'advance'], true)) {
            self::fail('Invalid opening balance type.');
        }
        if ($partyType === 'customer' && $kind === 'payable') {
            self::fail('Customer opening balance cannot be a payable.');
        }
        if ($partyType === 'vendor' && $kind === 'receivable') {
            self::fail('Vendor opening balance cannot be a receivable.');
        }
        $partyId = (int) $data['party_id'];
        DB::table($partyType === 'customer' ? 'customers' : 'fabric_vendors')->where('id', $partyId)->firstOrFail();
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            self::fail('Opening balance reason is required.', 'reason');
        }
        $amount = Pricing::minor($data['amount']);
        if ($amount <= 0) {
            self::fail('Opening balance amount must be greater than zero.', 'amount');
        }
        $id = DB::table('fabric_opening_balances')->insertGetId([
            'party_type' => $partyType,
            'party_id' => $partyId,
            'kind' => $kind,
            'amount' => $amount,
            'effective_at' => self::date($data['effective_at']),
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::audit('fabric_opening_balances', $id, 'created');

        return $id;
    }

    public static function openingRemaining(int $openingBalanceId): int
    {
        $balance = DB::table('fabric_opening_balances')->where('id', $openingBalanceId)->firstOrFail();
        $applied = (int) DB::table('fabric_opening_balance_applications')->where('opening_balance_id', $openingBalanceId)->sum('amount');

        return max(0, (int) $balance->amount - $applied);
    }

    /** Apply existing money to previous debt without recording another cash movement.
     * @param array<string,mixed> $data
     */
    public static function applyAdvanceToPreviousDebt(array $data): int
    {
        return self::idempotent('advance-to-previous-debt', (string) $data['idempotency_key'], $data, function () use ($data) {
            $debt = DB::table('fabric_opening_balances')->where('id', (int) $data['opening_balance_id'])->lockForUpdate()->firstOrFail();
            $vendor = $debt->party_type === 'vendor';
            if ($debt->kind !== ($vendor ? 'payable' : 'receivable')) {
                self::fail('Select an unpaid previous balance.', 'opening_balance_id');
            }
            $sourceType = (string) $data['source_type'];
            $sourceId = (int) $data['source_id'];
            if ($sourceType === 'opening_advance') {
                $source = DB::table('fabric_opening_balances')->where('id', $sourceId)->lockForUpdate()->firstOrFail();
                if ($source->kind !== 'advance' || $source->party_type !== $debt->party_type || (int) $source->party_id !== (int) $debt->party_id) {
                    self::fail('Advance and previous balance must belong to the same account.', 'source_id');
                }
                $available = self::openingRemaining($sourceId);
            } else {
                if ($sourceType !== ($vendor ? 'vendor_payment' : 'customer_receipt')) {
                    self::fail('Choose an advance from the same account type.', 'source_type');
                }
                $source = DB::table($vendor ? 'fabric_vendor_payments' : 'fabric_customer_receipts')->where('id', $sourceId)->lockForUpdate()->firstOrFail();
                $partyId = $vendor ? (int) $source->vendor_id : (int) $source->customer_id;
                if ($partyId !== (int) $debt->party_id) {
                    self::fail('Advance and previous balance must belong to the same account.', 'source_id');
                }
                $available = $vendor ? self::vendorPaymentAvailable($sourceId) : self::customerReceiptAvailable($sourceId);
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > $available || $amount > self::openingRemaining((int) $debt->id)) {
                self::fail('Amount must be positive and no greater than the available advance or unpaid previous balance.', 'amount');
            }
            $effectiveAt = now();
            if ($effectiveAt->lt(Carbon::parse($source->effective_at, 'UTC')) || $effectiveAt->lt(Carbon::parse($debt->effective_at, 'UTC'))) {
                self::fail('A future-dated advance or balance cannot be adjusted yet.', 'opening_balance_id');
            }
            if ($sourceType === 'opening_advance') {
                DB::table('fabric_opening_balance_applications')->insert([
                    'opening_balance_id' => $sourceId, 'target_type' => 'opening_balance', 'target_id' => $debt->id,
                    'amount' => $amount, 'effective_at' => $effectiveAt, 'reason' => 'Opening advance applied to previous debt',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $id = DB::table('fabric_opening_balance_applications')->insertGetId([
                'opening_balance_id' => $debt->id, 'target_type' => $sourceType, 'target_id' => $sourceId,
                'amount' => $amount, 'effective_at' => $effectiveAt, 'reason' => 'Existing advance adjusted against previous debt',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::audit('fabric_opening_balance_applications', $id, 'advance_applied', ['party_type' => $debt->party_type, 'party_id' => $debt->party_id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'amount' => $amount]);
            return ['type' => 'opening_balance_application', 'id' => $id];
        })['id'];
    }

    /** @param array<string,mixed> $data */
    public static function applyOpeningAdvance(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $opening = DB::table('fabric_opening_balances')->where('id', (int) $data['opening_balance_id'])->lockForUpdate()->firstOrFail();
            if ($opening->kind !== 'advance') {
                self::fail('Only an opening advance can be applied to a later transaction.');
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::openingRemaining((int) $opening->id)) {
                self::fail('Advance application exceeds the available opening advance.');
            }
            $effectiveAt = self::date($data['effective_at']);
            if ($effectiveAt->lt(Carbon::parse($opening->effective_at, 'UTC'))) {
                self::fail('Advance application cannot be dated before the opening advance.');
            }
            $targetType = (string) $data['target_type'];
            $targetId = (int) $data['target_id'];
            if ($opening->party_type === 'customer') {
                if ($targetType !== 'sale') {
                    self::fail('A customer advance can only be applied to a customer sale.');
                }
                $sale = DB::table('fabric_sales')->where('customer_id', $opening->party_id)->where('state', 'posted')->where('id', $targetId)->lockForUpdate()->firstOrFail();
                if ($effectiveAt->lt(Carbon::parse($sale->sale_at, 'UTC'))) {
                    self::fail('Advance application cannot be dated before the sale.');
                }
                if ($amount > self::saleOutstanding((int) $sale->id)) {
                    self::fail('Advance application exceeds the sale balance.');
                }
            } else {
                if ($targetType !== 'purchase_bill') {
                    self::fail('A vendor advance can only be applied to a vendor purchase bill.');
                }
                $bill = DB::table('fabric_purchase_bills')->where('vendor_id', $opening->party_id)->where('state', 'posted')->where('id', $targetId)->lockForUpdate()->firstOrFail();
                if ($effectiveAt->lt(Carbon::parse($bill->bill_date, 'UTC'))) {
                    self::fail('Advance application cannot be dated before the purchase bill.');
                }
                if ($amount > self::vendorOutstanding((int) $bill->id)) {
                    self::fail('Advance application exceeds the purchase balance.');
                }
            }
            $id = DB::table('fabric_opening_balance_applications')->insertGetId([
                'opening_balance_id' => $opening->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'amount' => $amount,
                'effective_at' => $effectiveAt,
                'reason' => trim((string) ($data['reason'] ?? 'Applied opening advance')),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::audit('fabric_opening_balance_applications', $id, 'advance_applied');

            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function createPurchase(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $vendorId = (int) $data['vendor_id'];
            DB::table('fabric_vendors')->where('active', true)->where('id', $vendorId)->lockForUpdate()->firstOrFail();
            $lines = $data['lines'] ?? [];
            if (! is_array($lines) || $lines === []) {
                self::fail('Add at least one purchase line.', 'lines');
            }
            $subtotal = $discount = $additional = 0;
            $normalized = [];
            foreach ($lines as $line) {
                $product = DB::table('fabric_products')->where('active', true)->where('id', (int) $line['product_id'])->firstOrFail();
                $color = DB::table('fabric_colors')->where('active', true)->where('id', (int) $line['color_id'])->firstOrFail();
                $qty = self::qty($line['quantity'], $product->unit);
                $rate = Pricing::minor($line['purchase_rate']);
                $gross = self::amountForQty($rate, $qty);
                $lineDiscount = Pricing::minor($line['discount'] ?? '0');
                $lineCost = Pricing::minor($line['additional_cost'] ?? '0');
                if ($lineDiscount > $gross) {
                    self::fail('Purchase line discount exceeds its gross amount.');
                }
                $total = $gross - $lineDiscount + $lineCost;
                $subtotal += $gross;
                $discount += $lineDiscount;
                $additional += $lineCost;
                $normalized[] = [$line, $qty, $rate, $lineDiscount, $lineCost, $total, $product, $color];
            }
            $id = DB::table('fabric_purchase_bills')->insertGetId([
                'reference' => self::reference('FB'),
                'vendor_id' => $vendorId,
                'supplier_reference' => trim((string) ($data['supplier_reference'] ?? '')) ?: null,
                'bill_date' => self::date($data['bill_date']),
                'state' => 'draft',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'additional_cost' => $additional,
                'total' => $subtotal - $discount + $additional,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($normalized as [$line, $qty, $rate, $lineDiscount, $lineCost, $total, $product, $color]) {
                DB::table('fabric_purchase_lines')->insert([
                    'purchase_bill_id' => $id,
                    'product_id' => (int) $line['product_id'],
                    'color_id' => (int) $line['color_id'],
                    'ordered_qty_milli' => $qty,
                    'received_qty_milli' => 0,
                    'purchase_rate' => $rate,
                    'discount_amount' => $lineDiscount,
                    'allocated_cost' => $lineCost,
                    'line_total' => $total,
                    'product_name_snapshot' => $product->name,
                    'color_name_snapshot' => $color->name,
                    'unit_snapshot' => $product->unit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            self::audit('fabric_purchase_bills', $id, 'created');

            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function updatePurchaseDraft(int $id, array $data): void
    {
        DB::transaction(function () use ($id, $data) {
            $bill = DB::table('fabric_purchase_bills')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($bill->state !== 'draft') {
                self::fail('Only a draft purchase bill can be edited.');
            }
            $hasReceipt = DB::table('fabric_purchase_lines')->where('purchase_bill_id', $id)->where('received_qty_milli', '>', 0)->exists()
                || DB::table('fabric_goods_receipts')->where('purchase_bill_id', $id)->exists();
            if ($hasReceipt) {
                self::fail('A purchase bill cannot be edited after any goods have been received. Use a return or adjustment for posted stock.');
            }

            $vendorId = (int) $data['vendor_id'];
            DB::table('fabric_vendors')->where('active', true)->where('id', $vendorId)->lockForUpdate()->firstOrFail();
            $lines = $data['lines'] ?? [];
            if (! is_array($lines) || $lines === []) {
                self::fail('Add at least one purchase line.', 'lines');
            }

            $subtotal = $discount = $additional = 0;
            $normalized = [];
            foreach ($lines as $line) {
                $product = DB::table('fabric_products')->where('active', true)->where('id', (int) $line['product_id'])->firstOrFail();
                $color = DB::table('fabric_colors')->where('active', true)->where('id', (int) $line['color_id'])->firstOrFail();
                $qty = self::qty($line['quantity'], $product->unit);
                $rate = Pricing::minor($line['purchase_rate']);
                $gross = self::amountForQty($rate, $qty);
                $lineDiscount = Pricing::minor($line['discount'] ?? '0');
                $lineCost = Pricing::minor($line['additional_cost'] ?? '0');
                if ($lineDiscount > $gross) {
                    self::fail('Purchase line discount exceeds its gross amount.');
                }
                $total = $gross - $lineDiscount + $lineCost;
                $subtotal += $gross;
                $discount += $lineDiscount;
                $additional += $lineCost;
                $normalized[] = [$line, $qty, $rate, $lineDiscount, $lineCost, $total, $product, $color];
            }

            DB::table('fabric_purchase_lines')->where('purchase_bill_id', $id)->delete();
            DB::table('fabric_purchase_bills')->where('id', $id)->update([
                'vendor_id' => $vendorId,
                'supplier_reference' => trim((string) ($data['supplier_reference'] ?? '')) ?: null,
                'bill_date' => self::date($data['bill_date']),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'additional_cost' => $additional,
                'total' => $subtotal - $discount + $additional,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            foreach ($normalized as [$line, $qty, $rate, $lineDiscount, $lineCost, $total, $product, $color]) {
                DB::table('fabric_purchase_lines')->insert([
                    'purchase_bill_id' => $id,
                    'product_id' => (int) $line['product_id'],
                    'color_id' => (int) $line['color_id'],
                    'ordered_qty_milli' => $qty,
                    'received_qty_milli' => 0,
                    'purchase_rate' => $rate,
                    'discount_amount' => $lineDiscount,
                    'allocated_cost' => $lineCost,
                    'line_total' => $total,
                    'product_name_snapshot' => $product->name,
                    'color_name_snapshot' => $color->name,
                    'unit_snapshot' => $product->unit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            self::audit('fabric_purchase_bills', $id, 'draft_updated', ['total' => $subtotal - $discount + $additional]);
        });
    }

    public static function postPurchase(int $id): void
    {
        DB::transaction(function () use ($id) {
            $bill = DB::table('fabric_purchase_bills')->lockForUpdate()->where('id', $id)->firstOrFail();
            if ($bill->state === 'posted') {
                return;
            }
            if ($bill->state !== 'draft') {
                self::fail('Only a draft purchase bill can be posted.');
            }
            DB::table('fabric_purchase_bills')->where('id', $id)->update([
                'state' => 'posted', 'posted_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
            self::audit('fabric_purchase_bills', $id, 'posted');
        });
    }

    /** @param array<string,mixed> $data */
    public static function receivePurchase(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $bill = DB::table('fabric_purchase_bills')->lockForUpdate()->where('id', (int) $data['purchase_bill_id'])->firstOrFail();
            $vendorId = (int) $data['vendor_id'];
            if ((int) $bill->vendor_id !== $vendorId) {
                self::fail('Receipt vendor must match the purchase bill.');
            }
            $receivedAt = self::date($data['received_at']);
            $receiptId = DB::table('fabric_goods_receipts')->insertGetId([
                'reference' => self::reference('GR'),
                'vendor_id' => $vendorId,
                'purchase_bill_id' => $bill->id,
                'received_at' => $receivedAt,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lines = $data['lines'] ?? [];
            if (! is_array($lines) || $lines === []) {
                self::fail('Add at least one received roll.');
            }
            foreach ($lines as $index => $row) {
                $purchaseLine = DB::table('fabric_purchase_lines')->lockForUpdate()->where('id', (int) $row['purchase_line_id'])->firstOrFail();
                if ((int) $purchaseLine->purchase_bill_id !== (int) $bill->id) {
                    self::fail('A receipt line does not belong to this purchase bill.');
                }
                $product = DB::table('fabric_products')->where('id', $purchaseLine->product_id)->firstOrFail();
                $qty = self::qty($row['quantity'], $product->unit);
                $remaining = (int) $purchaseLine->ordered_qty_milli - (int) $purchaseLine->received_qty_milli;
                if ($qty > $remaining) {
                    self::fail('Received quantity exceeds the quantity still awaiting receipt.');
                }
                $baseCost = max(0, (int) $purchaseLine->line_total);
                $unitCost = $purchaseLine->ordered_qty_milli > 0
                    ? Pricing::round(bcdiv(bcmul((string) $baseCost, '1000', 0), (string) $purchaseLine->ordered_qty_milli, 8))
                    : 0;
                $rollNo = trim((string) ($row['roll_number'] ?? '')) ?: self::reference('FR');
                $barcode = trim((string) ($row['barcode'] ?? '')) ?: DB::table('fabric_settings')->value('barcode_prefix').'-'.$rollNo;
                self::validateStockBarcode($barcode);
                $rollId = DB::table('fabric_rolls')->insertGetId([
                    'roll_number' => $rollNo,
                    'barcode' => $barcode,
                    'product_id' => $purchaseLine->product_id,
                    'color_id' => $purchaseLine->color_id,
                    'vendor_id' => $vendorId,
                    'goods_receipt_id' => $receiptId,
                    'purchase_line_id' => $purchaseLine->id,
                    'parent_roll_id' => null,
                    'original_qty_milli' => $qty,
                    'current_qty_milli' => $qty,
                    'reserved_qty_milli' => 0,
                    'unit_cost' => $unitCost,
                    'selling_price' => isset($row['selling_price']) ? Pricing::minor($row['selling_price']) : (int) $product->default_selling_price,
                    'condition' => 'sellable',
                    'active' => true,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('fabric_receipt_lines')->insert([
                    'goods_receipt_id' => $receiptId,
                    'purchase_line_id' => $purchaseLine->id,
                    'roll_id' => $rollId,
                    'quantity_milli' => $qty,
                    'unit_cost' => $unitCost,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('fabric_purchase_lines')->where('id', $purchaseLine->id)->increment('received_qty_milli', $qty, ['updated_at' => now()]);
                $roll = DB::table('fabric_rolls')->where('id', $rollId)->firstOrFail();
                self::stockMovement($roll, 'receipt', $qty, 'goods_receipt', $receiptId, $receivedAt, 'Purchase goods receipt', (string) $index);
            }
            self::audit('fabric_goods_receipts', $receiptId, 'posted');

            return $receiptId;
        });
    }

    public static function vendorOutstanding(int $billId): int
    {
        $bill = DB::table('fabric_purchase_bills')->where('id', $billId)->firstOrFail();
        if ($bill->state !== 'posted') {
            return 0;
        }
        $paid = (int) DB::table('fabric_vendor_payment_allocations')->where('purchase_bill_id', $billId)->sum('amount');
        $credits = (int) DB::table('fabric_vendor_credit_allocations')->where('purchase_bill_id', $billId)->sum('amount');
        $openingAdvances = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
            ->where('fabric_opening_balances.party_type', 'vendor')
            ->where('fabric_opening_balances.kind', 'advance')
            ->where('fabric_opening_balance_applications.target_type', 'purchase_bill')
            ->where('fabric_opening_balance_applications.target_id', $billId)
            ->sum('fabric_opening_balance_applications.amount');

        return max(0, (int) $bill->total - $paid - $credits - $openingAdvances);
    }

    /** @return array{payable:int,advance:int,credit:int} */
    public static function vendorSummary(int $vendorId): array
    {
        $billIds = DB::table('fabric_purchase_bills')->where('vendor_id', $vendorId)->where('state', 'posted')->pluck('id');
        $payable = 0;
        foreach ($billIds as $id) {
            $payable += self::vendorOutstanding((int) $id);
        }
        $openingPayable = 0;
        foreach (DB::table('fabric_opening_balances')->where(['party_type' => 'vendor', 'party_id' => $vendorId, 'kind' => 'payable'])->pluck('id') as $openingId) {
            $openingPayable += self::openingRemaining((int) $openingId);
        }
        $payments = (int) DB::table('fabric_vendor_payments')->where('vendor_id', $vendorId)->sum('amount');
        $allocated = (int) DB::table('fabric_vendor_payment_allocations')->join('fabric_vendor_payments', 'fabric_vendor_payments.id', '=', 'fabric_vendor_payment_allocations.vendor_payment_id')->where('fabric_vendor_payments.vendor_id', $vendorId)->sum('fabric_vendor_payment_allocations.amount');
        $openingDebtPaid = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_vendor_payments', 'fabric_vendor_payments.id', '=', 'fabric_opening_balance_applications.target_id')
            ->where('fabric_opening_balance_applications.target_type', 'vendor_payment')
            ->where('fabric_vendor_payments.vendor_id', $vendorId)
            ->sum('fabric_opening_balance_applications.amount');
        $openingAdvance = 0;
        foreach (DB::table('fabric_opening_balances')->where(['party_type' => 'vendor', 'party_id' => $vendorId, 'kind' => 'advance'])->pluck('id') as $openingId) {
            $openingAdvance += self::openingRemaining((int) $openingId);
        }
        $creditTotal = (int) DB::table('fabric_vendor_credits')->where('vendor_id', $vendorId)->sum('amount');
        $creditApplied = (int) DB::table('fabric_vendor_credit_allocations')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_credit_allocations.vendor_credit_id')->where('fabric_vendor_credits.vendor_id', $vendorId)->sum('fabric_vendor_credit_allocations.amount');
        $creditRefunded = (int) DB::table('fabric_vendor_refunds')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_refunds.vendor_credit_id')->where('fabric_vendor_credits.vendor_id', $vendorId)->sum('fabric_vendor_refunds.amount');

        return [
            'payable' => $payable + $openingPayable,
            'advance' => max(0, $payments - $allocated - $openingDebtPaid) + $openingAdvance,
            'credit' => max(0, $creditTotal - $creditApplied - $creditRefunded),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function vendorPayment(array $data): int
    {
        $result = self::idempotent('vendor-payment', (string) $data['idempotency_key'], $data, function () use ($data) {
            $vendorId = (int) $data['vendor_id'];
            DB::table('fabric_vendors')->where('active', true)->where('id', $vendorId)->lockForUpdate()->firstOrFail();
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0) {
                self::fail('Payment must be greater than zero.');
            }
            $effectiveAt = self::date($data['effective_at']);
            if (isset($data['payment_mode'])) {
                if (! empty($data['allocations']) || ! empty($data['opening_allocations'])) {
                    self::fail('Choose a payment mode or explicit allocations, not both.', 'payment_mode');
                }
                $mode = $data['payment_mode'];
                if (! in_array($mode, ['outstanding', 'bill', 'advance'], true)) {
                    self::fail('Select how this payment should be used.', 'payment_mode');
                }
                $data['allocations'] = [];
                $data['opening_allocations'] = [];
                $remaining = $amount;
                if ($mode === 'outstanding') {
                    $openings = DB::table('fabric_opening_balances')
                        ->where(['party_type' => 'vendor', 'party_id' => $vendorId, 'kind' => 'payable'])
                        ->where('effective_at', '<=', $effectiveAt)->orderBy('effective_at')->orderBy('id')->lockForUpdate()->get();
                    foreach ($openings as $opening) {
                        $part = min($remaining, self::openingRemaining((int) $opening->id));
                        if ($part > 0) {
                            $data['opening_allocations'][] = ['opening_balance_id' => (int) $opening->id, 'amount' => bcdiv((string) $part, '100', 2)];
                            $remaining -= $part;
                        }
                    }
                }
                if ($mode !== 'advance') {
                    $bills = DB::table('fabric_purchase_bills')->where('vendor_id', $vendorId)->where('state', 'posted');
                    if ($mode === 'bill') {
                        $bill = (clone $bills)->where('id', (int) ($data['purchase_bill_id'] ?? 0))->lockForUpdate()->first();
                        if (! $bill || self::vendorOutstanding((int) $bill->id) <= 0) {
                            self::fail('Select an unpaid posted bill for this vendor.', 'purchase_bill_id');
                        }
                        $bills->where('id', $bill->id);
                    } else {
                        $bills->where('bill_date', '<=', $effectiveAt);
                    }
                    foreach ($bills->orderBy('bill_date')->orderBy('id')->lockForUpdate()->get() as $bill) {
                        $part = min($remaining, self::vendorOutstanding((int) $bill->id));
                        if ($part > 0) {
                            $data['allocations'][] = ['purchase_bill_id' => (int) $bill->id, 'amount' => bcdiv((string) $part, '100', 2)];
                            $remaining -= $part;
                        }
                    }
                    if ($remaining === $amount) {
                        self::fail('This vendor has no unpaid balance on the payment date. Choose Advance payment for a payment on account.', 'payment_mode');
                    }
                }
            }
            $id = DB::table('fabric_vendor_payments')->insertGetId([
                'reference' => self::reference('FP'),
                'vendor_id' => $vendorId,
                'account_id' => (int) $data['account_id'],
                'amount' => $amount,
                'effective_at' => $effectiveAt,
                'idempotency_key' => (string) $data['idempotency_key'],
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $allocated = 0;
            foreach (($data['opening_allocations'] ?? []) as $allocation) {
                $opening = DB::table('fabric_opening_balances')->where('id', (int) $allocation['opening_balance_id'])->lockForUpdate()->firstOrFail();
                if ($opening->party_type !== 'vendor' || (int) $opening->party_id !== $vendorId || $opening->kind !== 'payable') {
                    self::fail('Selected opening payable does not belong to this vendor.');
                }
                if ($effectiveAt->lt(Carbon::parse($opening->effective_at, 'UTC'))) {
                    self::fail('Vendor payment cannot be dated before the opening payable.');
                }
                $part = Pricing::minor($allocation['amount']);
                if ($part <= 0 || $part > self::openingRemaining((int) $opening->id)) {
                    self::fail('Opening payable allocation exceeds its remaining balance.');
                }
                $allocated += $part;
                if ($allocated > $amount) {
                    self::fail('Vendor allocations exceed the payment amount.');
                }
                DB::table('fabric_opening_balance_applications')->insert([
                    'opening_balance_id' => $opening->id,
                    'target_type' => 'vendor_payment',
                    'target_id' => $id,
                    'amount' => $part,
                    'effective_at' => $effectiveAt,
                    'reason' => 'Vendor payment applied to opening payable',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (($data['allocations'] ?? []) as $allocation) {
                $billId = (int) $allocation['purchase_bill_id'];
                $bill = DB::table('fabric_purchase_bills')->where('vendor_id', $vendorId)->where('state', 'posted')->lockForUpdate()->where('id', $billId)->firstOrFail();
                if ($effectiveAt->lt(Carbon::parse($bill->bill_date, 'UTC'))) {
                    self::fail('Vendor payment cannot be allocated to a purchase bill dated later than the payment. Keep it as an advance and apply it after the bill date.');
                }
                $part = Pricing::minor($allocation['amount']);
                if ($part <= 0 || $part > self::vendorOutstanding((int) $bill->id)) {
                    self::fail('Vendor payment allocation exceeds the selected bill balance.');
                }
                $allocated += $part;
                if ($allocated > $amount) {
                    self::fail('Vendor allocations exceed the payment amount.');
                }
                DB::table('fabric_vendor_payment_allocations')->insert([
                    'vendor_payment_id' => $id,
                    'purchase_bill_id' => $billId,
                    'amount' => $part,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            self::accountEntry((int) $data['account_id'], 'out', $amount, 'vendor_payment', 'vendor_payment', $id, $effectiveAt, $data['notes'] ?? null);
            self::audit('fabric_vendor_payments', $id, 'posted', ['allocated' => $allocated, 'advance' => $amount - $allocated]);

            return ['type' => 'vendor_payment', 'id' => $id];
        });

        return $result['id'];
    }

    /** @param array<string,mixed> $data */
    public static function createInstallments(array $data): int
    {
        $vendorId = (int) $data['vendor_id'];
        DB::table('fabric_vendors')->where('id', $vendorId)->firstOrFail();
        $billId = isset($data['purchase_bill_id']) && $data['purchase_bill_id'] !== '' ? (int) $data['purchase_bill_id'] : null;
        if ($billId) {
            DB::table('fabric_purchase_bills')->where('vendor_id', $vendorId)->where('state', 'posted')->where('id', $billId)->firstOrFail();
        }
        $cadence = (string) $data['cadence'];
        if (! in_array($cadence, ['daily', 'weekly', 'monthly'], true)) {
            self::fail('Installment cadence must be daily, weekly or monthly.');
        }
        $count = max(1, min(120, (int) ($data['count'] ?? 1)));
        $amount = Pricing::minor($data['amount']);
        if ($amount <= 0) {
            self::fail('Installment amount must be greater than zero.', 'amount');
        }
        if ($billId && $amount * $count > self::vendorOutstanding($billId)) {
            self::fail('Installment schedule exceeds the current purchase bill balance.', 'amount');
        }
        $first = self::date($data['first_due_at']);
        $firstId = 0;
        DB::transaction(function () use ($vendorId, $billId, $cadence, $count, $amount, $first, $data, &$firstId) {
            $due = $first->copy();
            for ($i = 1; $i <= $count; $i++) {
                $id = DB::table('fabric_vendor_installments')->insertGetId([
                    'vendor_id' => $vendorId,
                    'purchase_bill_id' => $billId,
                    'cadence' => $cadence,
                    'amount' => $amount,
                    'due_at' => $due,
                    'status' => 'due',
                    'payment_id' => null,
                    'sequence' => $i,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($firstId === 0) { $firstId = $id; }
                $due = match ($cadence) {
                    'daily' => $due->copy()->addDay(),
                    'weekly' => $due->copy()->addWeek(),
                    default => $due->copy()->addMonthNoOverflow(),
                };
            }
            self::audit('fabric_vendor_installments', $firstId, 'schedule_created', ['count' => $count]);
        });

        return $firstId;
    }

    public static function settleInstallment(int $installmentId, int $paymentId): void
    {
        DB::transaction(function () use ($installmentId, $paymentId) {
            $installment = DB::table('fabric_vendor_installments')->lockForUpdate()->where('id', $installmentId)->firstOrFail();
            if ($installment->status !== 'due') {
                self::fail('This installment is not due.');
            }
            $payment = DB::table('fabric_vendor_payments')->where('id', $paymentId)->lockForUpdate()->firstOrFail();
            if ((int) $payment->vendor_id !== (int) $installment->vendor_id) {
                self::fail('Installment and payment vendor do not match.');
            }
            $alreadyMatched = (int) DB::table('fabric_vendor_installments')->where('payment_id', $paymentId)->where('status', 'paid')->sum('amount');
            if ($alreadyMatched + (int) $installment->amount > (int) $payment->amount) {
                self::fail('This payment does not have enough amount to settle the selected installment.');
            }
            DB::table('fabric_vendor_installments')->where('id', $installmentId)->update(['status' => 'paid', 'payment_id' => $paymentId, 'updated_at' => now()]);
            self::audit('fabric_vendor_installments', $installmentId, 'settled', ['payment_id' => $paymentId]);
        });
    }

    /** @return array{discount:int,snapshots:array<int,array<string,mixed>>} */
    private static function promotionDiscount(\stdClass $product, int $colorId, int $gross, CarbonInterface $at): array
    {
        $promotions = DB::table('fabric_promotions')
            ->where('active', true)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->orderBy('priority')->orderBy('id')
            ->get();
        $remaining = $gross;
        $discount = 0;
        $snapshots = [];
        foreach ($promotions as $promotion) {
            $targets = DB::table('fabric_promotion_targets')->where('promotion_id', $promotion->id)->get();
            $matches = $targets->isEmpty() || $targets->contains(function ($target) use ($product, $colorId) {
                return ($target->target_type === 'product' && (int) $target->target_id === (int) $product->id)
                    || ($target->target_type === 'category' && (int) $target->target_id === (int) $product->category_id)
                    || ($target->target_type === 'color' && (int) $target->target_id === $colorId);
            });
            if (! $matches) {
                continue;
            }
            $part = Pricing::round(bcdiv(bcmul((string) $remaining, Pricing::decimal($promotion->percent), 4), '100', 8));
            $part = min($part, $remaining);
            $discount += $part;
            $remaining -= $part;
            $snapshots[] = [
                'id' => (int) $promotion->id,
                'name' => (string) $promotion->name,
                'percent' => (string) $promotion->percent,
                'priority' => (int) $promotion->priority,
                'discount' => $part,
            ];
            if (! $promotion->stackable) {
                break;
            }
        }

        return ['discount' => $discount, 'snapshots' => $snapshots];
    }

    /** @param array<string,mixed> $data */
    public static function postSale(array $data, string $role): int
    {
        $result = self::idempotent('sale', (string) $data['idempotency_key'], $data, function () use ($data, $role) {
            $saleAt = self::date($data['sale_at']);
            $customerId = isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null;
            if ($customerId) {
                DB::table('customers')->where('active', true)->where('id', $customerId)->firstOrFail();
            }
            $lines = $data['lines'] ?? [];
            if (! is_array($lines) || $lines === []) {
                self::fail('Add at least one fabric item.');
            }
            $normalized = [];
            $seenRolls = [];
            $grossTotal = $discountTotal = $netTotal = 0;
            foreach ($lines as $index => $line) {
                $rollId = (int) $line['roll_id'];
                if (isset($seenRolls[$rollId])) {
                    self::fail('Add each roll only once per sale. Combine the quantity into a single line.', 'lines');
                }
                $seenRolls[$rollId] = true;
                $roll = DB::table('fabric_rolls')->lockForUpdate()->where('id', $rollId)->firstOrFail();
                if (! $roll->active || ! in_array($roll->condition, ['sellable', 'remnant'], true)) {
                    self::fail('The selected roll is not available for sale.');
                }
                $product = DB::table('fabric_products')->where('active', true)->where('id', $roll->product_id)->firstOrFail();
                $color = DB::table('fabric_colors')->where('id', $roll->color_id)->firstOrFail();
                $qty = self::qty($line['quantity'], $product->unit);
                $available = max(0, (int) $roll->current_qty_milli - (int) $roll->reserved_qty_milli);
                if ($qty > $available) {
                    self::fail('Sale quantity exceeds available stock for '.$roll->roll_number.'.');
                }
                $rate = isset($line['unit_price']) && $line['unit_price'] !== '' ? Pricing::minor($line['unit_price']) : (int) $roll->selling_price;
                $gross = self::amountForQty($rate, $qty);
                $promo = self::promotionDiscount($product, (int) $roll->color_id, $gross, $saleAt);
                $manual = Pricing::minor($line['manual_discount'] ?? '0');
                if ($manual > 0 && ! in_array($role, ['owner', 'manager'], true)) {
                    self::fail('Manual discounts require owner or manager permission.');
                }
                if ($manual > 0 && trim((string) ($data['manual_discount_reason'] ?? '')) === '') {
                    self::fail('Enter a reason for a manual discount.');
                }
                $discount = $promo['discount'] + $manual;
                if ($discount > $gross) {
                    self::fail('Discount exceeds the line amount.');
                }
                $net = $gross - $discount;
                $cost = self::amountForQty((int) $roll->unit_cost, $qty);
                $normalized[] = [$index, $roll, $product, $color, $qty, $rate, $gross, $discount, $net, $cost, $promo['snapshots']];
                $grossTotal += $gross;
                $discountTotal += $discount;
                $netTotal += $net;
            }
            $payments = $data['payments'] ?? [];
            $paid = 0;
            $paymentKinds = [];
            foreach ($payments as $payment) {
                $paymentAccount = DB::table('fabric_accounts')->where('active', true)->lockForUpdate()->where('id', (int) $payment['account_id'])->firstOrFail();
                $paymentKinds[(int) $paymentAccount->id] = $paymentAccount->kind;
                $paymentAmount = Pricing::minor($payment['amount']);
                if ($paymentAmount <= 0) {
                    self::fail('Each immediate payment must be greater than zero.', 'payments');
                }
                $paid += $paymentAmount;
            }
            $tendered = $paid;
            if ($paid > $netTotal) {
                $excess = $paid - $netTotal;
                for ($i = count($payments) - 1; $i >= 0 && $excess > 0; $i--) {
                    if ($paymentKinds[(int) $payments[$i]['account_id']] !== 'cash') continue;
                    $currAmount = Pricing::minor($payments[$i]['amount']);
                    $reduce = min($currAmount, $excess);
                    $newAmount = $currAmount - $reduce;
                    $excess -= $reduce;
                    if ($newAmount > 0) {
                        $payments[$i]['amount'] = bcdiv((string) $newAmount, '100', 2);
                    } else {
                        unset($payments[$i]);
                    }
                }
                if ($excess > 0) {
                    self::fail('Bank payment exceeds the final amount. Enter the actual bank amount; change can only be returned from cash.', 'payments');
                }
                $payments = array_values($payments);
                $paid = $netTotal;
            }
            $balance = $netTotal - $paid;
            $creditAuthorized = (bool) ($data['credit_authorized'] ?? false);
            if ($balance > 0) {
                if (! DB::table('fabric_settings')->value('credit_sales_enabled') || ! $creditAuthorized) {
                    self::fail('Credit sale permission is required for an unpaid balance.');
                }
                if (! $customerId) {
                    self::fail('Identify the customer before keeping a credit balance.');
                }
                if (! in_array($role, ['owner', 'manager'], true)) {
                    self::fail('Credit sales require owner or manager permission.');
                }
            }
            $saleId = DB::table('fabric_sales')->insertGetId([
                'reference' => self::reference('FS'),
                'customer_id' => $customerId,
                'sale_at' => $saleAt,
                'state' => 'posted',
                'gross' => $grossTotal,
                'discount' => $discountTotal,
                'total' => $netTotal,
                'paid' => $paid,
                'tendered' => $tendered,
                'cashier_name' => auth()->user()?->name,
                'balance' => $balance,
                'credit_authorized' => $creditAuthorized,
                'manual_discount_reason' => trim((string) ($data['manual_discount_reason'] ?? '')) ?: null,
                'idempotency_key' => (string) $data['idempotency_key'],
                'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($normalized as [$index, $roll, $product, $color, $qty, $rate, $gross, $discount, $net, $cost, $snapshots]) {
                $lineId = DB::table('fabric_sale_lines')->insertGetId([
                    'sale_id' => $saleId,
                    'roll_id' => $roll->id,
                    'product_id' => $product->id,
                    'color_id' => $roll->color_id,
                    'line_type' => $product->unit === 'piece' ? 'piece' : 'fabric',
                    'quantity_milli' => $qty,
                    'unit_price' => $rate,
                    'gross' => $gross,
                    'discount' => $discount,
                    'net' => $net,
                    'cost' => $cost,
                    'promotion_snapshot' => json_encode($snapshots, JSON_THROW_ON_ERROR),
                    'product_name_snapshot' => $product->name,
                    'color_name_snapshot' => $color->name,
                    'roll_number_snapshot' => $roll->roll_number,
                    'unit_snapshot' => $product->unit,
                    'returned_qty_milli' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fabric_rolls')->where('id', $roll->id)->decrement('current_qty_milli', $qty, [
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
                self::stockMovement($roll, 'sale', -$qty, 'sale', $saleId, $saleAt, 'POS sale', (string) $lineId);
            }
            foreach ($payments as $payment) {
                $amount = Pricing::minor($payment['amount']);
                if ($amount <= 0) {
                    continue;
                }
                $paymentId = DB::table('fabric_sale_payments')->insertGetId([
                    'sale_id' => $saleId,
                    'account_id' => (int) $payment['account_id'],
                    'amount' => $amount,
                    'external_reference' => trim((string) ($payment['external_reference'] ?? '')) ?: null,
                    'effective_at' => $saleAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                self::accountEntry((int) $payment['account_id'], 'in', $amount, 'sale_receipt', 'sale_payment', $paymentId, $saleAt, $payment['external_reference'] ?? null);
            }
            self::audit('fabric_sales', $saleId, 'posted', ['gross' => $grossTotal, 'discount' => $discountTotal, 'total' => $netTotal, 'paid' => $paid]);

            return ['type' => 'sale', 'id' => $saleId];
        });

        return $result['id'];
    }

    public static function saleOutstanding(int $saleId): int
    {
        $sale = DB::table('fabric_sales')->where('id', $saleId)->firstOrFail();
        $later = (int) DB::table('fabric_customer_receipt_allocations')->where('sale_id', $saleId)->sum('amount');
        $credits = (int) DB::table('fabric_credit_allocations')->where('sale_id', $saleId)->sum('amount');
        $openingAdvances = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
            ->where('fabric_opening_balances.party_type', 'customer')
            ->where('fabric_opening_balances.kind', 'advance')
            ->where('fabric_opening_balance_applications.target_type', 'sale')
            ->where('fabric_opening_balance_applications.target_id', $saleId)
            ->sum('fabric_opening_balance_applications.amount');

        return max(0, (int) $sale->total - (int) $sale->paid - $later - $credits - $openingAdvances);
    }

    /** @return array{outstanding:int,advance:int,credit:int} */
    public static function customerSummary(int $customerId): array
    {
        $sales = DB::table('fabric_sales')->where('customer_id', $customerId)->where('state', 'posted')->pluck('id');
        $outstanding = 0;
        foreach ($sales as $id) {
            $outstanding += self::saleOutstanding((int) $id);
        }
        $opening = 0;
        foreach (DB::table('fabric_opening_balances')->where(['party_type' => 'customer', 'party_id' => $customerId, 'kind' => 'receivable'])->pluck('id') as $openingId) {
            $opening += self::openingRemaining((int) $openingId);
        }
        $receipts = (int) DB::table('fabric_customer_receipts')->where('customer_id', $customerId)->sum('amount');
        $allocated = (int) DB::table('fabric_customer_receipt_allocations')->join('fabric_customer_receipts', 'fabric_customer_receipts.id', '=', 'fabric_customer_receipt_allocations.customer_receipt_id')->where('fabric_customer_receipts.customer_id', $customerId)->sum('fabric_customer_receipt_allocations.amount');
        $openingDebtCollected = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_customer_receipts', 'fabric_customer_receipts.id', '=', 'fabric_opening_balance_applications.target_id')
            ->where('fabric_opening_balance_applications.target_type', 'customer_receipt')
            ->where('fabric_customer_receipts.customer_id', $customerId)
            ->sum('fabric_opening_balance_applications.amount');
        $openingAdvance = 0;
        foreach (DB::table('fabric_opening_balances')->where(['party_type' => 'customer', 'party_id' => $customerId, 'kind' => 'advance'])->pluck('id') as $openingId) {
            $openingAdvance += self::openingRemaining((int) $openingId);
        }
        $credits = (int) DB::table('fabric_customer_credits')->where('customer_id', $customerId)->sum('amount');
        $creditAllocated = (int) DB::table('fabric_credit_allocations')->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_credit_allocations.credit_id')->where('fabric_customer_credits.customer_id', $customerId)->sum('fabric_credit_allocations.amount');
        $refunded = (int) DB::table('fabric_customer_refunds')->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_customer_refunds.credit_id')->where('fabric_customer_credits.customer_id', $customerId)->sum('fabric_customer_refunds.amount');

        return [
            'outstanding' => $outstanding + $opening,
            'advance' => max(0, $receipts - $allocated - $openingDebtCollected) + $openingAdvance,
            'credit' => max(0, $credits - $creditAllocated - $refunded),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function customerReceipt(array $data): int
    {
        $result = self::idempotent('customer-receipt', (string) $data['idempotency_key'], $data, function () use ($data) {
            $customerId = (int) $data['customer_id'];
            DB::table('customers')->where('active', true)->where('id', $customerId)->lockForUpdate()->firstOrFail();
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0) { self::fail('Customer receipt amount must be greater than zero.', 'amount'); }
            $effectiveAt = self::date($data['effective_at']);
            if (isset($data['collection_mode'])) {
                if (! empty($data['allocations']) || ! empty($data['opening_allocations'])) {
                    self::fail('Choose a collection purpose or explicit allocations, not both.', 'collection_mode');
                }
                $mode = $data['collection_mode'];
                if (! in_array($mode, ['outstanding', 'invoice', 'advance'], true)) {
                    self::fail('Select the collection purpose.', 'collection_mode');
                }
                $data['allocations'] = [];
                $data['opening_allocations'] = [];
                $remaining = $amount;
                if ($mode === 'outstanding') {
                    $openings = DB::table('fabric_opening_balances')
                        ->where(['party_type' => 'customer', 'party_id' => $customerId, 'kind' => 'receivable'])
                        ->where('effective_at', '<=', $effectiveAt)->orderBy('effective_at')->orderBy('id')->lockForUpdate()->get();
                    foreach ($openings as $opening) {
                        $part = min($remaining, self::openingRemaining((int) $opening->id));
                        if ($part > 0) {
                            $data['opening_allocations'][] = ['opening_balance_id' => (int) $opening->id, 'amount' => bcdiv((string) $part, '100', 2)];
                            $remaining -= $part;
                        }
                    }
                }
                if ($mode !== 'advance') {
                    $sales = DB::table('fabric_sales')->where('customer_id', $customerId)->where('state', 'posted');
                    if ($mode === 'invoice') {
                        $sale = (clone $sales)->where('id', (int) ($data['sale_id'] ?? 0))->lockForUpdate()->first();
                        if (! $sale || self::saleOutstanding((int) $sale->id) <= 0) {
                            self::fail('Select an unpaid invoice for this customer.', 'sale_id');
                        }
                        $sales->where('id', $sale->id);
                    } else {
                        $sales->where('sale_at', '<=', $effectiveAt);
                    }
                    foreach ($sales->orderBy('sale_at')->orderBy('id')->lockForUpdate()->get() as $sale) {
                        $part = min($remaining, self::saleOutstanding((int) $sale->id));
                        if ($part > 0) {
                            $data['allocations'][] = ['sale_id' => (int) $sale->id, 'amount' => bcdiv((string) $part, '100', 2)];
                            $remaining -= $part;
                        }
                    }
                    if ($remaining === $amount) {
                        self::fail('No unpaid balance on this date. Choose Advance collection for money received on account.', 'collection_mode');
                    }
                }
            }
            $id = DB::table('fabric_customer_receipts')->insertGetId([
                'reference' => self::reference('CR'),
                'customer_id' => $customerId,
                'account_id' => (int) $data['account_id'],
                'amount' => $amount,
                'effective_at' => $effectiveAt,
                'idempotency_key' => (string) $data['idempotency_key'],
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $allocated = 0;
            foreach (($data['opening_allocations'] ?? []) as $allocation) {
                $opening = DB::table('fabric_opening_balances')->where('id', (int) $allocation['opening_balance_id'])->lockForUpdate()->firstOrFail();
                if ($opening->party_type !== 'customer' || (int) $opening->party_id !== $customerId || $opening->kind !== 'receivable') {
                    self::fail('Selected opening receivable does not belong to this customer.');
                }
                if ($effectiveAt->lt(Carbon::parse($opening->effective_at, 'UTC'))) {
                    self::fail('Customer receipt cannot be dated before the opening receivable.');
                }
                $part = Pricing::minor($allocation['amount']);
                if ($part <= 0 || $part > self::openingRemaining((int) $opening->id)) {
                    self::fail('Opening receivable allocation exceeds its remaining balance.');
                }
                $allocated += $part;
                if ($allocated > $amount) {
                    self::fail('Customer allocations exceed the receipt amount.');
                }
                DB::table('fabric_opening_balance_applications')->insert([
                    'opening_balance_id' => $opening->id,
                    'target_type' => 'customer_receipt',
                    'target_id' => $id,
                    'amount' => $part,
                    'effective_at' => $effectiveAt,
                    'reason' => 'Customer receipt applied to opening receivable',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (($data['allocations'] ?? []) as $allocation) {
                $saleId = (int) $allocation['sale_id'];
                $sale = DB::table('fabric_sales')->where('customer_id', $customerId)->where('state', 'posted')->lockForUpdate()->where('id', $saleId)->firstOrFail();
                if ($effectiveAt->lt(Carbon::parse($sale->sale_at, 'UTC'))) {
                    self::fail('Customer receipt cannot be allocated to a sale dated later than the receipt. Keep it as an advance and apply it after the sale date.');
                }
                $part = Pricing::minor($allocation['amount']);
                if ($part <= 0 || $part > self::saleOutstanding((int) $sale->id)) {
                    self::fail('Customer receipt allocation exceeds the selected invoice balance.');
                }
                $allocated += $part;
                if ($allocated > $amount) {
                    self::fail('Customer allocations exceed the receipt amount.');
                }
                DB::table('fabric_customer_receipt_allocations')->insert([
                    'customer_receipt_id' => $id,
                    'sale_id' => $saleId,
                    'amount' => $part,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            self::accountEntry((int) $data['account_id'], 'in', $amount, 'customer_receipt', 'customer_receipt', $id, $effectiveAt, $data['notes'] ?? null);
            self::audit('fabric_customer_receipts', $id, 'posted', ['allocated' => $allocated, 'advance' => $amount - $allocated]);

            return ['type' => 'customer_receipt', 'id' => $id];
        });

        return $result['id'];
    }

    public static function customerReceiptAvailable(int $receiptId): int
    {
        $receipt = DB::table('fabric_customer_receipts')->where('id', $receiptId)->firstOrFail();
        $allocated = (int) DB::table('fabric_customer_receipt_allocations')->where('customer_receipt_id', $receiptId)->sum('amount');
        $opening = (int) DB::table('fabric_opening_balance_applications')->where('target_type', 'customer_receipt')->where('target_id', $receiptId)->sum('amount');

        return max(0, (int) $receipt->amount - $allocated - $opening);
    }

    /** @param array<string,mixed> $data */
    public static function applyCustomerAdvance(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $receipt = DB::table('fabric_customer_receipts')->where('id', (int) $data['receipt_id'])->lockForUpdate()->firstOrFail();
            $sale = DB::table('fabric_sales')->where('id', (int) $data['sale_id'])->where('state', 'posted')->lockForUpdate()->firstOrFail();
            if ((int) $receipt->customer_id !== (int) $sale->customer_id) {
                self::fail('Receipt advance and sale must belong to the same customer.');
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::customerReceiptAvailable((int) $receipt->id)) {
                self::fail('Advance application exceeds the available customer advance.');
            }
            if ($amount > self::saleOutstanding((int) $sale->id)) {
                self::fail('Advance application exceeds the sale balance.');
            }
            $id = DB::table('fabric_customer_receipt_allocations')->insertGetId([
                'customer_receipt_id' => $receipt->id,
                'sale_id' => $sale->id,
                'amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::audit('fabric_customer_receipt_allocations', $id, 'advance_applied', ['receipt_id' => $receipt->id, 'sale_id' => $sale->id, 'amount' => $amount]);

            return $id;
        });
    }

    public static function vendorPaymentAvailable(int $paymentId): int
    {
        $payment = DB::table('fabric_vendor_payments')->where('id', $paymentId)->firstOrFail();
        $allocated = (int) DB::table('fabric_vendor_payment_allocations')->where('vendor_payment_id', $paymentId)->sum('amount');
        $opening = (int) DB::table('fabric_opening_balance_applications')->where('target_type', 'vendor_payment')->where('target_id', $paymentId)->sum('amount');

        return max(0, (int) $payment->amount - $allocated - $opening);
    }

    /** @param array<string,mixed> $data */
    public static function applyVendorAdvance(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $payment = DB::table('fabric_vendor_payments')->where('id', (int) $data['payment_id'])->lockForUpdate()->firstOrFail();
            $bill = DB::table('fabric_purchase_bills')->where('id', (int) $data['purchase_bill_id'])->where('state', 'posted')->lockForUpdate()->firstOrFail();
            if ((int) $payment->vendor_id !== (int) $bill->vendor_id) {
                self::fail('Vendor advance and purchase bill must belong to the same vendor.');
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::vendorPaymentAvailable((int) $payment->id)) {
                self::fail('Advance application exceeds the available vendor advance.');
            }
            if ($amount > self::vendorOutstanding((int) $bill->id)) {
                self::fail('Advance application exceeds the purchase bill balance.');
            }
            $id = DB::table('fabric_vendor_payment_allocations')->insertGetId([
                'vendor_payment_id' => $payment->id,
                'purchase_bill_id' => $bill->id,
                'amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::audit('fabric_vendor_payment_allocations', $id, 'advance_applied', ['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => $amount]);

            return $id;
        });
    }

    public static function customerCreditAvailable(int $creditId): int
    {
        $credit = DB::table('fabric_customer_credits')->where('id', $creditId)->firstOrFail();
        $allocated = (int) DB::table('fabric_credit_allocations')->where('credit_id', $creditId)->sum('amount');
        $refunded = (int) DB::table('fabric_customer_refunds')->where('credit_id', $creditId)->sum('amount');

        return max(0, (int) $credit->amount - $allocated - $refunded);
    }

    /** @param array<string,mixed> $data */
    public static function applyCustomerCredit(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $credit = DB::table('fabric_customer_credits')->where('id', (int) $data['credit_id'])->lockForUpdate()->firstOrFail();
            $sale = DB::table('fabric_sales')->where('id', (int) $data['sale_id'])->where('state', 'posted')->lockForUpdate()->firstOrFail();
            if (! $credit->customer_id || (int) $credit->customer_id !== (int) $sale->customer_id) {
                self::fail('Customer credit and sale must belong to the same identified customer.');
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::customerCreditAvailable((int) $credit->id)) {
                self::fail('Credit application exceeds the available customer credit.');
            }
            if ($amount > self::saleOutstanding((int) $sale->id)) {
                self::fail('Credit application exceeds the sale balance.');
            }
            $id = DB::table('fabric_credit_allocations')->insertGetId([
                'credit_id' => $credit->id, 'sale_id' => $sale->id, 'amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::audit('fabric_credit_allocations', $id, 'credit_applied', ['credit_id' => $credit->id, 'sale_id' => $sale->id, 'amount' => $amount]);

            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function refundCustomerCredit(array $data): int
    {
        $result = self::idempotent('customer-credit-refund', (string) $data['idempotency_key'], $data, function () use ($data) {
            $credit = DB::table('fabric_customer_credits')->where('id', (int) $data['credit_id'])->lockForUpdate()->firstOrFail();
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::customerCreditAvailable((int) $credit->id)) {
                self::fail('Refund exceeds the available customer credit.');
            }
            $effectiveAt = self::date($data['effective_at']);
            if ($effectiveAt->lt(self::date($credit->effective_at))) {
                self::fail('Refund cannot be dated before the customer credit.');
            }
            $accountId = (int) $data['account_id'];
            DB::table('fabric_accounts')->where('id', $accountId)->where('active', true)->firstOrFail();
            $id = DB::table('fabric_customer_refunds')->insertGetId([
                'reference' => self::reference('RF'), 'credit_id' => $credit->id, 'account_id' => $accountId,
                'amount' => $amount, 'effective_at' => $effectiveAt, 'idempotency_key' => (string) $data['idempotency_key'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::accountEntry($accountId, 'out', $amount, 'customer_refund', 'customer_refund', $id, $effectiveAt, $data['reason'] ?? 'Customer credit refund');
            self::audit('fabric_customer_refunds', $id, 'posted', ['credit_id' => $credit->id, 'amount' => $amount]);

            return ['type' => 'customer_refund', 'id' => $id];
        });

        return $result['id'];
    }

    public static function vendorCreditAvailable(int $creditId): int
    {
        $credit = DB::table('fabric_vendor_credits')->where('id', $creditId)->firstOrFail();
        $allocated = (int) DB::table('fabric_vendor_credit_allocations')->where('vendor_credit_id', $creditId)->sum('amount');
        $refunded = (int) DB::table('fabric_vendor_refunds')->where('vendor_credit_id', $creditId)->sum('amount');

        return max(0, (int) $credit->amount - $allocated - $refunded);
    }

    /** @param array<string,mixed> $data */
    public static function applyVendorCredit(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $credit = DB::table('fabric_vendor_credits')->where('id', (int) $data['credit_id'])->lockForUpdate()->firstOrFail();
            $bill = DB::table('fabric_purchase_bills')->where('id', (int) $data['purchase_bill_id'])->where('state', 'posted')->lockForUpdate()->firstOrFail();
            if ((int) $credit->vendor_id !== (int) $bill->vendor_id) {
                self::fail('Vendor credit and purchase bill must belong to the same vendor.');
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::vendorCreditAvailable((int) $credit->id)) {
                self::fail('Credit application exceeds the available vendor credit.');
            }
            if ($amount > self::vendorOutstanding((int) $bill->id)) {
                self::fail('Credit application exceeds the purchase bill balance.');
            }
            $id = DB::table('fabric_vendor_credit_allocations')->insertGetId([
                'vendor_credit_id' => $credit->id, 'purchase_bill_id' => $bill->id, 'amount' => $amount,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::audit('fabric_vendor_credit_allocations', $id, 'credit_applied', ['credit_id' => $credit->id, 'bill_id' => $bill->id, 'amount' => $amount]);

            return $id;
        });
    }

    /** @param array<string,mixed> $data */
    public static function refundVendorCredit(array $data): int
    {
        $result = self::idempotent('vendor-credit-refund', (string) $data['idempotency_key'], $data, function () use ($data) {
            $credit = DB::table('fabric_vendor_credits')->where('id', (int) $data['credit_id'])->lockForUpdate()->firstOrFail();
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0 || $amount > self::vendorCreditAvailable((int) $credit->id)) {
                self::fail('Refund exceeds the available vendor credit.');
            }
            $effectiveAt = self::date($data['effective_at']);
            if ($effectiveAt->lt(self::date($credit->effective_at))) {
                self::fail('Vendor refund cannot be dated before the vendor credit.');
            }
            $accountId = (int) $data['account_id'];
            DB::table('fabric_accounts')->where('id', $accountId)->where('active', true)->firstOrFail();
            $id = DB::table('fabric_vendor_refunds')->insertGetId([
                'reference' => self::reference('VF'), 'vendor_credit_id' => $credit->id, 'account_id' => $accountId,
                'amount' => $amount, 'effective_at' => $effectiveAt, 'idempotency_key' => (string) $data['idempotency_key'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::accountEntry($accountId, 'in', $amount, 'vendor_refund', 'vendor_refund', $id, $effectiveAt, $data['reason'] ?? 'Vendor credit refund');
            self::audit('fabric_vendor_refunds', $id, 'posted', ['credit_id' => $credit->id, 'amount' => $amount]);

            return ['type' => 'vendor_refund', 'id' => $id];
        });

        return $result['id'];
    }

    /** @param array<string,mixed> $data */
    public static function customerReturn(array $data): int
    {
        $result = self::idempotent('customer-return', (string) $data['idempotency_key'], $data, function () use ($data) {
            $sale = DB::table('fabric_sales')->lockForUpdate()->where('id', (int) $data['sale_id'])->firstOrFail();
            $effectiveAt = self::date($data['effective_at']);
            if ($effectiveAt->lt(Carbon::parse($sale->sale_at))) {
                self::fail('A return cannot be dated before the original sale.');
            }
            $resolution = (string) ($data['resolution'] ?? 'credit');
            if (! in_array($resolution, ['credit', 'refund', 'reduce_balance'], true)) {
                self::fail('Invalid return resolution.');
            }
            if (! $sale->customer_id && $resolution !== 'refund') {
                self::fail('Walk-in returns must be refunded; they cannot retain account credit.');
            }
            $returnId = DB::table('fabric_returns')->insertGetId([
                'reference' => self::reference('FR'),
                'sale_id' => $sale->id,
                'customer_id' => $sale->customer_id,
                'effective_at' => $effectiveAt,
                'credit_amount' => 0,
                'resolution' => $resolution,
                'reason' => trim((string) $data['reason']),
                'idempotency_key' => (string) $data['idempotency_key'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $creditTotal = 0;
            foreach (($data['lines'] ?? []) as $index => $row) {
                $line = DB::table('fabric_sale_lines')->where('sale_id', $sale->id)->lockForUpdate()->where('id', (int) $row['sale_line_id'])->firstOrFail();
                $product = DB::table('fabric_products')->where('id', $line->product_id)->firstOrFail();
                $qty = self::qty($row['quantity'], $product->unit);
                $remainingReturnable = (int) $line->quantity_milli - (int) $line->returned_qty_milli;
                if ($qty > $remainingReturnable) {
                    self::fail('Return quantity exceeds the quantity still eligible for return.');
                }
                $condition = (string) $row['condition'];
                if (! in_array($condition, ['resalable', 'damaged'], true)) {
                    self::fail('Return condition must be resalable or damaged.');
                }
                // Use cumulative rounding so multiple partial returns can never
                // over-credit (or under-credit) the frozen discounted line amount.
                $priorReturnedQty = (int) DB::table('fabric_return_lines')->where('sale_line_id', $line->id)->sum('quantity_milli');
                $priorCredit = (int) DB::table('fabric_return_lines')->where('sale_line_id', $line->id)->sum('credit_amount');
                $cumulativeReturned = $priorReturnedQty + $qty;
                $targetCredit = $cumulativeReturned >= (int) $line->quantity_milli
                    ? (int) $line->net
                    : Pricing::round(bcdiv(bcmul(Pricing::decimal($line->net), (string) $cumulativeReturned, 0), (string) $line->quantity_milli, 8));
                $credit = max(0, min((int) $line->net - $priorCredit, $targetCredit - $priorCredit));

                // COGS is restored only for resalable goods. Snapshot the exact
                // amount on the return line so period-profit reports do not repeat
                // proportional rounding independently.
                $costRestored = 0;
                if ($condition === 'resalable') {
                    $priorResalableQty = (int) DB::table('fabric_return_lines')->where('sale_line_id', $line->id)->where('condition', 'resalable')->sum('quantity_milli');
                    $priorCostRestored = (int) DB::table('fabric_return_lines')->where('sale_line_id', $line->id)->sum('cost_restored');
                    $cumulativeResalable = $priorResalableQty + $qty;
                    $targetCost = $cumulativeResalable >= (int) $line->quantity_milli
                        ? (int) $line->cost
                        : Pricing::round(bcdiv(bcmul(Pricing::decimal($line->cost), (string) $cumulativeResalable, 0), (string) $line->quantity_milli, 8));
                    $costRestored = max(0, min((int) $line->cost - $priorCostRestored, $targetCost - $priorCostRestored));
                }
                $sourceRoll = DB::table('fabric_rolls')->where('id', $line->roll_id)->firstOrFail();
                $newRollId = null;
                if ($qty > 0) {
                    $rollNo = self::reference('FR');
                    $newRollId = DB::table('fabric_rolls')->insertGetId([
                        'roll_number' => $rollNo,
                        'barcode' => DB::table('fabric_settings')->value('barcode_prefix').'-'.$rollNo,
                        'product_id' => $line->product_id,
                        'color_id' => $line->color_id,
                        'vendor_id' => $sourceRoll->vendor_id,
                        'goods_receipt_id' => null,
                        'purchase_line_id' => $sourceRoll->purchase_line_id,
                        'parent_roll_id' => $sourceRoll->id,
                        'original_qty_milli' => $qty,
                        'current_qty_milli' => $qty,
                        'reserved_qty_milli' => 0,
                        'unit_cost' => $sourceRoll->unit_cost,
                        'selling_price' => $sourceRoll->selling_price,
                        'condition' => $condition === 'resalable' ? 'remnant' : 'damaged',
                        'active' => true,
                        'version' => 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $newRoll = DB::table('fabric_rolls')->where('id', $newRollId)->firstOrFail();
                    self::stockMovement($newRoll, $condition === 'resalable' ? 'customer_return' : 'damaged_return', $qty, 'customer_return', $returnId, $effectiveAt, $data['reason'], (string) $index);
                }
                DB::table('fabric_return_lines')->insert([
                    'return_id' => $returnId,
                    'sale_line_id' => $line->id,
                    'quantity_milli' => $qty,
                    'condition' => $condition,
                    'credit_amount' => $credit,
                    'cost_restored' => $costRestored,
                    'new_roll_id' => $newRollId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fabric_sale_lines')->where('id', $line->id)->increment('returned_qty_milli', $qty, ['updated_at' => now()]);
                $creditTotal += $credit;
            }
            if (empty($data['lines'])) {
                self::fail('Add at least one return line.');
            }
            DB::table('fabric_returns')->where('id', $returnId)->update(['credit_amount' => $creditTotal, 'updated_at' => now()]);

            // A physically valid partial return can round to zero minor units. It
            // still belongs in stock/history, but it must not create a zero-value
            // credit/refund ledger entry.
            if ($creditTotal > 0) {
                $creditId = DB::table('fabric_customer_credits')->insertGetId([
                    'reference' => self::reference('CR'),
                    'customer_id' => $sale->customer_id,
                    'return_id' => $returnId,
                    'amount' => $creditTotal,
                    'effective_at' => $effectiveAt,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($resolution === 'reduce_balance') {
                    $apply = min($creditTotal, self::saleOutstanding((int) $sale->id));
                    if ($apply > 0) {
                        DB::table('fabric_credit_allocations')->insert([
                            'credit_id' => $creditId, 'sale_id' => $sale->id, 'amount' => $apply,
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                } elseif ($resolution === 'refund') {
                    $accountId = (int) ($data['account_id'] ?? 0);
                    if (! $accountId) {
                        self::fail('Choose the cash or bank account used for the refund.');
                    }
                    $refundId = DB::table('fabric_customer_refunds')->insertGetId([
                        'reference' => self::reference('RF'),
                        'credit_id' => $creditId,
                        'account_id' => $accountId,
                        'amount' => $creditTotal,
                        'effective_at' => $effectiveAt,
                        'idempotency_key' => (string) $data['idempotency_key'].'-refund',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    self::accountEntry($accountId, 'out', $creditTotal, 'customer_refund', 'customer_refund', $refundId, $effectiveAt, $data['reason']);
                }
            }
            self::audit('fabric_returns', $returnId, 'posted', ['credit' => $creditTotal, 'resolution' => $resolution]);

            return ['type' => 'customer_return', 'id' => $returnId];
        });

        return $result['id'];
    }

    /** @param array<string,mixed> $data */
    public static function vendorReturn(array $data): int
    {
        $result = self::idempotent('vendor-return', (string) $data['idempotency_key'], $data, function () use ($data) {
            $vendorId = (int) $data['vendor_id'];
            $bill = DB::table('fabric_purchase_bills')->where('vendor_id', $vendorId)->where('state', 'posted')->lockForUpdate()->where('id', (int) $data['purchase_bill_id'])->firstOrFail();
            $effectiveAt = self::date($data['effective_at']);
            if ($effectiveAt->lt(Carbon::parse($bill->bill_date))) {
                self::fail('Vendor return cannot be dated before the purchase bill.');
            }
            $returnId = DB::table('fabric_vendor_returns')->insertGetId([
                'reference' => self::reference('VR'),
                'vendor_id' => $vendorId,
                'purchase_bill_id' => $bill->id,
                'effective_at' => $effectiveAt,
                'credit_amount' => 0,
                'reason' => trim((string) $data['reason']),
                'idempotency_key' => (string) $data['idempotency_key'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $creditTotal = 0;
            foreach (($data['lines'] ?? []) as $index => $row) {
                $roll = DB::table('fabric_rolls')->lockForUpdate()->where('id', (int) $row['roll_id'])->firstOrFail();
                if ((int) $roll->vendor_id !== $vendorId || ! $roll->purchase_line_id) {
                    self::fail('The selected roll did not come from this vendor purchase.');
                }
                $purchaseLine = DB::table('fabric_purchase_lines')->where('purchase_bill_id', $bill->id)->where('id', $roll->purchase_line_id)->first();
                if (! $purchaseLine) {
                    self::fail('The selected roll does not belong to this purchase bill.');
                }
                if ($roll->goods_receipt_id) {
                    $receipt = DB::table('fabric_goods_receipts')->where('id', $roll->goods_receipt_id)->firstOrFail();
                    if ($effectiveAt->lt(self::date($receipt->received_at))) {
                        self::fail('Vendor return cannot be dated before the goods receipt.');
                    }
                }
                $product = DB::table('fabric_products')->where('id', $roll->product_id)->firstOrFail();
                $qty = self::qty($row['quantity'], $product->unit);
                if ($qty > ((int) $roll->current_qty_milli - (int) $roll->reserved_qty_milli)) {
                    self::fail('Vendor return exceeds physically available stock.');
                }
                $credit = self::amountForQty((int) $roll->unit_cost, $qty);
                DB::table('fabric_vendor_return_lines')->insert([
                    'vendor_return_id' => $returnId,
                    'roll_id' => $roll->id,
                    'purchase_line_id' => $purchaseLine->id,
                    'quantity_milli' => $qty,
                    'credit_amount' => $credit,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('fabric_rolls')->where('id', $roll->id)->decrement('current_qty_milli', $qty, [
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
                self::stockMovement($roll, 'vendor_return', -$qty, 'vendor_return', $returnId, $effectiveAt, $data['reason'], (string) $index);
                $creditTotal += $credit;
            }
            if ($creditTotal <= 0) {
                self::fail('Add at least one vendor return line.');
            }
            DB::table('fabric_vendor_returns')->where('id', $returnId)->update(['credit_amount' => $creditTotal, 'updated_at' => now()]);
            $creditId = DB::table('fabric_vendor_credits')->insertGetId([
                'reference' => self::reference('VF'),
                'vendor_id' => $vendorId,
                'vendor_return_id' => $returnId,
                'amount' => $creditTotal,
                'effective_at' => $effectiveAt,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $apply = min($creditTotal, self::vendorOutstanding((int) $bill->id));
            if ($apply > 0) {
                DB::table('fabric_vendor_credit_allocations')->insert([
                    'vendor_credit_id' => $creditId,
                    'purchase_bill_id' => $bill->id,
                    'amount' => $apply,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            self::audit('fabric_vendor_returns', $returnId, 'posted', ['credit' => $creditTotal]);

            return ['type' => 'vendor_return', 'id' => $returnId];
        });

        return $result['id'];
    }

    /** @param array<string,mixed> $data */
    public static function expense(array $data): int
    {
        $result = self::idempotent('expense', (string) $data['idempotency_key'], $data, function () use ($data) {
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0) { self::fail('Expense amount must be greater than zero.', 'amount'); }
            $effectiveAt = self::date($data['effective_at']);
            $id = DB::table('fabric_expenses')->insertGetId([
                'reference' => self::reference('EX'),
                'category' => trim((string) $data['category']),
                'amount' => $amount,
                'account_id' => (int) $data['account_id'],
                'effective_at' => $effectiveAt,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'receipt_path' => $data['receipt_path'] ?? null,
                'idempotency_key' => (string) $data['idempotency_key'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::accountEntry((int) $data['account_id'], 'out', $amount, 'expense', 'expense', $id, $effectiveAt, $data['note'] ?? null);
            self::audit('fabric_expenses', $id, 'posted');

            return ['type' => 'expense', 'id' => $id];
        });

        return $result['id'];
    }

    /** @param array<string,mixed> $data */
    public static function transfer(array $data): int
    {
        $result = self::idempotent('transfer', (string) $data['idempotency_key'], $data, function () use ($data) {
            $from = (int) $data['from_account_id'];
            $to = (int) $data['to_account_id'];
            if ($from === $to) {
                self::fail('Transfer accounts must be different.');
            }
            $amount = Pricing::minor($data['amount']);
            if ($amount <= 0) { self::fail('Transfer amount must be greater than zero.', 'amount'); }
            $effectiveAt = self::date($data['effective_at']);
            $id = DB::table('fabric_transfers')->insertGetId([
                'reference' => self::reference('TR'),
                'from_account_id' => $from,
                'to_account_id' => $to,
                'amount' => $amount,
                'effective_at' => $effectiveAt,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'idempotency_key' => (string) $data['idempotency_key'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            self::accountEntry($from, 'out', $amount, 'transfer_out', 'transfer', $id, $effectiveAt, $data['note'] ?? null);
            self::accountEntry($to, 'in', $amount, 'transfer_in', 'transfer', $id, $effectiveAt, $data['note'] ?? null);
            self::audit('fabric_transfers', $id, 'posted');

            return ['type' => 'transfer', 'id' => $id];
        });

        return $result['id'];
    }

    public static function expireReservations(): int
    {
        $ids = DB::table('fabric_reservations')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id');
        foreach ($ids as $id) {
            self::releaseReservation((int) $id, 'expired');
        }

        return $ids->count();
    }

    /** @param array<string,mixed> $data */
    public static function reserve(array $data): int
    {
        if (! DB::table('fabric_settings')->value('reservations_enabled')) {
            self::fail('Enable reservations in Fabric Settings first.');
        }
        return DB::transaction(function () use ($data) {
            $roll = DB::table('fabric_rolls')->lockForUpdate()->where('id', (int) $data['roll_id'])->firstOrFail();
            if (! $roll->active || ! in_array($roll->condition, ['sellable', 'remnant'], true)) {
                self::fail('Only active sellable/remnant rolls can be reserved.', 'roll_id');
            }
            $product = DB::table('fabric_products')->where('active', true)->where('id', $roll->product_id)->firstOrFail();
            $customerId = ! empty($data['customer_id']) ? (int) $data['customer_id'] : null;
            if ($customerId) {
                DB::table('customers')->where('active', true)->where('id', $customerId)->firstOrFail();
            }
            $qty = self::qty($data['quantity'], $product->unit);
            if ($qty > ((int) $roll->current_qty_milli - (int) $roll->reserved_qty_milli)) {
                self::fail('Reservation exceeds available stock.');
            }
            $id = DB::table('fabric_reservations')->insertGetId([
                'roll_id' => $roll->id,
                'customer_id' => $customerId,
                'quantity_milli' => $qty,
                'status' => 'active',
                'expires_at' => ! empty($data['expires_at']) ? self::date($data['expires_at']) : null,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('fabric_rolls')->where('id', $roll->id)->increment('reserved_qty_milli', $qty, ['updated_at' => now()]);
            self::stockMovement($roll, 'reservation', 0, 'reservation', $id, now(), 'Reserved '.$qty.' thousandths');
            self::audit('fabric_reservations', $id, 'created');

            return $id;
        });
    }

    public static function releaseReservation(int $id, string $status = 'released'): void
    {
        DB::transaction(function () use ($id, $status) {
            $reservation = DB::table('fabric_reservations')->lockForUpdate()->where('id', $id)->firstOrFail();
            if ($reservation->status !== 'active') {
                return;
            }
            $roll = DB::table('fabric_rolls')->lockForUpdate()->where('id', $reservation->roll_id)->firstOrFail();
            DB::table('fabric_rolls')->where('id', $roll->id)->update([
                'reserved_qty_milli' => max(0, (int) $roll->reserved_qty_milli - (int) $reservation->quantity_milli),
                'updated_at' => now(),
                'version' => DB::raw('version + 1'),
            ]);
            DB::table('fabric_reservations')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
            self::stockMovement($roll, 'reservation_release', 0, 'reservation', $id, now(), 'Reservation '.($status === 'expired' ? 'expired' : 'released'), 'release');
            self::audit('fabric_reservations', $id, $status);
        });
    }

    /** @param array<string,mixed> $data */
    public static function openingStock(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $product = DB::table('fabric_products')->where('active', true)->where('id', (int) $data['product_id'])->firstOrFail();
            DB::table('fabric_colors')->where('active', true)->where('id', (int) $data['color_id'])->firstOrFail();
            $vendorId = ! empty($data['vendor_id']) ? (int) $data['vendor_id'] : null;
            if ($vendorId) DB::table('fabric_vendors')->where('id', $vendorId)->firstOrFail();
            $qty = self::qty($data['quantity'], $product->unit);
            $unitCost = Pricing::minor($data['unit_cost'] ?? '0');
            $sellingPrice = Pricing::minor($data['selling_price'] ?? '0');
            if ($unitCost < 0 || $sellingPrice < 0) self::fail('Opening stock cost and selling price cannot be negative.');
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') self::fail('Explain the source of opening stock.', 'reason');
            $effectiveAt = self::date($data['effective_at']);
            $rollNumber = trim((string) ($data['roll_number'] ?? '')) ?: self::reference('OR');
            $barcode = trim((string) ($data['barcode'] ?? '')) ?: ((string) DB::table('fabric_settings')->where('id', 1)->value('barcode_prefix')).'-'.$rollNumber;
            self::validateStockBarcode($barcode);
            if (DB::table('fabric_rolls')->where('roll_number', $rollNumber)->exists()) self::fail('Roll number already exists.', 'roll_number');
            if (DB::table('fabric_rolls')->where('barcode', $barcode)->exists()) self::fail('Barcode already exists.', 'barcode');
            $rollId = DB::table('fabric_rolls')->insertGetId([
                'roll_number' => $rollNumber, 'barcode' => $barcode, 'product_id' => $product->id, 'color_id' => (int) $data['color_id'],
                'vendor_id' => $vendorId, 'goods_receipt_id' => null, 'purchase_line_id' => null, 'parent_roll_id' => null,
                'original_qty_milli' => $qty, 'current_qty_milli' => $qty, 'reserved_qty_milli' => 0,
                'unit_cost' => $unitCost, 'selling_price' => $sellingPrice, 'condition' => 'sellable', 'active' => true, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $roll = DB::table('fabric_rolls')->where('id', $rollId)->firstOrFail();
            self::stockMovement($roll, 'opening_stock', $qty, 'opening_stock', $rollId, $effectiveAt, $reason);
            self::audit('fabric_rolls', $rollId, 'opening_stock_recorded', ['quantity_milli' => $qty, 'unit_cost' => $unitCost, 'reason' => $reason]);
            return $rollId;
        });
    }

    /** @param array<string,mixed> $data */
    public static function adjustStock(array $data): void
    {
        DB::transaction(function () use ($data) {
            $roll = DB::table('fabric_rolls')->lockForUpdate()->where('id', (int) $data['roll_id'])->firstOrFail();
            $product = DB::table('fabric_products')->where('id', $roll->product_id)->firstOrFail();
            $target = self::qty($data['quantity'], $product->unit, true);
            if ($target < (int) $roll->reserved_qty_milli) {
                self::fail('Adjusted stock cannot be below the reserved quantity.');
            }
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                self::fail('A stock adjustment reason is required.');
            }
            $delta = $target - (int) $roll->current_qty_milli;
            DB::table('fabric_rolls')->where('id', $roll->id)->update([
                'current_qty_milli' => $target,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            self::stockMovement($roll, 'adjustment', $delta, 'roll', $roll->id, self::date($data['effective_at']), $reason, (string) Str::uuid());
            self::audit('fabric_rolls', $roll->id, 'stock_adjusted', ['delta_milli' => $delta, 'reason' => $reason]);
        });
    }

    /** @param array<string,mixed> $data */
    public static function physicalCount(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $countedAt = self::date($data['counted_at']);
            $id = DB::table('fabric_stock_counts')->insertGetId([
                'reference' => self::reference('SC'),
                'state' => 'draft',
                'counted_at' => $countedAt,
                'created_by' => auth()->id(),
                'posted_at' => null,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $seenRolls = [];
            foreach (($data['lines'] ?? []) as $row) {
                $rollId = (int) $row['roll_id'];
                if (isset($seenRolls[$rollId])) {
                    self::fail('Each roll can appear only once in a physical count.', 'lines');
                }
                $seenRolls[$rollId] = true;
                $roll = DB::table('fabric_rolls')->where('id', $rollId)->firstOrFail();
                $product = DB::table('fabric_products')->where('id', $roll->product_id)->firstOrFail();
                $counted = self::qty($row['quantity'], $product->unit, true);
                if ($counted !== (int) $roll->current_qty_milli && trim((string) ($row['reason'] ?? '')) === '') {
                    self::fail('Enter a reason for every stock-count difference.');
                }
                DB::table('fabric_stock_count_lines')->insert([
                    'stock_count_id' => $id,
                    'roll_id' => $roll->id,
                    'system_qty_milli' => $roll->current_qty_milli,
                    'counted_qty_milli' => $counted,
                    'reason' => trim((string) ($row['reason'] ?? '')) ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            self::audit('fabric_stock_counts', $id, 'created');

            return $id;
        });
    }

    public static function postPhysicalCount(int $id): void
    {
        DB::transaction(function () use ($id) {
            $count = DB::table('fabric_stock_counts')->lockForUpdate()->where('id', $id)->firstOrFail();
            if ($count->state === 'posted') {
                return;
            }
            $lines = DB::table('fabric_stock_count_lines')->where('stock_count_id', $id)->get();
            foreach ($lines as $line) {
                $roll = DB::table('fabric_rolls')->lockForUpdate()->where('id', $line->roll_id)->firstOrFail();
                if ((int) $roll->current_qty_milli !== (int) $line->system_qty_milli) {
                    self::fail('Stock changed after this count was recorded. Review and recount the affected roll before posting.');
                }
                if ((int) $line->counted_qty_milli < (int) $roll->reserved_qty_milli) {
                    self::fail('A counted quantity is below active reservations. Resolve reservations first.');
                }
                $delta = (int) $line->counted_qty_milli - (int) $roll->current_qty_milli;
                if ($delta !== 0) {
                    DB::table('fabric_rolls')->where('id', $roll->id)->update([
                        'current_qty_milli' => (int) $line->counted_qty_milli,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                    self::stockMovement($roll, 'physical_count', $delta, 'stock_count', $id, Carbon::parse($count->counted_at), $line->reason, (string) $line->id);
                }
            }
            DB::table('fabric_stock_counts')->where('id', $id)->update(['state' => 'posted', 'posted_at' => now(), 'updated_at' => now()]);
            self::audit('fabric_stock_counts', $id, 'posted');
        });
    }

    /** @param array<string,mixed> $data */
    public static function savePromotion(array $data, ?int $id = null): int
    {
        $percent = Pricing::decimal($data['percent']);
        if (! preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $percent) || bccomp($percent, '100', 4) > 0) {
            self::fail('Promotion percentage must be between 0 and 100.', 'percent');
        }
        $starts = self::date($data['starts_at']);
        $ends = self::date($data['ends_at']);
        if ($ends->lte($starts)) {
            self::fail('Promotion end must be after its start.');
        }
        return DB::transaction(function () use ($data, $id, $percent, $starts, $ends) {
            $existing = $id ? DB::table('fabric_promotions')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            $name = trim((string) $data['name']);
            if ($name === '') { self::fail('Promotion name is required.', 'name'); }
            $values = [
                'name' => $name,
                'percent' => $percent,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'priority' => (int) ($data['priority'] ?? 100),
                'stackable' => (bool) ($data['stackable'] ?? false),
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) ($existing->active ?? true),
                'updated_at' => now(),
            ];
            if ($id) {
                DB::table('fabric_promotions')->where('id', $id)->update([...$values, 'version' => DB::raw('version + 1')]);
                DB::table('fabric_promotion_targets')->where('promotion_id', $id)->delete();
            } else {
                $id = DB::table('fabric_promotions')->insertGetId([...$values, 'version' => 1, 'created_at' => now()]);
            }
            foreach (($data['targets'] ?? []) as $target) {
                if (! in_array($target['type'] ?? '', ['product', 'category', 'color'], true)) {
                    self::fail('Invalid promotion target.');
                }
                $targetTable = match ($target['type']) {
                    'product' => 'fabric_products',
                    'category' => 'fabric_categories',
                    'color' => 'fabric_colors',
                };
                DB::table($targetTable)->where('id', (int) $target['id'])->firstOrFail();
                DB::table('fabric_promotion_targets')->insert([
                    'promotion_id' => $id,
                    'target_type' => $target['type'],
                    'target_id' => (int) $target['id'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            self::audit('fabric_promotions', $id, 'saved');

            return $id;
        });
    }

    /** @return array{customer_outstanding:int,vendor_outstanding:int,customer_advances:int,vendor_advances:int,customer_credits:int,vendor_credits:int} */
    private static function accountTotalsAt(CarbonInterface $cutoff): array
    {
        $customerSales = (int) DB::table('fabric_sales')->where('state', 'posted')->where('sale_at', '<=', $cutoff)->sum('total');
        $customerImmediate = (int) DB::table('fabric_sales')->where('state', 'posted')->where('sale_at', '<=', $cutoff)->sum('paid');
        $customerLater = (int) DB::table('fabric_customer_receipt_allocations')
            ->join('fabric_customer_receipts', 'fabric_customer_receipts.id', '=', 'fabric_customer_receipt_allocations.customer_receipt_id')
            ->where('fabric_customer_receipts.effective_at', '<=', $cutoff)
            ->where('fabric_customer_receipt_allocations.created_at', '<=', $cutoff)
            ->sum('fabric_customer_receipt_allocations.amount');
        $customerCreditsApplied = (int) DB::table('fabric_credit_allocations')
            ->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_credit_allocations.credit_id')
            ->where('fabric_customer_credits.effective_at', '<=', $cutoff)
            ->where('fabric_credit_allocations.created_at', '<=', $cutoff)
            ->sum('fabric_credit_allocations.amount');
        $customerOpeningAdvanceApplied = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
            ->where('fabric_opening_balances.party_type', 'customer')->where('fabric_opening_balances.kind', 'advance')
            ->where('fabric_opening_balance_applications.target_type', 'sale')
            ->where('fabric_opening_balance_applications.effective_at', '<=', $cutoff)
            ->sum('fabric_opening_balance_applications.amount');
        $openingReceivable = (int) DB::table('fabric_opening_balances')->where('party_type', 'customer')->where('kind', 'receivable')->where('effective_at', '<=', $cutoff)->sum('amount');
        $openingReceivableApplied = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
            ->where('fabric_opening_balances.party_type', 'customer')->where('fabric_opening_balances.kind', 'receivable')
            ->where('fabric_opening_balance_applications.effective_at', '<=', $cutoff)
            ->sum('fabric_opening_balance_applications.amount');
        $customerOutstanding = max(0, $customerSales - $customerImmediate - $customerLater - $customerCreditsApplied - $customerOpeningAdvanceApplied + $openingReceivable - $openingReceivableApplied);

        $receipts = (int) DB::table('fabric_customer_receipts')->where('effective_at', '<=', $cutoff)->sum('amount');
        $receiptAllocated = (int) DB::table('fabric_customer_receipt_allocations')
            ->join('fabric_customer_receipts', 'fabric_customer_receipts.id', '=', 'fabric_customer_receipt_allocations.customer_receipt_id')
            ->where('fabric_customer_receipts.effective_at', '<=', $cutoff)->where('fabric_customer_receipt_allocations.created_at', '<=', $cutoff)
            ->sum('fabric_customer_receipt_allocations.amount');
        $receiptsToOpening = (int) DB::table('fabric_opening_balance_applications')
            ->where('target_type', 'customer_receipt')->where('effective_at', '<=', $cutoff)->sum('amount');
        $customerOpeningAdvance = (int) DB::table('fabric_opening_balances')->where('party_type', 'customer')->where('kind', 'advance')->where('effective_at', '<=', $cutoff)->sum('amount');
        $customerOpeningAdvanceUsed = (int) DB::table('fabric_opening_balance_applications')
            ->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
            ->where('fabric_opening_balances.party_type', 'customer')->where('fabric_opening_balances.kind', 'advance')
            ->where('fabric_opening_balance_applications.effective_at', '<=', $cutoff)->sum('fabric_opening_balance_applications.amount');
        $customerAdvances = max(0, $receipts - $receiptAllocated - $receiptsToOpening) + max(0, $customerOpeningAdvance - $customerOpeningAdvanceUsed);

        $customerCreditTotal = (int) DB::table('fabric_customer_credits')->where('effective_at', '<=', $cutoff)->sum('amount');
        $customerCreditAllocated = (int) DB::table('fabric_credit_allocations')->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_credit_allocations.credit_id')->where('fabric_customer_credits.effective_at', '<=', $cutoff)->where('fabric_credit_allocations.created_at', '<=', $cutoff)->sum('fabric_credit_allocations.amount');
        $customerCreditRefunded = (int) DB::table('fabric_customer_refunds')->where('effective_at', '<=', $cutoff)->sum('amount');

        $vendorBills = (int) DB::table('fabric_purchase_bills')->where('state', 'posted')->where('bill_date', '<=', $cutoff)->sum('total');
        $vendorAllocated = (int) DB::table('fabric_vendor_payment_allocations')->join('fabric_vendor_payments', 'fabric_vendor_payments.id', '=', 'fabric_vendor_payment_allocations.vendor_payment_id')->where('fabric_vendor_payments.effective_at', '<=', $cutoff)->where('fabric_vendor_payment_allocations.created_at', '<=', $cutoff)->sum('fabric_vendor_payment_allocations.amount');
        $vendorCreditApplied = (int) DB::table('fabric_vendor_credit_allocations')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_credit_allocations.vendor_credit_id')->where('fabric_vendor_credits.effective_at', '<=', $cutoff)->where('fabric_vendor_credit_allocations.created_at', '<=', $cutoff)->sum('fabric_vendor_credit_allocations.amount');
        $vendorOpeningAdvanceApplied = (int) DB::table('fabric_opening_balance_applications')->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')->where('fabric_opening_balances.party_type', 'vendor')->where('fabric_opening_balances.kind', 'advance')->where('fabric_opening_balance_applications.target_type', 'purchase_bill')->where('fabric_opening_balance_applications.effective_at', '<=', $cutoff)->sum('fabric_opening_balance_applications.amount');
        $openingPayable = (int) DB::table('fabric_opening_balances')->where('party_type', 'vendor')->where('kind', 'payable')->where('effective_at', '<=', $cutoff)->sum('amount');
        $openingPayableApplied = (int) DB::table('fabric_opening_balance_applications')->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')->where('fabric_opening_balances.party_type', 'vendor')->where('fabric_opening_balances.kind', 'payable')->where('fabric_opening_balance_applications.effective_at', '<=', $cutoff)->sum('fabric_opening_balance_applications.amount');
        $vendorOutstanding = max(0, $vendorBills - $vendorAllocated - $vendorCreditApplied - $vendorOpeningAdvanceApplied + $openingPayable - $openingPayableApplied);

        $vendorPayments = (int) DB::table('fabric_vendor_payments')->where('effective_at', '<=', $cutoff)->sum('amount');
        $vendorPaymentAllocated = (int) DB::table('fabric_vendor_payment_allocations')->join('fabric_vendor_payments', 'fabric_vendor_payments.id', '=', 'fabric_vendor_payment_allocations.vendor_payment_id')->where('fabric_vendor_payments.effective_at', '<=', $cutoff)->where('fabric_vendor_payment_allocations.created_at', '<=', $cutoff)->sum('fabric_vendor_payment_allocations.amount');
        $vendorPaymentsToOpening = (int) DB::table('fabric_opening_balance_applications')->where('target_type', 'vendor_payment')->where('effective_at', '<=', $cutoff)->sum('amount');
        $vendorOpeningAdvance = (int) DB::table('fabric_opening_balances')->where('party_type', 'vendor')->where('kind', 'advance')->where('effective_at', '<=', $cutoff)->sum('amount');
        $vendorOpeningAdvanceUsed = (int) DB::table('fabric_opening_balance_applications')->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')->where('fabric_opening_balances.party_type', 'vendor')->where('fabric_opening_balances.kind', 'advance')->where('fabric_opening_balance_applications.effective_at', '<=', $cutoff)->sum('fabric_opening_balance_applications.amount');
        $vendorAdvances = max(0, $vendorPayments - $vendorPaymentAllocated - $vendorPaymentsToOpening) + max(0, $vendorOpeningAdvance - $vendorOpeningAdvanceUsed);

        $vendorCreditTotal = (int) DB::table('fabric_vendor_credits')->where('effective_at', '<=', $cutoff)->sum('amount');
        $vendorCreditAllocated = (int) DB::table('fabric_vendor_credit_allocations')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_credit_allocations.vendor_credit_id')->where('fabric_vendor_credits.effective_at', '<=', $cutoff)->where('fabric_vendor_credit_allocations.created_at', '<=', $cutoff)->sum('fabric_vendor_credit_allocations.amount');
        $vendorCreditRefunded = (int) DB::table('fabric_vendor_refunds')->where('effective_at', '<=', $cutoff)->sum('amount');

        return [
            'customer_outstanding' => $customerOutstanding,
            'vendor_outstanding' => $vendorOutstanding,
            'customer_advances' => $customerAdvances,
            'vendor_advances' => $vendorAdvances,
            'customer_credits' => max(0, $customerCreditTotal - $customerCreditAllocated - $customerCreditRefunded),
            'vendor_credits' => max(0, $vendorCreditTotal - $vendorCreditAllocated - $vendorCreditRefunded),
        ];
    }

    /** @return array<string,int|array<int,array<string,mixed>>> */
    public static function dashboard(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= now()->startOfDay();
        $to ??= now()->endOfDay();
        $sales = DB::table('fabric_sales')->where('state', 'posted')->whereBetween('sale_at', [$from, $to]);
        $netSales = (int) (clone $sales)->sum('total') - (int) DB::table('fabric_returns')->whereBetween('effective_at', [$from, $to])->sum('credit_amount');
        $collections = (int) DB::table('fabric_account_entries')->where('direction', 'in')->whereIn('kind', ['sale_receipt', 'customer_receipt'])->whereBetween('effective_at', [$from, $to])->sum('amount');
        $purchases = (int) DB::table('fabric_purchase_bills')->where('state', 'posted')->whereBetween('bill_date', [$from, $to])->sum('total');
        $vendorPayments = (int) DB::table('fabric_vendor_payments')->whereBetween('effective_at', [$from, $to])->sum('amount');
        $expenses = (int) DB::table('fabric_expenses')->whereBetween('effective_at', [$from, $to])->sum('amount');
        $cogs = (int) DB::table('fabric_sale_lines')->join('fabric_sales', 'fabric_sales.id', '=', 'fabric_sale_lines.sale_id')->where('fabric_sales.state', 'posted')->whereBetween('fabric_sales.sale_at', [$from, $to])->sum('fabric_sale_lines.cost');
        // Return lines freeze their exact restored cost so repeated partial
        // returns cannot introduce period-report rounding drift.
        $restoredCogs = (int) DB::table('fabric_return_lines')
            ->join('fabric_returns', 'fabric_returns.id', '=', 'fabric_return_lines.return_id')
            ->where('fabric_return_lines.condition', 'resalable')
            ->whereBetween('fabric_returns.effective_at', [$from, $to])
            ->sum('fabric_return_lines.cost_restored');
        $cogs = max(0, $cogs - $restoredCogs);
        $stockValue = 0;
        foreach (DB::table('fabric_rolls')->where('active', true)->whereIn('condition', ['sellable', 'remnant'])->where('current_qty_milli', '>', 0)->get(['current_qty_milli', 'unit_cost']) as $roll) {
            $stockValue += self::amountForQty((int) $roll->unit_cost, (int) $roll->current_qty_milli);
        }
        $lowRollThreshold = (int) (DB::table('fabric_settings')->where('id', 1)->value('default_low_roll_milli') ?? 0);
        $lowStockRolls = DB::table('fabric_rolls')->where('active', true)->whereIn('condition', ['sellable', 'remnant'])
            ->whereRaw('(current_qty_milli - reserved_qty_milli) <= ?', [$lowRollThreshold])->count();
        $lowStockGroups = 0;
        $groupRows = DB::table('fabric_rolls')->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')
            ->where('fabric_rolls.active', true)->whereIn('fabric_rolls.condition', ['sellable', 'remnant'])
            ->groupBy('fabric_rolls.product_id', 'fabric_rolls.color_id', 'fabric_products.low_stock_milli')
            ->selectRaw('fabric_rolls.product_id, fabric_rolls.color_id, fabric_products.low_stock_milli, SUM(fabric_rolls.current_qty_milli - fabric_rolls.reserved_qty_milli) as available_milli')->get();
        foreach ($groupRows as $group) {
            if ((int) $group->available_milli <= (int) $group->low_stock_milli) { $lowStockGroups++; }
        }
        $businessTimezone = BusinessSetting::findOrFail(1)->timezone;
        $todayStart = Carbon::now($businessTimezone)->startOfDay()->utc();
        $todayEnd = Carbon::now($businessTimezone)->endOfDay()->utc();
        $upcomingEnd = Carbon::now($businessTimezone)->addDays(7)->endOfDay()->utc();
        $installmentBase = DB::table('fabric_vendor_installments')->where('status', 'due');
        $installmentsOverdue = (clone $installmentBase)->where('due_at', '<', $todayStart)->count();
        $installmentsToday = (clone $installmentBase)->whereBetween('due_at', [$todayStart, $todayEnd])->count();
        $installmentsUpcoming = (clone $installmentBase)->where('due_at', '>', $todayEnd)->where('due_at', '<=', $upcomingEnd)->count();
        $installmentsDue = $installmentsOverdue + $installmentsToday;
        $balances = self::accountTotalsAt($to);

        return [
            'net_sales' => $netSales,
            'collections' => $collections,
            'purchases' => $purchases,
            'vendor_payments' => $vendorPayments,
            'expenses' => $expenses,
            'cogs' => $cogs,
            'gross_profit' => $netSales - $cogs,
            'net_profit' => $netSales - $cogs - $expenses,
            'stock_value' => $stockValue,
            'low_stock' => $lowStockGroups + $lowStockRolls,
            'low_stock_products' => $lowStockGroups,
            'low_stock_rolls' => $lowStockRolls,
            'installments_due' => $installmentsDue,
            'installments_due_today' => $installmentsToday,
            'installments_overdue' => $installmentsOverdue,
            'installments_upcoming' => $installmentsUpcoming,
            'returns' => (int) DB::table('fabric_returns')->whereBetween('effective_at', [$from, $to])->sum('credit_amount'),
            'customer_refunds' => (int) DB::table('fabric_customer_refunds')->whereBetween('effective_at', [$from, $to])->sum('amount'),
            'vendor_returns' => (int) DB::table('fabric_vendor_returns')->whereBetween('effective_at', [$from, $to])->sum('credit_amount'),
            'vendor_refunds' => (int) DB::table('fabric_vendor_refunds')->whereBetween('effective_at', [$from, $to])->sum('amount'),
            ...$balances,
        ];
    }
}

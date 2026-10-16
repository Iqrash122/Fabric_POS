<?php

namespace App\Domain;

use App\Models\BusinessSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Shared display/export contract: business names and explicit money/quantity units. */
final class FabricReportPresenter
{
    /** @param array<int,object> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function rows(string $type, array $rows): array
    {
        $settings = BusinessSetting::findOrFail(1);
        $money = static fn ($value): string => number_format((int) $value / 100, 2, '.', '');
        $date = static fn ($value): string => $value ? Carbon::parse((string) $value, 'UTC')->setTimezone($settings->timezone)->format('Y-m-d H:i') : '';
        $label = static fn ($value): string => ucwords(str_replace('_', ' ', (string) $value));
        $accounts = DB::table('fabric_accounts')->pluck('name', 'id');
        $products = DB::table('fabric_products')->get()->keyBy('id');
        $colors = DB::table('fabric_colors')->pluck('name', 'id');
        $references = [];
        foreach (['sale' => 'fabric_sales', 'goods_receipt' => 'fabric_goods_receipts', 'customer_return' => 'fabric_returns', 'vendor_return' => 'fabric_vendor_returns', 'stock_count' => 'fabric_stock_counts', 'expense' => 'fabric_expenses', 'vendor_payment' => 'fabric_vendor_payments', 'customer_receipt' => 'fabric_customer_receipts'] as $kind => $table) {
            $references[$kind] = DB::table($table)->pluck('reference', 'id');
        }
        $currency = (string) $settings->currency;
        $moneyKeys = ['subtotal', 'additional_cost', 'amount', 'available', 'original_amount', 'outstanding', 'total', 'paid', 'balance', 'gross', 'discount', 'cost', 'net_sales', 'collections', 'purchases', 'vendor_payments', 'expenses', 'cogs', 'gross_profit', 'net_profit', 'stock_value', 'returns', 'customer_outstanding', 'vendor_outstanding', 'customer_advances', 'vendor_advances', 'customer_credits', 'vendor_credits', 'customer_refunds', 'vendor_returns', 'vendor_refunds'];
        return array_map(function ($row) use ($type, $money, $date, $label, $accounts, $products, $colors, $references, $currency, $moneyKeys): array {
            $r = get_object_vars($row);
            if ($type === 'sales') $r = ['reference' => $r['reference'], 'customer' => $r['customer_name'] ?: 'Walk-in', 'sale_at' => $r['sale_at'], 'state' => $r['state'], 'gross' => $r['gross'], 'discount' => $r['discount'], 'total' => $r['total'], 'paid' => $r['paid']];
            if ($type === 'purchases') $r = ['reference' => $r['reference'], 'vendor' => $r['vendor_name'], 'supplier_reference' => $r['supplier_reference'], 'bill_date' => $r['bill_date'], 'state' => $r['state'], 'subtotal' => $r['subtotal'], 'discount' => $r['discount'], 'additional_cost' => $r['additional_cost'], 'total' => $r['total']];
            if ($type === 'stock') {
                $unit = $r['unit'] === 'piece' ? 'pcs' : 'm';
                return ['Fabric' => $r['product_name'], 'Color' => $r['color_name'], 'Roll' => $r['roll_number'], 'Barcode' => $r['barcode'], 'Condition' => $label($r['condition']), 'Unit' => $unit, 'Received quantity' => number_format($r['original_qty_milli'] / 1000, 3, '.', ''), 'Current quantity' => number_format($r['current_qty_milli'] / 1000, 3, '.', ''), 'Reserved quantity' => number_format($r['reserved_qty_milli'] / 1000, 3, '.', ''), 'Available quantity' => number_format(max(0, $r['current_qty_milli'] - $r['reserved_qty_milli']) / 1000, 3, '.', ''), "Unit cost ($currency)" => $money($r['unit_cost']), "Stock value ($currency)" => number_format($r['unit_cost'] * $r['current_qty_milli'] / 100000, 2, '.', '')];
            }
            if ($type === 'movements') {
                $product = $products[$r['product_id']] ?? null;
                $unit = ($product->unit ?? 'meter') === 'piece' ? 'pcs' : 'm';
                return ['Date' => $date($r['effective_at']), 'Fabric' => $product->name ?? 'Unavailable product', 'Color' => $colors[$r['color_id']] ?? '', 'Roll' => $r['roll_number'], 'Movement' => $label($r['kind']), 'Unit' => $unit, 'Quantity change' => sprintf('%+.3f', $r['qty_delta_milli'] / 1000), 'Reference' => $references[$r['reference_type']][$r['reference_id']] ?? $label($r['reference_type']).' #'.$r['reference_id'], 'Reason' => $r['reason']];
            }
            if ($type === 'expenses') $r = ['reference' => $r['reference'], 'account' => $accounts[$r['account_id']] ?? 'Unavailable account', 'category' => $r['category'], 'amount' => $r['amount'], 'effective_at' => $r['effective_at'], 'note' => $r['note']];
            if ($type === 'cash') $r = ['effective_at' => $r['effective_at'], 'account' => $accounts[$r['account_id']] ?? 'Unavailable account', 'kind' => $label($r['kind']), 'direction' => $label($r['direction']), 'amount' => $r['amount'], 'reference' => $references[$r['reference_type']][$r['reference_id']] ?? $label($r['reference_type']).' #'.$r['reference_id'], 'notes' => $r['notes']];
            $result = [];
            foreach ($r as $key => $value) {
                if ($key === 'id' || str_ends_with($key, '_id') || in_array($key, ['idempotency_key', 'version', 'receipt_path', 'created_at', 'updated_at', 'posted_at'], true)) continue;
                $heading = $label($key);
                if (in_array($key, $moneyKeys, true)) { $heading .= " ($currency)"; $value = $money($value); }
                elseif (str_ends_with($key, '_at') || $key === 'bill_date') $value = $date($value);
                $result[$heading] = $value;
            }
            return $result;
        }, $rows);
    }
}

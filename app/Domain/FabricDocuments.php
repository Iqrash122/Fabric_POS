<?php

namespace App\Domain;

use App\Models\BusinessSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class FabricDocuments
{
    public static function barcodeLabel(int $id): Response
    {
        $roll = DB::table('fabric_rolls')
            ->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')
            ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id')
            ->where('fabric_rolls.id', $id)
            ->select('fabric_rolls.*', 'fabric_products.name as product_name', 'fabric_products.unit', 'fabric_colors.name as color_name')->firstOrFail();
        $settings = BusinessSetting::findOrFail(1);
        $labelSettings = DB::table('fabric_settings')->first();
        $width = max(30, min(150, (int) ($labelSettings->barcode_width_mm ?? 70)));
        $height = max(20, min(100, (int) ($labelSettings->barcode_height_mm ?? 40)));
        $barcode = 'data:image/svg+xml;base64,'.base64_encode(self::barcodeSvg((string) $roll->barcode));
        return response()->view('fabric.barcode-print', compact('roll', 'settings', 'width', 'height', 'barcode'));
    }

    public static function thermal(int $id): \Illuminate\Http\Response
    {
        $settings = BusinessSetting::findOrFail(1);
        $currency = $settings->currency;
        $fabricSettings = DB::table('fabric_settings')->where('id', 1)->first();
        $sale = DB::table('fabric_sales')->where('id', $id)->firstOrFail();
        $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first() : null;
        $walkInName = (string) ($fabricSettings->walk_in_name ?? 'Walk-in Customer');
        $contact = [
            'name'    => $customer->name ?? $walkInName,
            'phone'   => $customer->phone ?? '',
            'address' => $customer->address ?? '',
        ];
        $dbLines = DB::table('fabric_sale_lines')
            ->join('fabric_products', 'fabric_products.id', '=', 'fabric_sale_lines.product_id')
            ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_sale_lines.color_id')
            ->join('fabric_rolls', 'fabric_rolls.id', '=', 'fabric_sale_lines.roll_id')
            ->where('fabric_sale_lines.sale_id', $sale->id)
            ->select('fabric_sale_lines.*', 'fabric_products.name as current_product_name', 'fabric_products.unit as current_unit', 'fabric_colors.name as current_color_name', 'fabric_rolls.roll_number as current_roll_number')
            ->get();
        $lines = [];
        foreach ($dbLines as $line) {
            $productName = $line->product_name_snapshot ?: $line->current_product_name;
            $colorName   = $line->color_name_snapshot ?: $line->current_color_name;
            $rollNumber  = $line->roll_number_snapshot ?: $line->current_roll_number;
            $unit        = $line->unit_snapshot ?: $line->current_unit;
            $qtyMajor    = (int) $line->quantity_milli / 1000;
            $lines[] = [
                'name'         => $productName . ' / ' . $colorName,
                'roll'         => $rollNumber,
                'qty_fmt'      => $unit === 'piece' ? (int) $qtyMajor . ' pcs' : rtrim(rtrim(number_format($qtyMajor, 3, '.', ''), '0'), '.') . ' m',
                'rate_fmt'     => number_format((int) $line->unit_price / 100, 2, '.', ','),
                'discount'     => (int) $line->discount,
                'discount_fmt' => self::money((int) $line->discount, $currency),
                'net_fmt'      => number_format((int) $line->net / 100, 2, '.', ','),
            ];
        }
        $dbPayments = DB::table('fabric_sale_payments')
            ->join('fabric_accounts', 'fabric_accounts.id', '=', 'fabric_sale_payments.account_id')
            ->where('sale_id', $sale->id)
            ->get(['fabric_sale_payments.*', 'fabric_accounts.name as account_name']);
        $payments = $dbPayments->map(fn($p) => [
            'account'    => $p->account_name,
            'amount_fmt' => self::money((int) $p->amount, $currency),
        ])->all();
        $gross      = (int) $sale->gross;
        $total      = (int) $sale->total;
        $discount   = (int) $sale->discount;
        $paidAtSale = (int) $sale->paid;
        $balance    = FabricInventory::saleOutstanding($id);
        $tendered   = $sale->tendered;
        $change     = $tendered === null ? 0 : max(0, (int) $tendered - $paidAtSale);
        $balanceAtSale = (int) $sale->balance;
        $widthMm    = max(58, min(120, (int) ($fabricSettings->thermal_width_mm ?? 80)));
        $footerMsg  = (string) ($fabricSettings->receipt_footer ?? '') ?: (string) ($settings->invoice_footer ?? '');
        $logo       = $settings->logo && Storage::disk('local')->exists($settings->logo)
            ? 'data:' . Storage::disk('local')->mimeType($settings->logo) . ';base64,' . base64_encode(Storage::disk('local')->get($settings->logo))
            : null;
        $ref      = $sale->reference;
        $saleDate = self::date($sale->sale_at, $settings->timezone);
        $cashier  = $sale->cashier_name;
        $html = view('fabric.thermal', compact(
            'settings', 'contact', 'walkInName', 'lines', 'payments',
            'gross', 'total', 'discount', 'paidAtSale', 'balance', 'change',
            'widthMm', 'footerMsg', 'logo', 'ref', 'saleDate', 'cashier', 'currency', 'tendered', 'balanceAtSale'
        ))->with([
            'grossFmt'    => self::money($gross, $currency),
            'totalFmt'    => self::money($total, $currency),
            'discountFmt' => self::money($discount, $currency),
            'paidFmt'     => self::money($paidAtSale, $currency),
            'balanceFmt'  => self::money($balance, $currency),
            'changeFmt'   => self::money($change, $currency),
            'tenderedFmt' => $tendered === null ? null : self::money((int) $tendered, $currency),
            'balanceAtSaleFmt' => self::money($balanceAtSale, $currency),
        ])->render();
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    public static function pdf(string $type, int $id): Response
    {
        $settings = BusinessSetting::findOrFail(1);
        $currency = $settings->currency;
        $fabricSettings = DB::table('fabric_settings')->where('id', 1)->first();
        $title = '';
        $ref = '';
        $contact = [];
        $rows = [];
        $sections = [];
        $summary = [];
        $notes = '';
        $paper = 'a4';
        $orientation = 'portrait';

        if (in_array($type, ['invoice', 'thermal'], true)) {
            $sale = DB::table('fabric_sales')->where('id', $id)->firstOrFail();
            $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first() : null;
            $title = $type === 'thermal' ? 'Fabric sale receipt' : ((string) ($fabricSettings->invoice_title ?? 'Fabric Sale Invoice'));
            $ref = $sale->reference;
            $contact = ['name' => $customer->name ?? DB::table('fabric_settings')->value('walk_in_name') ?? 'Walk-in Customer', 'phone' => $customer->phone ?? '', 'address' => $customer->address ?? ''];
            $lines = DB::table('fabric_sale_lines')
                ->join('fabric_products', 'fabric_products.id', '=', 'fabric_sale_lines.product_id')
                ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_sale_lines.color_id')
                ->join('fabric_rolls', 'fabric_rolls.id', '=', 'fabric_sale_lines.roll_id')
                ->where('fabric_sale_lines.sale_id', $sale->id)
                ->select('fabric_sale_lines.*', 'fabric_products.name as current_product_name', 'fabric_products.unit as current_unit', 'fabric_colors.name as current_color_name', 'fabric_rolls.roll_number as current_roll_number')
                ->get();
            foreach ($lines as $line) {
                $productName = $line->product_name_snapshot ?: $line->current_product_name;
                $colorName = $line->color_name_snapshot ?: $line->current_color_name;
                $rollNumber = $line->roll_number_snapshot ?: $line->current_roll_number;
                $unit = $line->unit_snapshot ?: $line->current_unit;
                $rows[] = [
                    'Type' => $line->line_type === 'piece' ? 'Piece-based product' : 'Fabric sale',
                    'Fabric / item' => $productName.' / '.$colorName,
                    'Roll' => $rollNumber,
                    'Quantity' => self::quantity((int) $line->quantity_milli, $unit),
                    'Rate' => self::money((int) $line->unit_price, $currency),
                    'Original' => self::money((int) $line->gross, $currency),
                    'Discount' => self::money((int) $line->discount, $currency),
                    'Final' => self::money((int) $line->net, $currency),
                ];
            }
            $payments = DB::table('fabric_sale_payments')->join('fabric_accounts', 'fabric_accounts.id', '=', 'fabric_sale_payments.account_id')->where('sale_id', $sale->id)->get(['fabric_sale_payments.*', 'fabric_accounts.name as account_name']);
            if ($payments->isNotEmpty()) {
                $sections[] = ['title' => 'Payments', 'rows' => $payments->map(fn ($p) => [
                    'Account' => $p->account_name,
                    'Amount' => self::money((int) $p->amount, $currency),
                    'Reference' => $p->external_reference ?: '—',
                    'Date' => self::date($p->effective_at, $settings->timezone),
                ])->all()];
            }
            $summary = [
                'Original amount' => self::money((int) $sale->gross, $currency),
                'Discount' => self::money((int) $sale->discount, $currency),
                'Final amount' => self::money((int) $sale->total, $currency),
                'Paid at sale' => self::money((int) $sale->paid, $currency),
                'Current balance' => self::money(FabricInventory::saleOutstanding((int) $sale->id), $currency),
                'Sale date' => self::date($sale->sale_at, $settings->timezone),
            ];
            // thermal is now handled by the dedicated thermal() method; this branch is not reached
        } elseif ($type === 'customer-receipt') {
            $receipt = DB::table('fabric_customer_receipts')->where('id', $id)->firstOrFail();
            $customer = DB::table('customers')->where('id', $receipt->customer_id)->firstOrFail();
            $account = DB::table('fabric_accounts')->where('id', $receipt->account_id)->firstOrFail();
            $title = 'Fabric payment receipt';
            $ref = $receipt->reference;
            $contact = ['name' => $customer->name, 'phone' => $customer->phone, 'address' => $customer->address];
            $allocations = DB::table('fabric_customer_receipt_allocations')->join('fabric_sales', 'fabric_sales.id', '=', 'fabric_customer_receipt_allocations.sale_id')->where('customer_receipt_id', $id)->get(['fabric_sales.reference', 'fabric_customer_receipt_allocations.amount']);
            foreach ($allocations as $allocation) {
                $rows[] = ['Invoice' => $allocation->reference, 'Allocated' => self::money((int) $allocation->amount, $currency)];
            }
            $opening = DB::table('fabric_opening_balance_applications')->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')->where('target_type', 'customer_receipt')->where('target_id', $id)->get(['fabric_opening_balances.reason', 'fabric_opening_balance_applications.amount']);
            foreach ($opening as $allocation) {
                $rows[] = ['Invoice' => 'Opening receivable · '.$allocation->reason, 'Allocated' => self::money((int) $allocation->amount, $currency)];
            }
            $allocated = (int) DB::table('fabric_customer_receipt_allocations')->where('customer_receipt_id', $id)->sum('amount') + (int) DB::table('fabric_opening_balance_applications')->where('target_type', 'customer_receipt')->where('target_id', $id)->sum('amount');
            $summary = ['Received amount' => self::money((int) $receipt->amount, $currency), 'Allocated' => self::money($allocated, $currency), 'Unallocated advance' => self::money(max(0, (int) $receipt->amount - $allocated), $currency), 'Account' => $account->name, 'Date' => self::date($receipt->effective_at, $settings->timezone)];
        } elseif ($type === 'customer-statement') {
            $customer = DB::table('customers')->where('id', $id)->firstOrFail();
            $title = 'Fabric customer account statement';
            $ref = 'FAB-CUSTOMER-'.$id;
            $contact = ['name' => $customer->name, 'phone' => $customer->phone, 'address' => $customer->address];
            $events = [];
            foreach (DB::table('fabric_opening_balances')->where('party_type', 'customer')->where('party_id', $id)->get() as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Opening '.$x->kind, 'Reference' => 'OPEN-'.$x->id, 'Debit' => $x->kind === 'receivable' ? self::money((int) $x->amount, $currency) : '—', 'Credit' => $x->kind === 'advance' ? self::money((int) $x->amount, $currency) : '—']];
            foreach (DB::table('fabric_sales')->where('customer_id', $id)->get() as $x) $events[] = [$x->sale_at, ['Date' => self::date($x->sale_at, $settings->timezone), 'Event' => 'Sale', 'Reference' => $x->reference, 'Debit' => self::money((int) $x->total, $currency), 'Credit' => '—']];
            foreach (DB::table('fabric_customer_receipts')->where('customer_id', $id)->get() as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Receipt', 'Reference' => $x->reference, 'Debit' => '—', 'Credit' => self::money((int) $x->amount, $currency)]];
            foreach (DB::table('fabric_customer_credits')->where('customer_id', $id)->get() as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Return credit', 'Reference' => $x->reference, 'Debit' => '—', 'Credit' => self::money((int) $x->amount, $currency)]];
            foreach (DB::table('fabric_customer_refunds')->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_customer_refunds.credit_id')->where('fabric_customer_credits.customer_id', $id)->get(['fabric_customer_refunds.*']) as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Refund paid', 'Reference' => $x->reference, 'Debit' => self::money((int) $x->amount, $currency), 'Credit' => '—']];
            usort($events, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
            $rows = array_map(fn ($event) => $event[1], $events);
            $account = FabricInventory::customerSummary($id);
            $summary = ['Outstanding' => self::money($account['outstanding'], $currency), 'Unallocated advance' => self::money($account['advance'], $currency), 'Available return credit' => self::money($account['credit'], $currency)];
        } elseif ($type === 'vendor-statement') {
            $vendor = DB::table('fabric_vendors')->where('id', $id)->firstOrFail();
            $title = 'Vendor account statement';
            $ref = 'FAB-VENDOR-'.$id;
            $contact = ['name' => $vendor->shop_name.($vendor->owner_name ? ' · '.$vendor->owner_name : ''), 'phone' => $vendor->phone, 'address' => $vendor->address];
            $events = [];
            foreach (DB::table('fabric_opening_balances')->where('party_type', 'vendor')->where('party_id', $id)->get() as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Opening '.$x->kind, 'Reference' => 'OPEN-'.$x->id, 'Payable' => $x->kind === 'payable' ? self::money((int) $x->amount, $currency) : '—', 'Paid / credit' => $x->kind === 'advance' ? self::money((int) $x->amount, $currency) : '—']];
            foreach (DB::table('fabric_purchase_bills')->where('vendor_id', $id)->where('state', 'posted')->get() as $x) $events[] = [$x->bill_date, ['Date' => self::date($x->bill_date, $settings->timezone), 'Event' => 'Purchase bill', 'Reference' => $x->reference, 'Payable' => self::money((int) $x->total, $currency), 'Paid / credit' => '—']];
            foreach (DB::table('fabric_vendor_payments')->where('vendor_id', $id)->get() as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Payment', 'Reference' => $x->reference, 'Payable' => '—', 'Paid / credit' => self::money((int) $x->amount, $currency)]];
            foreach (DB::table('fabric_vendor_credits')->where('vendor_id', $id)->get() as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Return credit', 'Reference' => $x->reference, 'Payable' => '—', 'Paid / credit' => self::money((int) $x->amount, $currency)]];
            foreach (DB::table('fabric_vendor_refunds')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_refunds.vendor_credit_id')->where('fabric_vendor_credits.vendor_id', $id)->get(['fabric_vendor_refunds.*']) as $x) $events[] = [$x->effective_at, ['Date' => self::date($x->effective_at, $settings->timezone), 'Event' => 'Vendor refund received', 'Reference' => $x->reference, 'Payable' => '—', 'Paid / credit' => self::money((int) $x->amount, $currency)]];
            usort($events, fn ($a, $b) => strcmp((string) $a[0], (string) $b[0]));
            $rows = array_map(fn ($event) => $event[1], $events);
            $account = FabricInventory::vendorSummary($id);
            $summary = ['Payable' => self::money($account['payable'], $currency), 'Unallocated advance' => self::money($account['advance'], $currency), 'Available vendor credit' => self::money($account['credit'], $currency)];
        } elseif ($type === 'barcode') {
            $roll = DB::table('fabric_rolls')->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id')->where('fabric_rolls.id', $id)->select('fabric_rolls.*', 'fabric_products.name as product_name', 'fabric_products.unit', 'fabric_colors.name as color_name')->firstOrFail();
            $barcode = 'data:image/svg+xml;base64,'.base64_encode(self::barcodeSvg((string) $roll->barcode));
            $labelWidth = max(30, min(150, (int) ($fabricSettings->barcode_width_mm ?? 70))) * 2.83465;
            $labelHeight = max(20, min(100, (int) ($fabricSettings->barcode_height_mm ?? 40))) * 2.83465;
            return Pdf::loadView('fabric.label', compact('roll', 'barcode', 'settings'))->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->setPaper([0, 0, $labelWidth, $labelHeight])->download($roll->roll_number.'-barcode.pdf');
        } else {
            abort(404);
        }

        $document_footer = (string) ($fabricSettings->receipt_footer ?? '') ?: $settings->invoice_footer;
        $logo = $settings->logo && Storage::disk('local')->exists($settings->logo)
            ? 'data:'.Storage::disk('local')->mimeType($settings->logo).';base64,'.base64_encode(Storage::disk('local')->get($settings->logo))
            : null;
        $pdf = Pdf::loadView('fabric.document', compact('title', 'ref', 'settings', 'contact', 'rows', 'sections', 'summary', 'notes', 'logo', 'document_footer'))
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper($paper, $orientation);

        return $pdf->download($ref.'-'.$type.'.pdf');
    }

    public static function money(int $amount, string $currency): string
    {
        return $currency.' '.number_format($amount / 100, 2, '.', ',');
    }

    private static function quantity(int $milli, string $unit): string
    {
        return $unit === 'piece' ? (string) intdiv($milli, 1000).' pcs' : number_format($milli / 1000, 3, '.', '').' m';
    }

    private static function date(mixed $value, string $timezone): string
    {
        // Document dates come from UTC database timestamps, not shop-local form input.
        return \Illuminate\Support\Carbon::parse((string) $value, 'UTC')->timezone($timezone)->format('d M Y h:i A');
    }

    private static function safe(mixed $value): string
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        return preg_match('/^[\s]*[=+@\-\t\r]/u', $value) ? "'".$value : $value;
    }

    /** @param array<int, array<string|int, mixed>> $rows */
    public static function writeExport(array $rows, string $name, string $format): string
    {
        $headers = $rows ? array_keys($rows[0]) : ['No matching records'];
        $path = 'exports/'.Str::uuid().'.'.$format;
        Storage::disk('local')->makeDirectory('exports');
        $full = Storage::disk('local')->path($path);
        if ($format === 'csv') {
            $fp = fopen($full, 'w');
            if ($fp === false) throw new \RuntimeException('Cannot create export file.');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers, ',', '"', '');
            foreach ($rows as $row) fputcsv($fp, array_map(self::safe(...), array_values($row)), ',', '"', '');
            fclose($fp);
        } else {
            $zip = new \ZipArchive;
            $zip->open($full, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ([$headers, ...array_map('array_values', $rows)] as $row) {
                $xml .= '<row>';
                foreach ($row as $value) $xml .= '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars(self::safe($value), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
                $xml .= '</row>';
            }
            $xml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();
        }

        return $path;
    }

    /** Generate the exact stored value; never normalize a printed scan code. */
    public static function barcodeSvg(string $value): string
    {
        if ($value === '' || ! preg_match('/^[\x20-\x7E]+$/D', $value)) {
            FabricInventory::fail('This barcode contains unsupported characters. Use a printable ASCII barcode for a scannable label.', 'barcode');
        }
        $barcode = (new \Picqer\Barcode\Types\TypeCode128())->getBarcode($value);
        $width = $barcode->getWidth() * 2;
        $renderer = (new \Picqer\Barcode\Renderers\SvgRenderer())
            ->setSvgType(\Picqer\Barcode\Renderers\SvgRenderer::TYPE_SVG_INLINE)->setBackgroundColor([255, 255, 255]);
        $document = new \DOMDocument();
        $document->loadXML($renderer->render($barcode, $width, 64), LIBXML_NONET);
        $root = $document->documentElement;
        if (! $root) throw new \RuntimeException('Unable to render barcode.');
        // Ten narrow modules of white quiet zone on each side, including PDF output.
        $root->setAttribute('width', (string) ($width + 40));
        $root->setAttribute('viewBox', '-20 0 '.($width + 40).' 64');
        $background = $document->getElementsByTagName('rect')->item(0);
        $background?->setAttribute('x', '-20');
        $background?->setAttribute('width', (string) ($width + 40));
        return $document->saveXML($root) ?: throw new \RuntimeException('Unable to serialize barcode.');
    }
}

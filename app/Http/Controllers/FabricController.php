<?php

namespace App\Http\Controllers;

use App\Domain\FabricDocuments;
use App\Domain\FabricReportPresenter;
use App\Domain\FabricInventory;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FabricController extends Controller
{
    private const SECTIONS = [
        'dashboard', 'pos', 'customers', 'vendors', 'purchases', 'inventory', 'returns',
        'cash-bank', 'expenses', 'promotions', 'marketing', 'whatsapp-bulk', 'reports', 'settings',
    ];

    private const CASHIER_SECTIONS = ['dashboard', 'pos', 'customers', 'inventory'];

    private function role(Request $request): string
    {
        return $request->user()->is_owner ? 'owner' : (string) ($request->user()->fabric_role ?? '');
    }

    private function phone(?string $value): ?string
    {
        if (! $value) return null;
        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($value, BusinessSetting::findOrFail(1)->country);
            if (! $util->isPossibleNumber($number)) FabricInventory::fail('Phone number has an invalid length.', 'phone');
            return $util->format($number, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            FabricInventory::fail('Enter a valid phone number, including country code if overseas.', 'phone');
        }
    }


    private function installmentDisplayStatus(\stdClass $installment): string
    {
        if ((string) $installment->status === 'paid') return 'paid';
        $timezone = BusinessSetting::findOrFail(1)->timezone;
        $due = Carbon::parse((string) $installment->due_at)->setTimezone($timezone);
        $today = Carbon::now($timezone);
        if ($due->lt($today->copy()->startOfDay())) return 'overdue';
        if ($due->lte($today->copy()->endOfDay())) return 'due_today';
        return 'upcoming';
    }

    /** @return array<string,mixed> */
    private function common(Request $request): array
    {
        FabricInventory::expireReservations();
        $settings = BusinessSetting::findOrFail(1);
        $role = $this->role($request);
        $products = DB::table('fabric_products')
            ->leftJoin('fabric_categories', 'fabric_categories.id', '=', 'fabric_products.category_id')
            ->select('fabric_products.*', 'fabric_categories.name as category_name')
            ->orderBy('fabric_products.name')->get();
        $rollQuery = DB::table('fabric_rolls')
            ->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')
            ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id');
        if ($role === 'cashier') {
            $rollQuery->select(
                'fabric_rolls.id', 'fabric_rolls.roll_number', 'fabric_rolls.barcode',
                'fabric_rolls.product_id', 'fabric_rolls.color_id', 'fabric_rolls.original_qty_milli',
                'fabric_rolls.current_qty_milli', 'fabric_rolls.reserved_qty_milli',
                'fabric_rolls.selling_price', 'fabric_rolls.condition', 'fabric_rolls.active', 'fabric_rolls.version',
                'fabric_products.name as product_name', 'fabric_products.unit', 'fabric_colors.name as color_name'
            );
        } else {
            $rollQuery->leftJoin('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_rolls.vendor_id')
                ->select(
                    'fabric_rolls.*', 'fabric_products.name as product_name', 'fabric_products.unit',
                    'fabric_colors.name as color_name', 'fabric_vendors.shop_name as vendor_name'
                );
        }
        $rolls = $rollQuery->orderByDesc('fabric_rolls.id')->get()
            ->map(function ($roll) {
                $roll->available_qty_milli = max(0, (int) $roll->current_qty_milli - (int) $roll->reserved_qty_milli);
                return $roll;
            });

        return [
            'business' => $settings,
            'fabric_settings' => DB::table('fabric_settings')->where('id', 1)->first(),
            'role' => $role,
            'accounts' => $role === 'cashier'
                ? DB::table('fabric_accounts')->where('active', true)->orderBy('name')->get(['id', 'name', 'kind', 'active'])
                : DB::table('fabric_accounts')->orderBy('name')->get(),
            'categories' => DB::table('fabric_categories')->orderBy('name')->get(),
            'colors' => DB::table('fabric_colors')->orderBy('name')->get(),
            'products' => $products,
            'rolls' => $rolls,
            'vendors' => $role === 'cashier' ? collect() : DB::table('fabric_vendors')->orderBy('shop_name')->get(),
            'customers' => DB::table('customers')->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'active_promotions' => DB::table('fabric_promotions')
                ->where('active', true)
                ->where('starts_at', '<=', now())
                ->where('ends_at', '>=', now())
                ->orderBy('priority')
                ->get()
                ->map(function ($promotion) {
                    $promotion->targets = DB::table('fabric_promotion_targets')->where('promotion_id', $promotion->id)->get();
                    return $promotion;
                }),
            'success' => $request->session()->get('success'),
            'last_sale_id' => $request->session()->get('last_sale_id'),
        ];
    }

    public function index(Request $request, string $section = 'dashboard'): Response
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $role = $this->role($request);
        if ($role === 'cashier') {
            abort_unless(in_array($section, self::CASHIER_SECTIONS, true), 403);
        }
        if ($section === 'settings') {
            abort_unless($role === 'owner', 403);
        }
        $data = $this->common($request);
        $data['section'] = $section;
        $data['filters'] = $request->only(['q', 'period', 'from', 'to', 'report', 'status', 'vendor_id', 'customer_id', 'product_id']);

        if (in_array($section, ['vendors', 'customers'], true)) {
            $data['opening_balances'] = DB::table('fabric_opening_balances')
                ->where('party_type', $section === 'vendors' ? 'vendor' : 'customer')->orderByDesc('id')->get()
                ->map(function ($row) { $row->remaining = FabricInventory::openingRemaining((int) $row->id); return $row; });
        }

        if ($section === 'dashboard') {
            [$from, $to] = $this->period($request);
            $data['dashboard'] = FabricInventory::dashboard($from, $to);
            if ($role === 'cashier') {
                foreach (['purchases', 'vendor_payments', 'vendor_outstanding', 'vendor_advances', 'vendor_credits', 'vendor_returns', 'vendor_refunds', 'expenses', 'cogs', 'gross_profit', 'net_profit', 'stock_value', 'installments_due'] as $sensitiveMetric) {
                    unset($data['dashboard'][$sensitiveMetric]);
                }
            }
            $businessTimezone = (string) ($data['business']->timezone ?: 'UTC');
            $data['period'] = [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'from_date' => $from->copy()->setTimezone($businessTimezone)->toDateString(),
                'to_date' => $to->copy()->setTimezone($businessTimezone)->toDateString(),
            ];
            $data['recent_sales'] = DB::table('fabric_sales')->orderByDesc('id')->limit(8)->get()->map(function ($row) { $row->current_balance = FabricInventory::saleOutstanding((int) $row->id); return $row; });
            $data['due_installments'] = $role === 'cashier' ? collect() : DB::table('fabric_vendor_installments')
                ->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_installments.vendor_id')
                ->where('fabric_vendor_installments.status', 'due')
                ->where('fabric_vendor_installments.due_at', '<=', now()->addDays(7))
                ->select('fabric_vendor_installments.*', 'fabric_vendors.shop_name as vendor_name')
                ->orderBy('due_at')->limit(10)->get()
                ->map(function ($row) { $row->display_status = $this->installmentDisplayStatus($row); return $row; });
        } elseif ($section === 'pos') {
            $data['sales'] = $this->salesQuery($request)->limit(50)->get()->map(function ($row) { $row->current_balance = FabricInventory::saleOutstanding((int) $row->id); return $row; });
            $data['promotions'] = $data['active_promotions'];
        } elseif ($section === 'customers') {
            $customers = DB::table('customers')->where('active', true)->orderBy('name')->get(['id', 'name', 'phone', 'address']);
            foreach ($customers as $customer) {
                $customer->fabric = FabricInventory::customerSummary((int) $customer->id);
            }
            $data['customer_accounts'] = $customers;
            $data['receipts'] = DB::table('fabric_customer_receipts')->orderByDesc('id')->limit(50)->get();
            $data['customer_advances'] = DB::table('fabric_customer_receipts')->whereIn('customer_id', $customers->pluck('id'))->orderByDesc('id')->get()->map(function ($row) { $row->available = FabricInventory::customerReceiptAvailable((int) $row->id); return $row; })->filter(fn ($row) => $row->available > 0)->values();
            $data['customer_credits'] = DB::table('fabric_customer_credits')->orderByDesc('id')->limit(100)->get()->map(function ($row) { $row->available = FabricInventory::customerCreditAvailable((int) $row->id); return $row; })->filter(fn ($row) => $row->available > 0)->values();
            $data['customer_open_sales'] = DB::table('fabric_sales')->whereNotNull('customer_id')->where('state', 'posted')->orderByDesc('id')->limit(200)->get()->map(function ($row) { $row->outstanding = FabricInventory::saleOutstanding((int) $row->id); return $row; })->filter(fn ($row) => $row->outstanding > 0)->values();
        } elseif ($section === 'vendors') {
            $vendors = DB::table('fabric_vendors')->orderBy('shop_name')->get();
            foreach ($vendors as $vendor) {
                $vendor->account = FabricInventory::vendorSummary((int) $vendor->id);
            }
            $data['vendor_accounts'] = $vendors;
            $payments = DB::table('fabric_vendor_payments')
                ->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_payments.vendor_id')
                ->join('fabric_accounts', 'fabric_accounts.id', '=', 'fabric_vendor_payments.account_id')
                ->select('fabric_vendor_payments.*', 'fabric_vendors.shop_name as vendor_name', 'fabric_accounts.name as account_name')
                ->orderByDesc('fabric_vendor_payments.id')->get()->map(function ($row) {
                    $row->available = FabricInventory::vendorPaymentAvailable((int) $row->id);
                    $row->allocated = (int) $row->amount - $row->available;
                    return $row;
                });
            $data['vendor_payments'] = $payments->take(100)->values();
            $data['vendor_advances'] = $payments->filter(fn ($row) => $row->available > 0)->values();
            $data['vendor_credits'] = DB::table('fabric_vendor_credits')->orderByDesc('id')->limit(100)->get()->map(function ($row) { $row->available = FabricInventory::vendorCreditAvailable((int) $row->id); return $row; })->filter(fn ($row) => $row->available > 0)->values();
            $data['vendor_open_bills'] = DB::table('fabric_purchase_bills')->where('state', 'posted')->orderByDesc('id')->limit(200)->get()->map(function ($row) { $row->outstanding = FabricInventory::vendorOutstanding((int) $row->id); return $row; })->filter(fn ($row) => $row->outstanding > 0)->values();
            $data['installments'] = DB::table('fabric_vendor_installments')->orderBy('due_at')->limit(100)->get()
                ->map(function ($row) { $row->display_status = $this->installmentDisplayStatus($row); return $row; });
        } elseif ($section === 'purchases') {
            $data['purchase_bills'] = DB::table('fabric_purchase_bills')
                ->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_purchase_bills.vendor_id')
                ->select('fabric_purchase_bills.*', 'fabric_vendors.shop_name as vendor_name')
                ->orderByDesc('fabric_purchase_bills.id')->limit(100)->get()
                ->map(function ($bill) {
                    $bill->outstanding = $bill->state === 'posted' ? FabricInventory::vendorOutstanding((int) $bill->id) : 0;
                    $bill->lines = DB::table('fabric_purchase_lines')
                        ->join('fabric_products', 'fabric_products.id', '=', 'fabric_purchase_lines.product_id')
                        ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_purchase_lines.color_id')
                        ->where('purchase_bill_id', $bill->id)
                        ->select('fabric_purchase_lines.*')
                        ->selectRaw('COALESCE(fabric_purchase_lines.product_name_snapshot, fabric_products.name) as product_name')
                        ->selectRaw('COALESCE(fabric_purchase_lines.unit_snapshot, fabric_products.unit) as unit')
                        ->selectRaw('COALESCE(fabric_purchase_lines.color_name_snapshot, fabric_colors.name) as color_name')->get();
                    return $bill;
                });
            $data['receipts'] = DB::table('fabric_goods_receipts')->orderByDesc('id')->limit(50)->get();
        } elseif ($section === 'inventory') {
            $data['movements'] = DB::table('fabric_stock_movements')
                ->join('fabric_rolls', 'fabric_rolls.id', '=', 'fabric_stock_movements.roll_id')
                ->where(function ($q) use ($request) {
                    $term = trim((string) $request->input('q', ''));
                    if ($term !== '') {
                        $q->where('fabric_rolls.roll_number', 'like', "%{$term}%")
                            ->orWhere('fabric_rolls.barcode', 'like', "%{$term}%");
                    }
                })
                ->select('fabric_stock_movements.*', 'fabric_rolls.roll_number')
                ->orderByDesc('fabric_stock_movements.id')->limit(100)->get();
            $data['reservations'] = DB::table('fabric_reservations')->where('status', 'active')->orderBy('expires_at')->get();
            $data['stock_counts'] = DB::table('fabric_stock_counts')->orderByDesc('id')->limit(30)->get();
        } elseif ($section === 'returns') {
            $data['purchase_bills'] = DB::table('fabric_purchase_bills')->where('state', 'posted')->orderByDesc('id')->get();
            $data['return_rolls'] = DB::table('fabric_rolls')->join('fabric_purchase_lines', 'fabric_purchase_lines.id', '=', 'fabric_rolls.purchase_line_id')->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id')->where('fabric_rolls.current_qty_milli', '>', 0)->select('fabric_rolls.*', 'fabric_purchase_lines.purchase_bill_id', 'fabric_products.name as product_name', 'fabric_colors.name as color_name')->get();
            $data['customer_returns'] = DB::table('fabric_returns')->orderByDesc('id')->limit(100)->get();
            $data['vendor_returns'] = DB::table('fabric_vendor_returns')->orderByDesc('id')->limit(100)->get();
            $data['sale_lines'] = DB::table('fabric_sale_lines')
                ->join('fabric_sales', 'fabric_sales.id', '=', 'fabric_sale_lines.sale_id')
                ->join('fabric_products', 'fabric_products.id', '=', 'fabric_sale_lines.product_id')
                ->select('fabric_sale_lines.*', 'fabric_sales.reference as sale_reference', 'fabric_sales.customer_id', 'fabric_products.name as product_name')->orderByDesc('fabric_sale_lines.id')->limit(200)->get();
        } elseif ($section === 'cash-bank') {
            $accounts = DB::table('fabric_accounts')->orderBy('name')->get();
            foreach ($accounts as $account) {
                $incoming = (int) DB::table('fabric_account_entries')->where('account_id', $account->id)->where('direction', 'in')->sum('amount');
                $outgoing = (int) DB::table('fabric_account_entries')->where('account_id', $account->id)->where('direction', 'out')->sum('amount');
                $account->balance = (int) $account->opening_balance + $incoming - $outgoing;
            }
            $data['account_balances'] = $accounts;
            $data['account_entries'] = DB::table('fabric_account_entries')->orderByDesc('id')->limit(150)->get();
            $data['account_entry_rows'] = FabricReportPresenter::rows('cash', $data['account_entries']->all());
            $data['transfers'] = DB::table('fabric_transfers')->orderByDesc('id')->limit(50)->get();
        } elseif ($section === 'expenses') {
            $data['expenses'] = DB::table('fabric_expenses')->orderByDesc('effective_at')->limit(100)->get();
        } elseif ($section === 'promotions') {
            $data['promotions'] = DB::table('fabric_promotions')->orderBy('priority')->orderByDesc('id')->get()
                ->map(function ($promotion) {
                    $promotion->targets = DB::table('fabric_promotion_targets')->where('promotion_id', $promotion->id)->get();
                    return $promotion;
                });
        } elseif ($section === 'marketing') {
            $data['contacts'] = DB::table('fabric_marketing_contacts')->orderByDesc('id')->limit(200)->get();
            $data['campaigns'] = DB::table('fabric_marketing_campaigns')->orderByDesc('id')->limit(100)->get();
        } elseif ($section === 'whatsapp-bulk') {
            $data['whatsapp_driver'] = config('whatsapp.driver');
            $data['meta_whatsapp'] = app(\App\Services\MetaWhatsApp::class)->status();
            // Load all active customers with phone numbers for bulk WhatsApp messaging
            $data['customers'] = DB::table('customers')
                ->where('active', true)
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'normalized_phone', 'whatsapp', 'normalized_whatsapp']);
        } elseif ($section === 'reports') {

            $type = (string) $request->input('report', 'sales');
            $data['report_type'] = $type;
            $data['report_rows'] = $this->reportRows($type, $request);
            $data['report_display_rows'] = FabricReportPresenter::rows($type, $data['report_rows']);
        } elseif ($section === 'settings') {
            $data['opening_balances'] = DB::table('fabric_opening_balances')->orderByDesc('id')->limit(100)->get()
                ->map(function ($row) {
                    $row->remaining = FabricInventory::openingRemaining((int) $row->id);
                    return $row;
                });
            $data['staff'] = User::query()->whereNotNull('fabric_role')->orderBy('name')->get(['id', 'name', 'email', 'is_owner', 'fabric_role']);
            $data['audit'] = DB::table('audit_events')->where('entity', 'like', 'fabric_%')->orderByDesc('id')->limit(100)->get();
        }

        return Inertia::render('fabric/index', $data);
    }

    /** @return array{0:CarbonInterface,1:CarbonInterface} */
    private function period(Request $request): array
    {
        $tz = BusinessSetting::findOrFail(1)->timezone;
        $period = (string) $request->input('period', 'today');
        if ($period === 'week') {
            return [now($tz)->startOfWeek()->utc(), now($tz)->endOfWeek()->utc()];
        }
        if ($period === 'month') {
            return [now($tz)->startOfMonth()->utc(), now($tz)->endOfMonth()->utc()];
        }
        if ($period === 'custom' && $request->filled(['from', 'to'])) {
            return [Carbon::parse($request->string('from'), $tz)->startOfDay()->utc(), Carbon::parse($request->string('to'), $tz)->endOfDay()->utc()];
        }

        return [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()];
    }

    private function salesQuery(Request $request): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('fabric_sales')
            ->leftJoin('customers', 'customers.id', '=', 'fabric_sales.customer_id')
            ->select('fabric_sales.*', 'customers.name as customer_name')
            ->orderByDesc('fabric_sales.id');
        if ($request->filled('customer_id')) {
            $q->where('fabric_sales.customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('q')) {
            $term = trim((string) $request->input('q'));
            $q->where(function ($inner) use ($term) {
                $inner->where('fabric_sales.reference', 'like', "%{$term}%")
                    ->orWhere('customers.name', 'like', "%{$term}%");
            });
        }
        return $q;
    }

    public function action(Request $request, string $action, ?int $id = null): RedirectResponse
    {
        $role = $this->role($request);
        $manager = in_array($role, ['owner', 'manager'], true);
        $owner = $role === 'owner';

        switch ($action) {
            case 'customer-save':
                $data = $request->validate(['name' => 'required|string|max:180', 'phone' => 'required|string|max:40', 'address' => 'nullable|string|max:2000']);
                $normalized = $this->phone($data['phone']);
                $customer = new Customer();
                $customer->name = trim($data['name']);
                $customer->phone = $data['phone'];
                $customer->normalized_phone = $normalized;
                $customer->whatsapp = null;
                $customer->normalized_whatsapp = null;
                $customer->address = trim((string) ($data['address'] ?? '')) ?: null;
                $customer->notes = null;
                $customer->active = true;
                $customer->save();
                FabricInventory::audit('customers', (int) $customer->id, 'fabric_quick_customer_created');
                break;
            case 'vendor-save':
                abort_unless($manager, 403);
                $data = $request->validate(['shop_name' => 'required|string|max:160', 'owner_name' => 'nullable|string|max:160', 'phone' => 'nullable|string|max:80', 'email' => 'nullable|email|max:190', 'address' => 'nullable|string|max:2000', 'notes' => 'nullable|string|max:5000', 'active' => 'sometimes|boolean']);
                FabricInventory::saveVendor($data, $id);
                break;
            case 'category-save':
                abort_unless($manager, 403);
                $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('fabric_categories', 'name')->ignore($id)], 'active' => 'sometimes|boolean']);
                if ($id) {
                    $category = DB::table('fabric_categories')->where('id', $id)->firstOrFail();
                    $nextActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $category->active;
                    if ($category->active && ! $nextActive && DB::table('fabric_products')->where('category_id', $id)->where('active', true)->exists()) {
                        FabricInventory::fail('Archive or move active products before archiving this category.', 'active');
                    }
                    DB::table('fabric_categories')->where('id', $id)->update([...$data, 'active' => $nextActive, 'updated_at' => now()]);
                } else $id = DB::table('fabric_categories')->insertGetId([...$data, 'active' => $data['active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
                FabricInventory::audit('fabric_categories', (int) $id, 'saved');
                break;
            case 'color-save':
                abort_unless($manager, 403);
                $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('fabric_colors', 'name')->ignore($id)], 'hex' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'active' => 'sometimes|boolean']);
                if ($id) {
                    $color = DB::table('fabric_colors')->where('id', $id)->firstOrFail();
                    $nextActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $color->active;
                    if ($color->active && ! $nextActive) {
                        $onHand = (int) DB::table('fabric_rolls')->where('color_id', $id)->where('current_qty_milli', '>', 0)->sum('current_qty_milli');
                        $pendingReceipt = DB::table('fabric_purchase_lines')->join('fabric_purchase_bills', 'fabric_purchase_bills.id', '=', 'fabric_purchase_lines.purchase_bill_id')->where('fabric_purchase_lines.color_id', $id)->whereIn('fabric_purchase_bills.state', ['draft', 'posted'])->whereColumn('fabric_purchase_lines.received_qty_milli', '<', 'fabric_purchase_lines.ordered_qty_milli')->exists();
                        if ($onHand > 0 || $pendingReceipt) FabricInventory::fail('A color with stock on hand or quantity awaiting receipt cannot be archived.', 'active');
                    }
                    DB::table('fabric_colors')->where('id', $id)->update([...$data, 'active' => $nextActive, 'updated_at' => now()]);
                } else $id = DB::table('fabric_colors')->insertGetId([...$data, 'active' => $data['active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
                FabricInventory::audit('fabric_colors', (int) $id, 'saved');
                break;
            case 'product-save':
                abort_unless($manager, 403);
                $data = $request->validate([
                    'category_id' => 'required|integer', 'name' => 'required|string|max:160',
                    'sku' => ['required', 'string', 'max:80', Rule::unique('fabric_products', 'sku')->ignore($id)],
                    'barcode' => ['nullable', 'string', 'max:120', Rule::unique('fabric_products', 'barcode')->ignore($id)],
                    'unit' => 'required|in:meter,piece', 'default_selling_price' => 'required', 'low_stock' => 'required', 'active' => 'sometimes|boolean'
                ]);
                FabricInventory::saveProduct($data, $id);
                break;
            case 'account-save':
                abort_unless($owner, 403);
                $data = $request->validate(['name' => 'required|string|max:120', 'kind' => 'required|in:cash,bank', 'opening_balance' => 'required', 'active' => 'sometimes|boolean']);
                $id = DB::transaction(function () use ($id, $data): int {
                    $opening = \App\Domain\Pricing::minor($data['opening_balance']);
                    if ($id) {
                        $account = DB::table('fabric_accounts')->where('id', $id)->lockForUpdate()->firstOrFail();
                        $hasEntries = DB::table('fabric_account_entries')->where('account_id', $id)->exists();
                        if ($hasEntries && ((int) $account->opening_balance !== $opening || $account->kind !== $data['kind'])) {
                            FabricInventory::fail('Opening balance and account type cannot be changed after money movements exist. Use a traceable adjustment/transfer instead.', 'opening_balance');
                        }
                        $values = ['name' => $data['name'], 'kind' => $data['kind'], 'opening_balance' => $opening, 'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $account->active, 'updated_at' => now()];
                        if ($account->active && ! $values['active']) {
                            $incoming = (int) DB::table('fabric_account_entries')->where('account_id', $id)->where('direction', 'in')->sum('amount');
                            $outgoing = (int) DB::table('fabric_account_entries')->where('account_id', $id)->where('direction', 'out')->sum('amount');
                            $balance = (int) $account->opening_balance + $incoming - $outgoing;
                            if ($balance !== 0) FabricInventory::fail('Transfer or reconcile the account to zero before archiving it.', 'active');
                            if (DB::table('fabric_accounts')->where('active', true)->where('id', '<>', $id)->doesntExist()) FabricInventory::fail('At least one active cash or bank account is required.', 'active');
                        }
                        DB::table('fabric_accounts')->where('id', $id)->update([...$values, 'version' => DB::raw('version + 1')]);
                        return (int) $id;
                    }
                    $values = ['name' => $data['name'], 'kind' => $data['kind'], 'opening_balance' => $opening, 'active' => $data['active'] ?? true, 'updated_at' => now()];
                    return (int) DB::table('fabric_accounts')->insertGetId([...$values, 'version' => 1, 'created_at' => now()]);
                }, 3);
                FabricInventory::audit('fabric_accounts', (int) $id, 'saved');
                break;
            case 'opening-balance':
                abort_unless($owner, 403);
                FabricInventory::createOpeningBalance($request->validate(['party_type' => 'required|in:customer,vendor', 'party_id' => 'required|integer', 'kind' => 'required|in:receivable,payable,advance', 'amount' => 'required', 'effective_at' => 'required', 'reason' => 'required|string|max:2000', 'idempotency_key' => 'sometimes|required|string|max:120']));
                break;
            case 'advance-to-previous-debt':
                abort_unless($manager, 403);
                FabricInventory::applyAdvanceToPreviousDebt($request->validate([
                    'opening_balance_id' => 'required|integer', 'source_type' => 'required|in:vendor_payment,customer_receipt,opening_advance',
                    'source_id' => 'required|integer', 'amount' => 'required', 'idempotency_key' => 'required|string|max:120',
                ]));
                break;
            case 'opening-advance-apply':
                abort_unless($manager, 403);
                FabricInventory::applyOpeningAdvance($request->validate(['opening_balance_id' => 'required|integer', 'target_type' => 'required|in:sale,purchase_bill', 'target_id' => 'required|integer', 'amount' => 'required', 'effective_at' => 'required', 'reason' => 'nullable|string|max:2000']));
                break;
            case 'purchase-create':
                abort_unless($manager, 403);
                $data = $request->validate(['vendor_id' => 'required|integer', 'supplier_reference' => 'nullable|string|max:120', 'bill_date' => 'required', 'notes' => 'nullable|string|max:3000', 'lines' => 'required|array|min:1', 'lines.*.product_id' => 'required|integer', 'lines.*.color_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.purchase_rate' => 'required', 'lines.*.discount' => 'nullable', 'lines.*.additional_cost' => 'nullable']);
                FabricInventory::createPurchase($data);
                break;
            case 'purchase-update':
                abort_unless($manager, 403);
                abort_unless($id !== null, 404);
                $data = $request->validate(['vendor_id' => 'required|integer', 'supplier_reference' => 'nullable|string|max:120', 'bill_date' => 'required', 'notes' => 'nullable|string|max:3000', 'lines' => 'required|array|min:1', 'lines.*.product_id' => 'required|integer', 'lines.*.color_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.purchase_rate' => 'required', 'lines.*.discount' => 'nullable', 'lines.*.additional_cost' => 'nullable']);
                FabricInventory::updatePurchaseDraft((int) $id, $data);
                break;
            case 'purchase-post':
                abort_unless($manager, 403);
                FabricInventory::postPurchase((int) $id);
                break;
            case 'receipt-create':
                abort_unless($manager, 403);
                $data = $request->validate(['purchase_bill_id' => 'required|integer', 'vendor_id' => 'required|integer', 'received_at' => 'required', 'notes' => 'nullable|string|max:3000', 'lines' => 'required|array|min:1', 'lines.*.purchase_line_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.roll_number' => 'nullable|string|max:120', 'lines.*.barcode' => 'nullable|string|max:120', 'lines.*.selling_price' => 'nullable']);
                FabricInventory::receivePurchase($data);
                break;
            case 'vendor-payment':
                abort_unless($manager, 403);
                $data = $request->validate(['payment_mode' => 'sometimes|in:outstanding,bill,advance', 'purchase_bill_id' => 'required_if:payment_mode,bill|nullable|integer', 'vendor_id' => 'required|integer', 'account_id' => 'required|integer', 'amount' => 'required', 'effective_at' => 'required', 'idempotency_key' => 'required|string|max:120', 'notes' => 'nullable|string|max:2000', 'allocations' => 'sometimes|array', 'allocations.*.purchase_bill_id' => 'required_with:allocations|integer', 'allocations.*.amount' => 'required_with:allocations', 'opening_allocations' => 'sometimes|array', 'opening_allocations.*.opening_balance_id' => 'required_with:opening_allocations|integer', 'opening_allocations.*.amount' => 'required_with:opening_allocations']);
                FabricInventory::vendorPayment($data);
                break;
            case 'vendor-advance-apply':
                abort_unless($manager, 403);
                FabricInventory::applyVendorAdvance($request->validate(['payment_id' => 'required|integer', 'purchase_bill_id' => 'required|integer', 'amount' => 'required']));
                break;
            case 'vendor-credit-apply':
                abort_unless($manager, 403);
                FabricInventory::applyVendorCredit($request->validate(['credit_id' => 'required|integer', 'purchase_bill_id' => 'required|integer', 'amount' => 'required']));
                break;
            case 'vendor-credit-refund':
                abort_unless($manager, 403);
                FabricInventory::refundVendorCredit($request->validate(['credit_id' => 'required|integer', 'account_id' => 'required|integer', 'amount' => 'required', 'effective_at' => 'required', 'reason' => 'nullable|string|max:2000', 'idempotency_key' => 'required|string|max:120']));
                break;
            case 'installment-create':
                abort_unless($manager, 403);
                FabricInventory::createInstallments($request->validate(['vendor_id' => 'required|integer', 'purchase_bill_id' => 'nullable|integer', 'cadence' => 'required|in:daily,weekly,monthly', 'amount' => 'required', 'first_due_at' => 'required', 'count' => 'required|integer|min:1|max:120', 'notes' => 'nullable|string|max:2000']));
                break;
            case 'installment-settle':
                abort_unless($manager, 403);
                FabricInventory::settleInstallment((int) $id, (int) $request->validate(['payment_id' => 'required|integer'])['payment_id']);
                break;
            case 'sale':
                $data = $request->validate(['customer_id' => 'nullable|integer', 'sale_at' => 'required', 'quoted_total' => 'nullable|integer|min:0', 'credit_authorized' => 'sometimes|boolean', 'manual_discount_reason' => 'nullable|string|max:2000', 'idempotency_key' => 'required|string|max:120', 'lines' => 'required|array|min:1', 'lines.*.roll_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.unit_price' => 'nullable', 'lines.*.manual_discount' => 'nullable', 'payments' => 'sometimes|array', 'payments.*.account_id' => 'required_with:payments|integer', 'payments.*.amount' => 'required_with:payments', 'payments.*.external_reference' => 'nullable|string|max:120']);
                $saleId = FabricInventory::postSale($data, $role);
                return back()->with('success', 'Sale invoice posted successfully.')->with('last_sale_id', $saleId);
            case 'customer-receipt':
                $data = $request->validate(['collection_mode' => 'sometimes|in:outstanding,invoice,advance', 'sale_id' => 'required_if:collection_mode,invoice|nullable|integer', 'customer_id' => 'required|integer', 'account_id' => 'required|integer', 'amount' => 'required', 'effective_at' => 'required', 'idempotency_key' => 'required|string|max:120', 'notes' => 'nullable|string|max:2000', 'allocations' => 'sometimes|array', 'allocations.*.sale_id' => 'required_with:allocations|integer', 'allocations.*.amount' => 'required_with:allocations', 'opening_allocations' => 'sometimes|array', 'opening_allocations.*.opening_balance_id' => 'required_with:opening_allocations|integer', 'opening_allocations.*.amount' => 'required_with:opening_allocations']);
                FabricInventory::customerReceipt($data);
                break;
            case 'customer-advance-apply':
                FabricInventory::applyCustomerAdvance($request->validate(['receipt_id' => 'required|integer', 'sale_id' => 'required|integer', 'amount' => 'required']));
                break;
            case 'customer-credit-apply':
                FabricInventory::applyCustomerCredit($request->validate(['credit_id' => 'required|integer', 'sale_id' => 'required|integer', 'amount' => 'required']));
                break;
            case 'customer-credit-refund':
                abort_unless($manager, 403);
                FabricInventory::refundCustomerCredit($request->validate(['credit_id' => 'required|integer', 'account_id' => 'required|integer', 'amount' => 'required', 'effective_at' => 'required', 'reason' => 'nullable|string|max:2000', 'idempotency_key' => 'required|string|max:120']));
                break;
            case 'customer-return':
                abort_unless($manager, 403);
                $data = $request->validate(['sale_id' => 'required|integer', 'effective_at' => 'required', 'resolution' => 'required|in:credit,refund,reduce_balance', 'reason' => 'required|string|max:2000', 'account_id' => 'nullable|integer', 'idempotency_key' => 'required|string|max:120', 'lines' => 'required|array|min:1', 'lines.*.sale_line_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.condition' => 'required|in:resalable,damaged'], [], ['sale_id' => 'sale invoice', 'lines.*.sale_line_id' => 'sale item', 'lines.*.quantity' => 'return quantity', 'lines.*.condition' => 'return condition', 'account_id' => 'refund account']);
                FabricInventory::customerReturn($data);
                break;
            case 'vendor-return':
                abort_unless($manager, 403);
                $data = $request->validate(['vendor_id' => 'required|integer', 'purchase_bill_id' => 'required|integer', 'effective_at' => 'required', 'reason' => 'required|string|max:2000', 'idempotency_key' => 'required|string|max:120', 'lines' => 'required|array|min:1', 'lines.*.roll_id' => 'required|integer', 'lines.*.quantity' => 'required'], [], ['vendor_id' => 'vendor', 'purchase_bill_id' => 'purchase bill', 'lines.*.roll_id' => 'roll', 'lines.*.quantity' => 'return quantity']);
                FabricInventory::vendorReturn($data);
                break;
            case 'expense':
                abort_unless($manager, 403);
                $data = $request->validate(['category' => 'required|string|max:120', 'amount' => 'required', 'account_id' => 'required|integer', 'effective_at' => 'required', 'note' => 'nullable|string|max:3000', 'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', 'idempotency_key' => 'required|string|max:120']);
                if ($request->hasFile('receipt')) $data['receipt_path'] = $request->file('receipt')->store('fabric-expense-receipts', 'local');
                unset($data['receipt']);
                try {
                    FabricInventory::expense($data);
                } catch (\Throwable $e) {
                    if (! empty($data['receipt_path'])) Storage::disk('local')->delete($data['receipt_path']);
                    throw $e;
                }
                break;
            case 'transfer':
                abort_unless($manager, 403);
                FabricInventory::transfer($request->validate(['from_account_id' => 'required|integer', 'to_account_id' => 'required|integer|different:from_account_id', 'amount' => 'required', 'effective_at' => 'required', 'note' => 'nullable|string|max:2000', 'idempotency_key' => 'required|string|max:120']));
                break;
            case 'reserve':
                FabricInventory::reserve($request->validate(['roll_id' => 'required|integer', 'customer_id' => 'nullable|integer', 'quantity' => 'required', 'expires_at' => 'nullable', 'note' => 'nullable|string|max:2000']));
                break;
            case 'reservation-release':
                FabricInventory::releaseReservation((int) $id);
                break;
            case 'opening-stock':
                abort_unless($manager, 403);
                FabricInventory::openingStock($request->validate([
                    'product_id' => 'required|integer', 'color_id' => 'required|integer', 'vendor_id' => 'nullable|integer',
                    'quantity' => 'required', 'unit_cost' => 'required', 'selling_price' => 'required',
                    'roll_number' => 'nullable|string|max:120', 'barcode' => 'nullable|string|max:190',
                    'effective_at' => 'required', 'reason' => 'required|string|max:2000',
                ]));
                break;
            case 'stock-adjust':
                abort_unless($manager, 403);
                FabricInventory::adjustStock($request->validate(['roll_id' => 'required|integer', 'quantity' => 'required', 'effective_at' => 'required', 'reason' => 'required|string|max:2000']));
                break;
            case 'stock-count':
                abort_unless($manager, 403);
                FabricInventory::physicalCount($request->validate(['counted_at' => 'required', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1', 'lines.*.roll_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.reason' => 'nullable|string|max:2000']));
                break;
            case 'stock-count-post':
                abort_unless($manager, 403);
                FabricInventory::postPhysicalCount((int) $id);
                break;
            case 'promotion-save':
                abort_unless($manager, 403);
                FabricInventory::savePromotion($request->validate(['name' => 'required|string|max:160', 'percent' => 'required', 'starts_at' => 'required', 'ends_at' => 'required', 'priority' => 'required|integer', 'stackable' => 'sometimes|boolean', 'active' => 'sometimes|boolean', 'targets' => 'sometimes|array', 'targets.*.type' => 'required_with:targets|in:product,category,color', 'targets.*.id' => 'required_with:targets|integer']), $id);
                break;
            case 'vendor-toggle':
                abort_unless($manager, 403);
                $vendor = DB::table('fabric_vendors')->where('id', (int) $id)->firstOrFail();
                FabricInventory::saveVendor([
                    'shop_name' => $vendor->shop_name, 'owner_name' => $vendor->owner_name, 'phone' => $vendor->phone,
                    'email' => $vendor->email, 'address' => $vendor->address, 'notes' => $vendor->notes, 'active' => ! (bool) $vendor->active,
                ], (int) $id);
                FabricInventory::audit('fabric_vendors', (int) $id, $vendor->active ? 'archived' : 'reactivated');
                break;
            case 'category-toggle':
                abort_unless($manager, 403);
                $category = DB::table('fabric_categories')->where('id', (int) $id)->firstOrFail();
                if ($category->active && DB::table('fabric_products')->where('category_id', (int) $id)->where('active', true)->exists()) {
                    FabricInventory::fail('Archive or move active products before archiving this category.', 'active');
                }
                DB::table('fabric_categories')->where('id', (int) $id)->update(['active' => ! (bool) $category->active, 'updated_at' => now()]);
                FabricInventory::audit('fabric_categories', (int) $id, $category->active ? 'archived' : 'reactivated');
                break;
            case 'color-toggle':
                abort_unless($manager, 403);
                $color = DB::table('fabric_colors')->where('id', (int) $id)->firstOrFail();
                if ($color->active) {
                    $onHand = (int) DB::table('fabric_rolls')->where('color_id', (int) $id)->where('current_qty_milli', '>', 0)->sum('current_qty_milli');
                    $pendingReceipt = DB::table('fabric_purchase_lines')->join('fabric_purchase_bills', 'fabric_purchase_bills.id', '=', 'fabric_purchase_lines.purchase_bill_id')->where('fabric_purchase_lines.color_id', (int) $id)->whereIn('fabric_purchase_bills.state', ['draft', 'posted'])->whereColumn('fabric_purchase_lines.received_qty_milli', '<', 'fabric_purchase_lines.ordered_qty_milli')->exists();
                    if ($onHand > 0 || $pendingReceipt) FabricInventory::fail('A color with stock on hand or quantity awaiting receipt cannot be archived.', 'active');
                }
                DB::table('fabric_colors')->where('id', (int) $id)->update(['active' => ! (bool) $color->active, 'updated_at' => now()]);
                FabricInventory::audit('fabric_colors', (int) $id, $color->active ? 'archived' : 'reactivated');
                break;
            case 'promotion-toggle':
                abort_unless($manager, 403);
                $promotion = DB::table('fabric_promotions')->where('id', (int) $id)->firstOrFail();
                DB::table('fabric_promotions')->where('id', (int) $id)->update(['active' => ! (bool) $promotion->active, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                FabricInventory::audit('fabric_promotions', (int) $id, $promotion->active ? 'deactivated' : 'activated');
                break;
            case 'product-toggle':
                abort_unless($manager, 403);
                $product = DB::table('fabric_products')->where('id', (int) $id)->firstOrFail();
                if ($product->active) {
                    $onHand = (int) DB::table('fabric_rolls')->where('product_id', (int) $id)->where('current_qty_milli', '>', 0)->sum('current_qty_milli');
                    $pendingReceipt = DB::table('fabric_purchase_lines')->join('fabric_purchase_bills', 'fabric_purchase_bills.id', '=', 'fabric_purchase_lines.purchase_bill_id')->where('fabric_purchase_lines.product_id', (int) $id)->whereIn('fabric_purchase_bills.state', ['draft', 'posted'])->whereColumn('fabric_purchase_lines.received_qty_milli', '<', 'fabric_purchase_lines.ordered_qty_milli')->exists();
                    abort_if($onHand > 0 || $pendingReceipt, 422, 'A product with stock on hand or quantity awaiting receipt cannot be archived.');
                }
                DB::table('fabric_products')->where('id', (int) $id)->update(['active' => ! (bool) $product->active, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
                FabricInventory::audit('fabric_products', (int) $id, $product->active ? 'archived' : 'reactivated');
                break;
            case 'settings':
                abort_unless($owner, 403);
                $data = $request->validate([
                    'business_name' => 'required|string|max:160',
                    'currency' => 'required|string|size:3',
                    'timezone' => 'required|timezone',
                    'country' => 'required|string|size:2',
                    'business_phone' => 'nullable|string|max:60',
                    'business_address' => 'nullable|string|max:2000',
                    'reservations_enabled' => 'sometimes|boolean',
                    'credit_sales_enabled' => 'sometimes|boolean',
                    'barcode_prefix' => 'required|string|max:12',
                    'default_low_stock' => 'required',
                    'default_low_roll' => 'required',
                    'walk_in_name' => 'required|string|max:120',
                    'thermal_width_mm' => 'required|integer|min:58|max:120',
                    'barcode_width_mm' => 'required|integer|min:30|max:150',
                    'barcode_height_mm' => 'required|integer|min:20|max:100',
                    'invoice_title' => 'required|string|max:160',
                    'receipt_footer' => 'nullable|string|max:1000',
                ]);
                DB::transaction(function () use ($data) {
                    BusinessSetting::query()->whereKey(1)->update([
                        'name' => trim($data['business_name']),
                        'currency' => strtoupper($data['currency']),
                        'timezone' => $data['timezone'],
                        'country' => strtoupper($data['country']),
                        'phone' => trim((string) ($data['business_phone'] ?? '')) ?: null,
                        'address' => trim((string) ($data['business_address'] ?? '')) ?: null,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                    DB::table('fabric_settings')->where('id', 1)->update([
                        'reservations_enabled' => $data['reservations_enabled'] ?? false,
                        'credit_sales_enabled' => $data['credit_sales_enabled'] ?? false,
                        'barcode_prefix' => strtoupper($data['barcode_prefix']),
                        'default_low_stock_milli' => FabricInventory::qty($data['default_low_stock'], 'meter', true),
                        'default_low_roll_milli' => FabricInventory::qty($data['default_low_roll'], 'meter', true),
                        'walk_in_name' => $data['walk_in_name'],
                        'thermal_width_mm' => $data['thermal_width_mm'],
                        'barcode_width_mm' => $data['barcode_width_mm'],
                        'barcode_height_mm' => $data['barcode_height_mm'],
                        'invoice_title' => $data['invoice_title'],
                        'receipt_footer' => trim((string) ($data['receipt_footer'] ?? '')) ?: null,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                    FabricInventory::audit('business_settings', 1, 'updated');
                    FabricInventory::audit('fabric_settings', 1, 'updated');
                });
                break;
            case 'staff-save':
                abort_unless($owner, 403);
                $data = $request->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($id)], 'role' => 'required|in:manager,cashier', 'password' => $id ? 'nullable|string|min:12' : 'required|string|min:12']);
                $user = $id ? User::findOrFail($id) : new User();
                abort_if($user->is_owner, 422, 'Owner role is managed separately.');
                $user->name = $data['name']; $user->email = $data['email']; $user->fabric_role = $data['role']; $user->is_owner = false; $user->email_verified_at ??= now();
                if (! empty($data['password'])) $user->password = Hash::make($data['password']);
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                break;
            case 'marketing-import':
                abort_unless($manager, 403);
                $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
                $handle = fopen($request->file('file')->getRealPath(), 'rb');
                abort_if($handle === false, 422, 'Could not read the CSV file.');
                $header = fgetcsv($handle) ?: [];
                $keys = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
                $phoneIndex = array_search('phone', $keys, true);
                $nameIndex = array_search('name', $keys, true);
                if ($phoneIndex === false) { fclose($handle); FabricInventory::fail('CSV must contain a phone column.', 'file'); }
                while (($row = fgetcsv($handle)) !== false) {
                    $phone = trim((string) ($row[$phoneIndex] ?? ''));
                    if ($phone === '') continue;
                    $phone = (string) $this->phone($phone);
                    $name = $nameIndex !== false ? trim((string) ($row[$nameIndex] ?? '')) : '';
                    $existing = DB::table('fabric_marketing_contacts')->where('phone', $phone)->first();
                    if ($existing) {
                        // Import never revokes/grants existing consent; it may only fill an empty name.
                        if ($name !== '' && ! $existing->name) DB::table('fabric_marketing_contacts')->where('id', $existing->id)->update(['name' => $name, 'updated_at' => now()]);
                    } else {
                        DB::table('fabric_marketing_contacts')->insert(['phone' => $phone, 'name' => $name ?: null, 'whatsapp_consent' => false, 'whatsapp_consent_at' => null, 'whatsapp_consent_source' => null, 'sms_consent' => false, 'sms_consent_at' => null, 'sms_consent_source' => null, 'opted_out' => false, 'opted_out_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                fclose($handle);
                break;
            case 'marketing-optout':
                abort_unless($manager, 403);
                DB::table('fabric_marketing_contacts')->where('id', (int) $id)->update(['opted_out' => true, 'opted_out_at' => now(), 'updated_at' => now()]);
                break;
            case 'marketing-contact':
                abort_unless($manager, 403);
                $data = $request->validate(['phone' => 'required|string|max:80', 'name' => 'nullable|string|max:120', 'whatsapp_consent' => 'sometimes|boolean', 'whatsapp_consent_source' => 'nullable|string|max:200', 'sms_consent' => 'sometimes|boolean', 'sms_consent_source' => 'nullable|string|max:200', 'opted_out' => 'sometimes|boolean']);
                $data['phone'] = (string) $this->phone($data['phone']);
                $existing = DB::table('fabric_marketing_contacts')->where('phone', $data['phone'])->first();
                $values = ['name' => $data['name'] ?? null, 'updated_at' => now()];
                foreach (['whatsapp', 'sms'] as $channel) {
                    $key = $channel.'_consent';
                    if (array_key_exists($key, $data)) {
                        $values[$key] = (bool) $data[$key];
                        $values[$channel.'_consent_at'] = $data[$key] ? now() : null;
                        $values[$channel.'_consent_source'] = $data[$channel.'_consent_source'] ?? null;
                    } elseif (! $existing) {
                        $values[$key] = false;
                    }
                }
                if (array_key_exists('opted_out', $data)) { $values['opted_out'] = (bool) $data['opted_out']; $values['opted_out_at'] = $data['opted_out'] ? now() : null; }
                if ($existing) DB::table('fabric_marketing_contacts')->where('id', $existing->id)->update($values);
                else DB::table('fabric_marketing_contacts')->insert([...$values, 'phone' => $data['phone'], 'opted_out' => $values['opted_out'] ?? false, 'created_at' => now()]);
                break;
            case 'campaign-save':
                abort_unless($manager, 403);
                $data = $request->validate(['name' => 'required|string|max:160', 'channel' => 'required|in:whatsapp,sms', 'message' => 'required|string|max:5000', 'provider' => 'nullable|string|max:120']);
                DB::table('fabric_marketing_campaigns')->insert(['name' => $data['name'], 'channel' => $data['channel'], 'message' => $data['message'], 'state' => 'draft', 'provider' => $data['provider'] ?? null, 'approved_at' => null, 'launched_at' => null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                break;
            case 'campaign-approve':
                abort_unless($owner, 403);
                DB::transaction(function () use ($id) {
                    $campaign = DB::table('fabric_marketing_campaigns')->where('id', (int) $id)->lockForUpdate()->firstOrFail();
                    abort_unless($campaign->state === 'draft', 422, 'Only a draft campaign can be approved.');
                    $consentColumn = $campaign->channel === 'whatsapp' ? 'whatsapp_consent' : 'sms_consent';
                    $contacts = DB::table('fabric_marketing_contacts')->where($consentColumn, true)->where('opted_out', false)->pluck('id');
                    foreach ($contacts as $contactId) {
                        DB::table('fabric_campaign_recipients')->insertOrIgnore(['campaign_id' => $campaign->id, 'contact_id' => $contactId, 'state' => 'pending', 'provider_message_id' => null, 'error' => null, 'sent_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('fabric_marketing_campaigns')->where('id', $campaign->id)->update(['state' => 'approved', 'approved_at' => now(), 'updated_at' => now()]);
                });
                break;
            default:
                abort(404);
        }

        return back()->with('success', 'Fabric operation completed successfully.');
    }

    public function barcode(Request $request, string $code): JsonResponse
    {
        $code = trim($code);
        FabricInventory::expireReservations();
        $roll = DB::table('fabric_rolls')
            ->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')
            ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id')
            ->where(function ($q) use ($code) {
                $q->where('fabric_rolls.barcode', $code)
                    ->orWhere('fabric_rolls.roll_number', $code);
            })
            ->select(
                'fabric_rolls.id', 'fabric_rolls.roll_number', 'fabric_rolls.barcode',
                'fabric_rolls.product_id', 'fabric_rolls.color_id', 'fabric_rolls.current_qty_milli',
                'fabric_rolls.reserved_qty_milli', 'fabric_rolls.selling_price', 'fabric_rolls.condition', 'fabric_rolls.active',
                'fabric_products.name as product_name', 'fabric_products.unit', 'fabric_colors.name as color_name'
            )->first();
        if ($roll) {
            $roll->available_qty_milli = max(0, (int) $roll->current_qty_milli - (int) $roll->reserved_qty_milli);
            if (! $roll->active || ! in_array($roll->condition, ['sellable', 'remnant'], true) || $roll->available_qty_milli <= 0) {
                return response()->json(['message' => 'This roll is sold out, reserved, damaged or inactive. Choose an available roll from Inventory.'], 422);
            }
            return response()->json(['type' => 'roll', 'record' => $roll]);
        }
        $product = DB::table('fabric_products')
            ->where('barcode', $code)
            ->orWhere('sku', $code)
            ->first();
        $product ??= DB::table('fabric_products')->where('name', $code)->first();
        $product ??= DB::table('fabric_products')->where('name', 'like', "%{$code}%")->orderBy('id')->first();
        if ($product) {
            $available = DB::table('fabric_rolls')
                ->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')
                ->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id')
                ->where('fabric_rolls.product_id', $product->id)
                ->where('fabric_rolls.active', true)
                ->whereIn('fabric_rolls.condition', ['sellable', 'remnant'])
                ->select(
                    'fabric_rolls.id', 'fabric_rolls.roll_number', 'fabric_rolls.barcode',
                    'fabric_rolls.product_id', 'fabric_rolls.color_id', 'fabric_rolls.current_qty_milli',
                    'fabric_rolls.reserved_qty_milli', 'fabric_rolls.selling_price', 'fabric_rolls.condition', 'fabric_rolls.active',
                    'fabric_products.name as product_name', 'fabric_products.unit', 'fabric_colors.name as color_name'
                )
                ->get()
                ->map(fn ($r) => [...(array) $r, 'available_qty_milli' => max(0, (int) $r->current_qty_milli - (int) $r->reserved_qty_milli)])
                ->filter(fn ($r) => $r['available_qty_milli'] > 0)
                ->values();
            return response()->json([
                'type' => 'product',
                'record' => $product,
                'rolls' => $available,
                'has_rolls' => $available->isNotEmpty() || DB::table('fabric_rolls')->where('product_id', $product->id)->exists(),
            ]);
        }
        return response()->json(['message' => 'No matching roll, barcode, or product found.'], 404);
    }

    /** @return array<int,object> */
    private function customerAccountReport(Request $request, CarbonInterface $to): array
    {
        $salesQuery = DB::table('fabric_sales')
            ->join('customers', 'customers.id', '=', 'fabric_sales.customer_id')
            ->where('fabric_sales.state', 'posted')
            ->whereNotNull('fabric_sales.customer_id')
            ->where('fabric_sales.sale_at', '<=', $to)
            ->select('fabric_sales.id', 'fabric_sales.reference', 'fabric_sales.customer_id', 'fabric_sales.sale_at', 'fabric_sales.total', 'fabric_sales.paid', 'customers.name as customer_name');
        if ($request->filled('customer_id')) $salesQuery->where('fabric_sales.customer_id', (int) $request->input('customer_id'));
        $sales = $salesQuery->orderBy('customers.name')->orderBy('fabric_sales.sale_at')->get();
        $saleIds = $sales->pluck('id');
        $receiptAlloc = collect(); $creditAlloc = collect(); $openingAdvance = collect();
        if ($saleIds->isNotEmpty()) {
            $receiptAlloc = DB::table('fabric_customer_receipt_allocations')
                ->join('fabric_customer_receipts', 'fabric_customer_receipts.id', '=', 'fabric_customer_receipt_allocations.customer_receipt_id')
                ->whereIn('fabric_customer_receipt_allocations.sale_id', $saleIds)
                ->where('fabric_customer_receipts.effective_at', '<=', $to)
                ->where('fabric_customer_receipt_allocations.created_at', '<=', $to)
                ->groupBy('fabric_customer_receipt_allocations.sale_id')
                ->selectRaw('fabric_customer_receipt_allocations.sale_id, SUM(fabric_customer_receipt_allocations.amount) as amount')->pluck('amount', 'sale_id');
            $creditAlloc = DB::table('fabric_credit_allocations')
                ->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_credit_allocations.credit_id')
                ->whereIn('fabric_credit_allocations.sale_id', $saleIds)
                ->where('fabric_customer_credits.effective_at', '<=', $to)
                ->where('fabric_credit_allocations.created_at', '<=', $to)
                ->groupBy('fabric_credit_allocations.sale_id')
                ->selectRaw('fabric_credit_allocations.sale_id, SUM(fabric_credit_allocations.amount) as amount')->pluck('amount', 'sale_id');
            $openingAdvance = DB::table('fabric_opening_balance_applications')
                ->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
                ->where('fabric_opening_balances.party_type', 'customer')->where('fabric_opening_balances.kind', 'advance')
                ->where('fabric_opening_balance_applications.target_type', 'sale')
                ->whereIn('fabric_opening_balance_applications.target_id', $saleIds)
                ->where('fabric_opening_balance_applications.effective_at', '<=', $to)
                ->groupBy('fabric_opening_balance_applications.target_id')
                ->selectRaw('fabric_opening_balance_applications.target_id, SUM(fabric_opening_balance_applications.amount) as amount')->pluck('amount', 'target_id');
        }
        $rows = collect();
        foreach ($sales as $sale) {
            $outstanding = max(0, (int) $sale->total - (int) $sale->paid - (int) ($receiptAlloc[$sale->id] ?? 0) - (int) ($creditAlloc[$sale->id] ?? 0) - (int) ($openingAdvance[$sale->id] ?? 0));
            if ($outstanding > 0) $rows->push((object) ['source' => 'invoice', 'reference' => $sale->reference, 'customer' => $sale->customer_name, 'customer_id' => $sale->customer_id, 'effective_at' => $sale->sale_at, 'original_amount' => (int) $sale->total, 'outstanding' => $outstanding]);
        }
        $openingQuery = DB::table('fabric_opening_balances')->join('customers', 'customers.id', '=', 'fabric_opening_balances.party_id')
            ->where('fabric_opening_balances.party_type', 'customer')->where('fabric_opening_balances.kind', 'receivable')->where('fabric_opening_balances.effective_at', '<=', $to)
            ->select('fabric_opening_balances.*', 'customers.name as customer_name');
        if ($request->filled('customer_id')) $openingQuery->where('fabric_opening_balances.party_id', (int) $request->input('customer_id'));
        $openings = $openingQuery->get();
        $openingIds = $openings->pluck('id');
        $applied = $openingIds->isEmpty() ? collect() : DB::table('fabric_opening_balance_applications')->whereIn('opening_balance_id', $openingIds)->where('effective_at', '<=', $to)->groupBy('opening_balance_id')->selectRaw('opening_balance_id, SUM(amount) as amount')->pluck('amount', 'opening_balance_id');
        foreach ($openings as $opening) {
            $outstanding = max(0, (int) $opening->amount - (int) ($applied[$opening->id] ?? 0));
            if ($outstanding > 0) $rows->push((object) ['source' => 'opening_receivable', 'reference' => 'Opening #'.$opening->id, 'customer' => $opening->customer_name, 'customer_id' => $opening->party_id, 'effective_at' => $opening->effective_at, 'original_amount' => (int) $opening->amount, 'outstanding' => $outstanding]);
        }
        return $rows->sortBy([['customer', 'asc'], ['effective_at', 'asc']])->values()->all();
    }

    /** @return array<int,object> */
    private function vendorAccountReport(Request $request, CarbonInterface $to): array
    {
        $billQuery = DB::table('fabric_purchase_bills')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_purchase_bills.vendor_id')
            ->where('fabric_purchase_bills.state', 'posted')->where('fabric_purchase_bills.bill_date', '<=', $to)
            ->select('fabric_purchase_bills.id', 'fabric_purchase_bills.reference', 'fabric_purchase_bills.vendor_id', 'fabric_purchase_bills.bill_date', 'fabric_purchase_bills.total', 'fabric_vendors.shop_name as vendor_name');
        if ($request->filled('vendor_id')) $billQuery->where('fabric_purchase_bills.vendor_id', (int) $request->input('vendor_id'));
        $bills = $billQuery->orderBy('fabric_vendors.shop_name')->orderBy('fabric_purchase_bills.bill_date')->get();
        $billIds = $bills->pluck('id');
        $paymentAlloc = collect(); $creditAlloc = collect(); $openingAdvance = collect();
        if ($billIds->isNotEmpty()) {
            $paymentAlloc = DB::table('fabric_vendor_payment_allocations')->join('fabric_vendor_payments', 'fabric_vendor_payments.id', '=', 'fabric_vendor_payment_allocations.vendor_payment_id')
                ->whereIn('fabric_vendor_payment_allocations.purchase_bill_id', $billIds)->where('fabric_vendor_payments.effective_at', '<=', $to)->where('fabric_vendor_payment_allocations.created_at', '<=', $to)
                ->groupBy('fabric_vendor_payment_allocations.purchase_bill_id')->selectRaw('fabric_vendor_payment_allocations.purchase_bill_id, SUM(fabric_vendor_payment_allocations.amount) as amount')->pluck('amount', 'purchase_bill_id');
            $creditAlloc = DB::table('fabric_vendor_credit_allocations')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_credit_allocations.vendor_credit_id')
                ->whereIn('fabric_vendor_credit_allocations.purchase_bill_id', $billIds)->where('fabric_vendor_credits.effective_at', '<=', $to)->where('fabric_vendor_credit_allocations.created_at', '<=', $to)
                ->groupBy('fabric_vendor_credit_allocations.purchase_bill_id')->selectRaw('fabric_vendor_credit_allocations.purchase_bill_id, SUM(fabric_vendor_credit_allocations.amount) as amount')->pluck('amount', 'purchase_bill_id');
            $openingAdvance = DB::table('fabric_opening_balance_applications')->join('fabric_opening_balances', 'fabric_opening_balances.id', '=', 'fabric_opening_balance_applications.opening_balance_id')
                ->where('fabric_opening_balances.party_type', 'vendor')->where('fabric_opening_balances.kind', 'advance')->where('fabric_opening_balance_applications.target_type', 'purchase_bill')
                ->whereIn('fabric_opening_balance_applications.target_id', $billIds)->where('fabric_opening_balance_applications.effective_at', '<=', $to)
                ->groupBy('fabric_opening_balance_applications.target_id')->selectRaw('fabric_opening_balance_applications.target_id, SUM(fabric_opening_balance_applications.amount) as amount')->pluck('amount', 'target_id');
        }
        $rows = collect();
        foreach ($bills as $bill) {
            $outstanding = max(0, (int) $bill->total - (int) ($paymentAlloc[$bill->id] ?? 0) - (int) ($creditAlloc[$bill->id] ?? 0) - (int) ($openingAdvance[$bill->id] ?? 0));
            if ($outstanding > 0) $rows->push((object) ['source' => 'purchase_bill', 'reference' => $bill->reference, 'vendor' => $bill->vendor_name, 'vendor_id' => $bill->vendor_id, 'effective_at' => $bill->bill_date, 'original_amount' => (int) $bill->total, 'outstanding' => $outstanding]);
        }
        $openingQuery = DB::table('fabric_opening_balances')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_opening_balances.party_id')
            ->where('fabric_opening_balances.party_type', 'vendor')->where('fabric_opening_balances.kind', 'payable')->where('fabric_opening_balances.effective_at', '<=', $to)
            ->select('fabric_opening_balances.*', 'fabric_vendors.shop_name as vendor_name');
        if ($request->filled('vendor_id')) $openingQuery->where('fabric_opening_balances.party_id', (int) $request->input('vendor_id'));
        $openings = $openingQuery->get(); $openingIds = $openings->pluck('id');
        $applied = $openingIds->isEmpty() ? collect() : DB::table('fabric_opening_balance_applications')->whereIn('opening_balance_id', $openingIds)->where('effective_at', '<=', $to)->groupBy('opening_balance_id')->selectRaw('opening_balance_id, SUM(amount) as amount')->pluck('amount', 'opening_balance_id');
        foreach ($openings as $opening) {
            $outstanding = max(0, (int) $opening->amount - (int) ($applied[$opening->id] ?? 0));
            if ($outstanding > 0) $rows->push((object) ['source' => 'opening_payable', 'reference' => 'Opening #'.$opening->id, 'vendor' => $opening->vendor_name, 'vendor_id' => $opening->party_id, 'effective_at' => $opening->effective_at, 'original_amount' => (int) $opening->amount, 'outstanding' => $outstanding]);
        }
        return $rows->sortBy([['vendor', 'asc'], ['effective_at', 'asc']])->values()->all();
    }

    /** @return array<int,object> */
    private function returnsReport(Request $request, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = collect();
        $customer = DB::table('fabric_returns')->join('fabric_sales', 'fabric_sales.id', '=', 'fabric_returns.sale_id')->leftJoin('customers', 'customers.id', '=', 'fabric_returns.customer_id')
            ->whereBetween('fabric_returns.effective_at', [$from, $to])->select('fabric_returns.*', 'fabric_sales.reference as sale_reference', 'customers.name as party_name');
        if ($request->filled('customer_id')) $customer->where('fabric_returns.customer_id', (int) $request->input('customer_id'));
        foreach ($customer->get() as $r) $rows->push((object) ['event' => 'customer_return', 'reference' => $r->reference, 'party' => $r->party_name ?: 'Walk-in', 'source_reference' => $r->sale_reference, 'effective_at' => $r->effective_at, 'amount' => (int) $r->credit_amount, 'resolution' => $r->resolution]);
        $customerRefund = DB::table('fabric_customer_refunds')->join('fabric_customer_credits', 'fabric_customer_credits.id', '=', 'fabric_customer_refunds.credit_id')->leftJoin('customers', 'customers.id', '=', 'fabric_customer_credits.customer_id')
            ->whereBetween('fabric_customer_refunds.effective_at', [$from, $to])->select('fabric_customer_refunds.*', 'customers.name as party_name');
        if ($request->filled('customer_id')) $customerRefund->where('fabric_customer_credits.customer_id', (int) $request->input('customer_id'));
        foreach ($customerRefund->get() as $r) $rows->push((object) ['event' => 'customer_refund', 'reference' => $r->reference, 'party' => $r->party_name ?: 'Walk-in', 'source_reference' => 'Credit #'.$r->credit_id, 'effective_at' => $r->effective_at, 'amount' => (int) $r->amount, 'resolution' => 'cash/bank']);
        $vendor = DB::table('fabric_vendor_returns')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_returns.vendor_id')->join('fabric_purchase_bills', 'fabric_purchase_bills.id', '=', 'fabric_vendor_returns.purchase_bill_id')
            ->whereBetween('fabric_vendor_returns.effective_at', [$from, $to])->select('fabric_vendor_returns.*', 'fabric_vendors.shop_name as party_name', 'fabric_purchase_bills.reference as bill_reference');
        if ($request->filled('vendor_id')) $vendor->where('fabric_vendor_returns.vendor_id', (int) $request->input('vendor_id'));
        foreach ($vendor->get() as $r) $rows->push((object) ['event' => 'vendor_return', 'reference' => $r->reference, 'party' => $r->party_name, 'source_reference' => $r->bill_reference, 'effective_at' => $r->effective_at, 'amount' => (int) $r->credit_amount, 'resolution' => 'vendor_credit']);
        $vendorRefund = DB::table('fabric_vendor_refunds')->join('fabric_vendor_credits', 'fabric_vendor_credits.id', '=', 'fabric_vendor_refunds.vendor_credit_id')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_credits.vendor_id')
            ->whereBetween('fabric_vendor_refunds.effective_at', [$from, $to])->select('fabric_vendor_refunds.*', 'fabric_vendors.shop_name as party_name', 'fabric_vendor_credits.vendor_id');
        if ($request->filled('vendor_id')) $vendorRefund->where('fabric_vendor_credits.vendor_id', (int) $request->input('vendor_id'));
        foreach ($vendorRefund->get() as $r) $rows->push((object) ['event' => 'vendor_refund', 'reference' => $r->reference, 'party' => $r->party_name, 'source_reference' => 'Credit #'.$r->vendor_credit_id, 'effective_at' => $r->effective_at, 'amount' => (int) $r->amount, 'resolution' => 'cash/bank']);
        return $rows->sortByDesc('effective_at')->values()->all();
    }

    /** @return array<int,object> */
    private function advancesReport(Request $request, CarbonInterface $to): array
    {
        $rows = collect();
        $partyFilter = (string) $request->input('status', '');

        if ($partyFilter === '' || str_starts_with($partyFilter, 'customer')) {
            $receipts = DB::table('fabric_customer_receipts')->join('customers', 'customers.id', '=', 'fabric_customer_receipts.customer_id')
                ->where('fabric_customer_receipts.effective_at', '<=', $to)->select('fabric_customer_receipts.*', 'customers.name as party_name')->get();
            $ids = $receipts->pluck('id');
            $allocated = $ids->isEmpty() ? collect() : DB::table('fabric_customer_receipt_allocations')->whereIn('customer_receipt_id', $ids)->where('created_at', '<=', $to)->groupBy('customer_receipt_id')->selectRaw('customer_receipt_id, SUM(amount) as amount')->pluck('amount', 'customer_receipt_id');
            $openingUsed = $ids->isEmpty() ? collect() : DB::table('fabric_opening_balance_applications')->where('target_type', 'customer_receipt')->whereIn('target_id', $ids)->where('effective_at', '<=', $to)->groupBy('target_id')->selectRaw('target_id, SUM(amount) as amount')->pluck('amount', 'target_id');
            foreach ($receipts as $r) {
                $available = max(0, (int) $r->amount - (int) ($allocated[$r->id] ?? 0) - (int) ($openingUsed[$r->id] ?? 0));
                if ($available > 0 && ($partyFilter === '' || $partyFilter === 'customer')) $rows->push((object) ['party_type' => 'customer', 'fund_type' => 'payment_advance', 'reference' => $r->reference, 'party' => $r->party_name, 'effective_at' => $r->effective_at, 'available' => $available]);
            }
            $credits = DB::table('fabric_customer_credits')->leftJoin('customers', 'customers.id', '=', 'fabric_customer_credits.customer_id')->where('fabric_customer_credits.effective_at', '<=', $to)->select('fabric_customer_credits.*', 'customers.name as party_name')->get();
            $creditIds = $credits->pluck('id');
            $creditUsed = $creditIds->isEmpty() ? collect() : DB::table('fabric_credit_allocations')->whereIn('credit_id', $creditIds)->where('created_at', '<=', $to)->groupBy('credit_id')->selectRaw('credit_id, SUM(amount) as amount')->pluck('amount', 'credit_id');
            $creditRefunded = $creditIds->isEmpty() ? collect() : DB::table('fabric_customer_refunds')->whereIn('credit_id', $creditIds)->where('effective_at', '<=', $to)->groupBy('credit_id')->selectRaw('credit_id, SUM(amount) as amount')->pluck('amount', 'credit_id');
            foreach ($credits as $r) {
                $available = max(0, (int) $r->amount - (int) ($creditUsed[$r->id] ?? 0) - (int) ($creditRefunded[$r->id] ?? 0));
                if ($available > 0 && ($partyFilter === '' || $partyFilter === 'customer_credit')) $rows->push((object) ['party_type' => 'customer', 'fund_type' => 'return_credit', 'reference' => $r->reference, 'party' => $r->party_name ?: 'Walk-in', 'effective_at' => $r->effective_at, 'available' => $available]);
            }
        }
        if ($partyFilter === '' || str_starts_with($partyFilter, 'vendor')) {
            $payments = DB::table('fabric_vendor_payments')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_payments.vendor_id')->where('fabric_vendor_payments.effective_at', '<=', $to)->select('fabric_vendor_payments.*', 'fabric_vendors.shop_name as party_name')->get();
            $ids = $payments->pluck('id');
            $allocated = $ids->isEmpty() ? collect() : DB::table('fabric_vendor_payment_allocations')->whereIn('vendor_payment_id', $ids)->where('created_at', '<=', $to)->groupBy('vendor_payment_id')->selectRaw('vendor_payment_id, SUM(amount) as amount')->pluck('amount', 'vendor_payment_id');
            $openingUsed = $ids->isEmpty() ? collect() : DB::table('fabric_opening_balance_applications')->where('target_type', 'vendor_payment')->whereIn('target_id', $ids)->where('effective_at', '<=', $to)->groupBy('target_id')->selectRaw('target_id, SUM(amount) as amount')->pluck('amount', 'target_id');
            foreach ($payments as $r) {
                $available = max(0, (int) $r->amount - (int) ($allocated[$r->id] ?? 0) - (int) ($openingUsed[$r->id] ?? 0));
                if ($available > 0 && ($partyFilter === '' || $partyFilter === 'vendor')) $rows->push((object) ['party_type' => 'vendor', 'fund_type' => 'payment_advance', 'reference' => $r->reference, 'party' => $r->party_name, 'effective_at' => $r->effective_at, 'available' => $available]);
            }
            $credits = DB::table('fabric_vendor_credits')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_credits.vendor_id')->where('fabric_vendor_credits.effective_at', '<=', $to)->select('fabric_vendor_credits.*', 'fabric_vendors.shop_name as party_name')->get();
            $creditIds = $credits->pluck('id');
            $creditUsed = $creditIds->isEmpty() ? collect() : DB::table('fabric_vendor_credit_allocations')->whereIn('vendor_credit_id', $creditIds)->where('created_at', '<=', $to)->groupBy('vendor_credit_id')->selectRaw('vendor_credit_id, SUM(amount) as amount')->pluck('amount', 'vendor_credit_id');
            $creditRefunded = $creditIds->isEmpty() ? collect() : DB::table('fabric_vendor_refunds')->whereIn('vendor_credit_id', $creditIds)->where('effective_at', '<=', $to)->groupBy('vendor_credit_id')->selectRaw('vendor_credit_id, SUM(amount) as amount')->pluck('amount', 'vendor_credit_id');
            foreach ($credits as $r) {
                $available = max(0, (int) $r->amount - (int) ($creditUsed[$r->id] ?? 0) - (int) ($creditRefunded[$r->id] ?? 0));
                if ($available > 0 && ($partyFilter === '' || $partyFilter === 'vendor_credit')) $rows->push((object) ['party_type' => 'vendor', 'fund_type' => 'return_credit', 'reference' => $r->reference, 'party' => $r->party_name, 'effective_at' => $r->effective_at, 'available' => $available]);
            }
        }

        $openings = DB::table('fabric_opening_balances')->where('kind', 'advance')->where('effective_at', '<=', $to)->get();
        $openingIds = $openings->pluck('id');
        $used = $openingIds->isEmpty() ? collect() : DB::table('fabric_opening_balance_applications')->whereIn('opening_balance_id', $openingIds)->where('effective_at', '<=', $to)->groupBy('opening_balance_id')->selectRaw('opening_balance_id, SUM(amount) as amount')->pluck('amount', 'opening_balance_id');
        $customerNames = DB::table('customers')->whereIn('id', $openings->where('party_type', 'customer')->pluck('party_id'))->pluck('name', 'id');
        $vendorNames = DB::table('fabric_vendors')->whereIn('id', $openings->where('party_type', 'vendor')->pluck('party_id'))->pluck('shop_name', 'id');
        foreach ($openings as $r) {
            if ($partyFilter !== '' && $partyFilter !== $r->party_type) continue;
            $available = max(0, (int) $r->amount - (int) ($used[$r->id] ?? 0));
            if ($available <= 0) continue;
            $party = $r->party_type === 'customer' ? ($customerNames[$r->party_id] ?? 'Customer #'.$r->party_id) : ($vendorNames[$r->party_id] ?? 'Vendor #'.$r->party_id);
            $rows->push((object) ['party_type' => $r->party_type, 'fund_type' => 'opening_advance', 'reference' => 'Opening #'.$r->id, 'party' => $party, 'effective_at' => $r->effective_at, 'available' => $available]);
        }
        return $rows->sortByDesc('effective_at')->values()->all();
    }

    /** @return array<int,object> */
    private function reportRows(string $type, Request $request): array
    {
        $from = $request->filled('from') ? FabricInventory::date($request->input('from').' 00:00:00') : now()->subMonth();
        $to = $request->filled('to') ? FabricInventory::date($request->input('to').' 23:59:59') : now();
        $rows = match ($type) {
            'sales' => tap(DB::table('fabric_sales')->leftJoin('customers', 'customers.id', '=', 'fabric_sales.customer_id')->whereBetween('sale_at', [$from, $to])->select('fabric_sales.*', 'customers.name as customer_name'), function ($q) use ($request) { if ($request->filled('customer_id')) $q->where('fabric_sales.customer_id', (int) $request->input('customer_id')); if ($request->filled('status')) $q->where('fabric_sales.state', $request->input('status')); })->orderByDesc('sale_at')->get(),
            'purchases' => tap(DB::table('fabric_purchase_bills')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_purchase_bills.vendor_id')->whereBetween('bill_date', [$from, $to])->select('fabric_purchase_bills.*', 'fabric_vendors.shop_name as vendor_name'), function ($q) use ($request) { if ($request->filled('vendor_id')) $q->where('fabric_purchase_bills.vendor_id', (int) $request->input('vendor_id')); if ($request->filled('status')) $q->where('fabric_purchase_bills.state', $request->input('status')); })->orderByDesc('bill_date')->get(),
            'stock' => tap(DB::table('fabric_rolls')->join('fabric_products', 'fabric_products.id', '=', 'fabric_rolls.product_id')->join('fabric_colors', 'fabric_colors.id', '=', 'fabric_rolls.color_id')->select('fabric_rolls.*', 'fabric_products.name as product_name', 'fabric_products.unit', 'fabric_colors.name as color_name'), function ($q) use ($request) { if ($request->filled('product_id')) $q->where('fabric_rolls.product_id', (int) $request->input('product_id')); if ($request->filled('status')) $q->where('fabric_rolls.condition', $request->input('status')); })->orderBy('fabric_products.name')->get(),
            'movements' => tap(DB::table('fabric_stock_movements')->join('fabric_rolls', 'fabric_rolls.id', '=', 'fabric_stock_movements.roll_id')->whereBetween('fabric_stock_movements.effective_at', [$from, $to])->select('fabric_stock_movements.*', 'fabric_rolls.roll_number', 'fabric_rolls.product_id'), function ($q) use ($request) { if ($request->filled('product_id')) $q->where('fabric_rolls.product_id', (int) $request->input('product_id')); if ($request->filled('status')) $q->where('fabric_stock_movements.kind', $request->input('status')); })->orderByDesc('fabric_stock_movements.effective_at')->get(),
            'expenses' => DB::table('fabric_expenses')->whereBetween('effective_at', [$from, $to])->orderByDesc('effective_at')->get(),
            'installments' => tap(DB::table('fabric_vendor_installments')->join('fabric_vendors', 'fabric_vendors.id', '=', 'fabric_vendor_installments.vendor_id')->select('fabric_vendor_installments.*', 'fabric_vendors.shop_name as vendor_name'), function ($q) use ($request, $from, $to) {
                $q->whereBetween('fabric_vendor_installments.due_at', [$from, $to]);
                if ($request->filled('vendor_id')) $q->where('fabric_vendor_installments.vendor_id', (int) $request->input('vendor_id'));
                $status = (string) $request->input('status', '');
                $timezone = BusinessSetting::findOrFail(1)->timezone;
                $todayStart = Carbon::now($timezone)->startOfDay()->utc();
                $todayEnd = Carbon::now($timezone)->endOfDay()->utc();
                if ($status === 'paid') $q->where('fabric_vendor_installments.status', 'paid');
                elseif ($status === 'overdue') $q->where('fabric_vendor_installments.status', 'due')->where('fabric_vendor_installments.due_at', '<', $todayStart);
                elseif ($status === 'due_today') $q->where('fabric_vendor_installments.status', 'due')->whereBetween('fabric_vendor_installments.due_at', [$todayStart, $todayEnd]);
                elseif ($status === 'upcoming') $q->where('fabric_vendor_installments.status', 'due')->where('fabric_vendor_installments.due_at', '>', $todayEnd);
                elseif ($status === 'due') $q->where('fabric_vendor_installments.status', 'due')->where('fabric_vendor_installments.due_at', '<=', $todayEnd);
            })->orderBy('due_at')->get()->map(function ($row) { $row->display_status = $this->installmentDisplayStatus($row); return $row; }),
            'cash' => DB::table('fabric_account_entries')->whereBetween('effective_at', [$from, $to])->orderByDesc('effective_at')->get(),
            'customers' => collect($this->customerAccountReport($request, $to)),
            'vendors' => collect($this->vendorAccountReport($request, $to)),
            'returns' => collect($this->returnsReport($request, $from, $to)),
            'advances' => collect($this->advancesReport($request, $to)),
            'profit' => collect([(object) FabricInventory::dashboard($from, $to)]),
            default => abort(404),
        };

        return $rows->map(fn ($row) => (object) $row)->all();
    }

    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($this->role($request), ['owner', 'manager'], true), 403);
        $format = strtolower((string) $request->input('format', 'csv'));
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $rows = FabricReportPresenter::rows($type, $this->reportRows($type, $request));
        $path = FabricDocuments::writeExport($rows, 'fabric-'.$type, $format);
        $name = 'fabric-'.$type.'-'.now()->format('Ymd-His').'.'.$format;

        return Storage::disk('local')->download($path, $name, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ]);
    }

    public function document(Request $request, string $type, int $id): HttpResponse
    {
        abort_unless(in_array($type, ['invoice', 'thermal', 'customer-receipt', 'customer-statement', 'vendor-statement', 'barcode', 'barcode-label'], true), 404);
        if ($type === 'vendor-statement') {
            abort_unless(in_array($this->role($request), ['owner', 'manager'], true), 403);
        }

        if ($type === 'thermal') {
            return FabricDocuments::thermal($id);
        }
        if ($type === 'barcode-label') {
            return FabricDocuments::barcodeLabel($id);
        }

        return FabricDocuments::pdf($type, $id);
    }

    public function authorizeWhatsApp(Request $request): JsonResponse
    {
        abort_unless(in_array($this->role($request), ['owner', 'manager'], true), 403);
        $data = $request->validate(['customer_ids' => 'required|array|min:1|max:500', 'customer_ids.*' => 'required|integer|distinct', 'message' => 'required|string|max:5000', 'mediaFilename' => ['nullable', 'string', 'regex:/^media_[a-zA-Z0-9_.-]+$/']]);
        $customers = DB::table('customers')->where('active', true)->whereIn('id', $data['customer_ids'])->get();
        abort_unless($customers->count() === count($data['customer_ids']), 422, 'Select active customers.');
        $phones = [];
        foreach ($customers as $customer) {
            $phone = $customer->normalized_whatsapp ?: $customer->normalized_phone;
            $consented = $phone && DB::table('fabric_marketing_contacts')->where('phone', $phone)->where('whatsapp_consent', true)->where('opted_out', false)->exists();
            abort_unless($consented, 422, 'Record WhatsApp consent in Marketing for every selected recipient. Opted-out contacts cannot receive messages.');
            $phones[] = $phone;
        }
        $keyPath = storage_path('app/private/whatsapp-server.key');
        abort_unless(is_file($keyPath), 503, 'Start the WhatsApp server before sending.');
        $payload = json_encode(['phones' => array_values(array_unique($phones)), 'message' => $data['message'], 'mediaFilename' => $data['mediaFilename'] ?? null, 'expires' => now()->addSeconds(60)->getTimestamp(), 'nonce' => (string) \Illuminate\Support\Str::uuid()], JSON_THROW_ON_ERROR);
        return response()->json(['payload' => $payload, 'signature' => hash_hmac('sha256', $payload, trim((string) file_get_contents($keyPath)))])->header('Cache-Control', 'no-store');
    }

    public function saleQuote(Request $request): JsonResponse
    {
        $data = $request->validate(['lines' => 'required|array|min:1|max:200', 'lines.*.roll_id' => 'required|integer', 'lines.*.quantity' => 'required', 'lines.*.unit_price' => 'nullable', 'lines.*.manual_discount' => 'nullable']);
        return response()->json(FabricInventory::saleQuote($data['lines']));
    }

    public function barcodeImage(int $id): HttpResponse
    {
        $roll = DB::table('fabric_rolls')->where('id', $id)->firstOrFail();
        return response(FabricDocuments::barcodeSvg((string) $roll->barcode), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function expenseReceipt(Request $request, int $id): StreamedResponse
    {
        abort_unless(in_array($this->role($request), ['owner', 'manager'], true), 403);
        $expense = DB::table('fabric_expenses')->where('id', $id)->firstOrFail();
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);
        return Storage::disk('local')->download($expense->receipt_path);
    }

    public function campaignLaunch(Request $request, int $id): RedirectResponse
    {
        abort_unless($this->role($request) === 'owner', 403);
        $campaign = DB::table('fabric_marketing_campaigns')->where('id', $id)->firstOrFail();
        abort_unless($campaign->state === 'approved', 422, 'Approve the campaign first.');
        if (! $campaign->provider) {
            return back()->withErrors(['form' => 'Choose and configure an approved WhatsApp/SMS provider before sending. Campaign preparation is complete; external delivery is not configured.']);
        }
        return back()->withErrors(['form' => 'Provider delivery connector is not configured in this installation. No messages were sent.']);
    }
}

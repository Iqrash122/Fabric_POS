import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BadgeDollarSign,
    Banknote,
    Barcode,
    Boxes,
    Building2,
    ChartNoAxesCombined,
    CheckSquare,
    ChevronRight,
    ClipboardCheck,
    FileDown,
    FileText,
    Gauge,
    ImageIcon,
    LogOut,
    Megaphone,
    Menu,
    MessageCircle,
    PackageCheck,
    PackageOpen,
    Plus,
    Printer,
    ReceiptText,
    RotateCcw,
    Search,
    Send,
    Settings2,
    ShoppingCart,
    Square,
    Store,
    Tags,
    Users,
    WalletCards,
    Wifi,
    WifiOff,
    X,
} from 'lucide-react';
import { cloneElement, type ComponentType, FormEvent, isValidElement, ReactNode, useEffect, useId, useMemo, useRef, useState } from 'react';
import {
    allowNextUnsavedNavigation,
    confirmDirtyScopeClose,
    DirtyScope,
    ErrorSummary,
    useFirstErrorFocus,
    useUnsavedChanges,
} from './form-support';
import './fabric.css';

type Row = Record<string, unknown>;
type Payload = Record<string, FormDataConvertible>;
type Errors = Record<string, string>;

type Props = {
    section: string;
    role: 'owner' | 'manager' | 'cashier';
    business: Row;
    fabric_settings: Row;
    accounts: Row[];
    categories: Row[];
    colors: Row[];
    products: Row[];
    rolls: Row[];
    vendors: Row[];
    customers: Row[];
    filters: Record<string, string>;
    dashboard?: Record<string, number>;
    period?: { from: string; to: string; from_date?: string; to_date?: string };
    recent_sales?: Row[];
    due_installments?: Row[];
    sales?: Row[];
    customer_accounts?: Row[];
    receipts?: Row[];
    customer_advances?: Row[];
    customer_credits?: Row[];
    customer_open_sales?: Row[];
    vendor_accounts?: Row[];
    vendor_payments?: Row[];
    vendor_advances?: Row[];
    vendor_credits?: Row[];
    vendor_open_bills?: Row[];
    installments?: Row[];
    purchase_bills?: Row[];
    movements?: Row[];
    reservations?: Row[];
    stock_counts?: Row[];
    customer_returns?: Row[];
    vendor_returns?: Row[];
    sale_lines?: Row[];
    account_balances?: Row[];
    account_entries?: Row[];
    transfers?: Row[];
    expenses?: Row[];
    promotions?: Row[];
    contacts?: Row[];
    campaigns?: Row[];
    report_type?: string;
    report_rows?: Row[];
    opening_balances?: Row[];
    staff?: Row[];
    audit?: Row[];
    last_sale_id?: string | number;
};

const nav = [
    ['dashboard', 'Dashboard', Gauge],
    ['pos', 'Sales / POS', ShoppingCart],
    ['customers', 'Customers', Users],
    ['vendors', 'Vendors', Building2],
    ['purchases', 'Purchases', PackageOpen],
    ['inventory', 'Inventory', Boxes],
    ['returns', 'Returns / Refunds', RotateCcw],
    ['cash-bank', 'Cash / Bank', WalletCards],
    ['expenses', 'Expenses', ReceiptText],
    ['promotions', 'Promotions', Tags],
    ['marketing', 'Marketing', Megaphone],
    ['whatsapp-bulk', 'WhatsApp Bulk', MessageCircle],
    ['reports', 'Reports', ChartNoAxesCombined],
    ['settings', 'Settings / Audit', Settings2],
] as const;

const sectionMeta: Record<string, { eyebrow: string; description: string }> = {
    dashboard: { eyebrow: 'Command center', description: 'Live sales, purchasing, collections, payables, stock value and profit at a glance.' },
    pos: { eyebrow: 'Fast checkout', description: 'Scan exact rolls, build invoices, split payments and post stock movement in one flow.' },
    customers: { eyebrow: 'Customer ledger', description: 'Review fabric balances, collections, advances, retained credits and printable statements.' },
    vendors: { eyebrow: 'Supplier ledger', description: 'Manage suppliers, payments, advances, credits, installments and account history.' },
    purchases: { eyebrow: 'Procurement', description: 'Post purchase bills and receive individual rolls without creating duplicate stock.' },
    inventory: { eyebrow: 'Stock control', description: 'Track every roll, barcode, reservation, adjustment, count and available quantity.' },
    returns: { eyebrow: 'Controlled corrections', description: 'Process customer and vendor returns with traceable stock and financial effects.' },
    'cash-bank': { eyebrow: 'Money movement', description: 'Monitor cash and bank balances, entries and transfers separately from sales and expenses.' },
    expenses: { eyebrow: 'Operating costs', description: 'Record business expenses with account impact, notes and private receipt attachments.' },
    promotions: { eyebrow: 'Pricing campaigns', description: 'Schedule controlled discounts with priority, stacking rules and historical protection.' },
    marketing: { eyebrow: 'Consent-first outreach', description: 'Maintain opted-in contacts and prepare WhatsApp or SMS campaigns safely.' },
    'whatsapp-bulk': { eyebrow: 'Bulk messaging', description: 'Select customers and send WhatsApp messages, images or videos to all of them in one click.' },
    reports: { eyebrow: 'Business intelligence', description: 'Inspect sales, purchases, stock, cash, expenses and profitability with export-ready views.' },
    settings: { eyebrow: 'Administration', description: 'Configure inventory rules, document layouts, opening balances, staff access and audit history.' },
};

const s = (value: unknown) => (value === null || value === undefined ? '' : String(value));
const n = (value: unknown) => Number(value ?? 0);
const bool = (value: unknown) => Boolean(value);
const money = (value: unknown, currency = 'PKR') =>
    `${currency} ${(n(value) / 100).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const qty = (value: unknown, unit = 'meter') => {
    const amount = n(value) / 1000;
    return unit === 'piece' ? `${amount.toLocaleString()} pcs` : `${amount.toFixed(3)} m`;
};
const localInput = (timeZone: string, source?: string) => {
    // Stored timestamps are UTC; datetime-local fields are interpreted in the shop timezone.
    const timestamp = source && !/(?:Z|[+-]\d{2}:\d{2})$/i.test(source) ? `${source.replace(' ', 'T')}Z` : source;
    const date = timestamp ? new Date(timestamp) : new Date();
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
        timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map((part) => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};
const options = (rows: Row[], label: (row: Row) => string) => rows.map((row) => ({ value: s(row.id), label: label(row) }));

function actionSnapshot(value: unknown): string {
    return JSON.stringify(value, (_key, item) => {
        if (typeof File !== 'undefined' && item instanceof File) {
            return { __file: true, name: item.name, size: item.size, type: item.type, lastModified: item.lastModified };
        }
        return item;
    });
}

function useAction<T extends Payload>(initial: T, url: string, onSuccess?: () => void, successData?: (current: T) => T) {
    const [data, setData] = useState<T>(initial);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const initialRef = useRef(actionSnapshot(initial));
    const formRef = useFirstErrorFocus(errors);
    const submitting = useRef(false);
    useUnsavedChanges(actionSnapshot(data) !== initialRef.current);

    const reset = (next: T = initial) => {
        initialRef.current = actionSnapshot(next);
        setData(next);
        setErrors({});
    };

    const post = (payload: Payload = data, multipart = false) => {
        if (processing || submitting.current) return;
        submitting.current = true;
        setProcessing(true);
        setErrors({});
        allowNextUnsavedNavigation();
        router.post(url, payload, {
            preserveScroll: true,
            forceFormData: multipart,
            onSuccess: () => {
                const succeeded = successData ? successData(data) : data;
                const next = Object.prototype.hasOwnProperty.call(succeeded, 'idempotency_key')
                    ? ({ ...succeeded, idempotency_key: crypto.randomUUID() } as T)
                    : succeeded;
                initialRef.current = actionSnapshot(next);
                setData(next);
                setErrors({});
                onSuccess?.();
            },
            onError: (next) => setErrors(next),
            onFinish: () => {
                submitting.current = false;
                setProcessing(false);
            },
        });
    };
    const submit = (event: FormEvent, multipart = false) => {
        event.preventDefault();
        post(data, multipart);
    };
    return { data, setData, errors, processing, submit, post, reset, formRef };
}

function Field({ label, children, hint }: { label: string; children: ReactNode; hint?: string }) {
    const labelId = useId();
    return (
        <label className="fabric-field">
            <span id={labelId}>{label}</span>
            {isValidElement<{ 'aria-labelledby'?: string }>(children) ? cloneElement(children, { 'aria-labelledby': labelId }) : children}
            {hint && <small>{hint}</small>}
        </label>
    );
}

function Select({ value, onChange, rows, placeholder = 'Select…', 'aria-labelledby': labelledBy }: { value: string; onChange: (value: string) => void; rows: { value: string; label: string }[]; placeholder?: string; 'aria-labelledby'?: string }) {
    return (
        <select aria-labelledby={labelledBy} value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">{placeholder}</option>
            {rows.map((row) => <option key={row.value} value={row.value}>{row.label}</option>)}
        </select>
    );
}

function SectionCard({ title, children, aside }: { title: string; children: ReactNode; aside?: ReactNode }) {
    return (
        <section className="fabric-card">
            <div className="fabric-card-head"><h2>{title}</h2>{aside}</div>
            {children}
        </section>
    );
}

function Button({ children, type = 'button', onClick, secondary = false, disabled = false }: { children: ReactNode; type?: 'button' | 'submit'; onClick?: () => void; secondary?: boolean; disabled?: boolean }) {
    return <button type={type} onClick={onClick} disabled={disabled} className={secondary ? 'fabric-button secondary' : 'fabric-button'}>{children}</button>;
}

function Status({ value }: { value: unknown }) {
    return <span className={`fabric-status status-${s(value).toLowerCase().replaceAll(' ', '-')}`}>{s(value) || '—'}</span>;
}

function Table({ rows, columns, empty = 'No records yet.' }: { rows: Row[]; columns: { label: string; value: (row: Row) => ReactNode }[]; empty?: string }) {
    if (!rows.length) return <div className="fabric-empty"><Boxes size={28} /><strong>{empty}</strong></div>;
    return (
        <div className="fabric-table-wrap" role="region" aria-label="Data table" tabIndex={0}>
            <table><thead><tr>{columns.map((column) => <th scope="col" key={column.label}>{column.label}</th>)}</tr></thead>
                <tbody>{rows.map((row, index) => <tr key={s(row.id) || index}>{columns.map((column) => <td key={column.label} data-label={column.label}>{column.value(row)}</td>)}</tr>)}</tbody>
            </table>
        </div>
    );
}

function InlineAction({ label, url, payload, secondary = true }: { label: string; url: string; payload?: Payload; secondary?: boolean }) {
    const [busy, setBusy] = useState(false);
    return <Button secondary={secondary} disabled={busy} onClick={() => {
        if (busy) return;
        setBusy(true);
        router.post(url, payload ?? {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    }}>{busy ? 'Working…' : label}</Button>;
}

function Expand({ title, children }: { title: string; children: ReactNode }) {
    const rawId = useId();
    const scope = `fabric-expand-${rawId.replaceAll(':', '')}`;
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);

    const close = () => {
        if (!confirmDirtyScopeClose(scope)) return;
        setOpen(false);
        window.requestAnimationFrame(() => triggerRef.current?.focus());
    };

    useEffect(() => {
        if (!open) return;
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open]);

    return <div className={open ? 'fabric-expand open' : 'fabric-expand'}>
        <button ref={triggerRef} type="button" className="fabric-expand-trigger" aria-expanded={open} aria-controls={`${scope}-panel`} onClick={() => open ? close() : setOpen(true)}><Plus size={16} /> {title}</button>
        {open && <>
            <button type="button" className="fabric-expand-scrim" aria-label={`Close ${title}`} onClick={close} />
            <div id={`${scope}-panel`} className="fabric-expand-body" role="dialog" aria-label={title}>
                <button type="button" className="fabric-expand-panel-close" aria-label={`Close ${title}`} onClick={close}><X size={17} /></button>
                <DirtyScope scope={scope}>{children}</DirtyScope>
            </div>
        </>}
    </div>;
}

function Layout({ p, children }: { p: Props; children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const [navigating, setNavigating] = useState(false);
    const page = usePage();
    const errors = (page.props.errors ?? {}) as Errors;
    const success = page.props.success as string | undefined;
    const allowedNav = p.role === 'cashier'
        ? nav.filter(([key]) => ['dashboard', 'pos', 'customers', 'inventory'].includes(key))
        : p.role === 'manager'
            ? nav.filter(([key]) => key !== 'settings')
            : nav;
    const title = nav.find(([key]) => key === p.section)?.[1] ?? 'Fabric';
    const meta = sectionMeta[p.section] ?? sectionMeta.dashboard;
    const activeRolls = p.rolls.filter((roll) => bool(roll.active) && ['sellable', 'remnant'].includes(s(roll.condition))).length;

    useEffect(() => {
        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
        };
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, []);

    useEffect(() => {
        document.body.classList.toggle('fabric-nav-open', open);
        return () => document.body.classList.remove('fabric-nav-open');
    }, [open]);

    useEffect(() => {
        const removeStart = router.on('start', () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    return (
        <div className={navigating ? "fabric-app is-navigating" : "fabric-app"}>
            <div className="fabric-page-progress" aria-hidden="true" />
            <Head title={`${title} · Fabric Inventory`} />
            <a className="fabric-skip" href="#fabric-main">Skip to main content</a>
            <aside className={open ? 'fabric-side open' : 'fabric-side'} aria-label="Fabric workspace navigation">
                <button type="button" className="fabric-side-close" aria-label="Close navigation" onClick={() => setOpen(false)}><X /></button>
                <Link className="fabric-brand" href="/fabric" onClick={() => setOpen(false)}>
                    <span><Store /></span>
                    <div><strong>{s(p.business.name) || 'Fabric POS'}</strong><small>FABRIC · INVENTORY · BILLING</small></div>
                </Link>
                <div className="fabric-nav-label">Workspace</div>
                <nav>{allowedNav.map(([key, label, Icon]) => <Link key={key} aria-current={p.section === key ? 'page' : undefined} className={p.section === key ? 'active' : ''} href={key === 'dashboard' ? '/fabric' : `/fabric/${key}`} onClick={() => setOpen(false)}><Icon size={18} /><span>{label}</span>{p.section === key && <ChevronRight className="fabric-nav-arrow" size={15} />}</Link>)}</nav>
                <div className="fabric-side-summary" aria-label="Inventory summary">
                    <small>LIVE INVENTORY</small>
                    <strong>{activeRolls.toLocaleString()} active rolls</strong>
                    <span>Exact-roll stock tracking enabled</span>
                </div>
                <div className="fabric-side-foot">
                    <button type="button" onClick={() => router.post('/logout')}><LogOut size={16} /> Sign out</button>
                    <small>{p.role.toUpperCase()} ACCESS</small>
                </div>
            </aside>
            {open && <button type="button" className="fabric-backdrop" aria-label="Close navigation" onClick={() => setOpen(false)} />}
            <div className="fabric-workspace">
                <header className="fabric-topbar">
                    <button type="button" className="fabric-menu" aria-label="Open navigation" aria-expanded={open} onClick={() => setOpen(true)}><Menu /></button>
                    <form action="/fabric/inventory" className="fabric-search" role="search"><Search size={17} /><input name="q" defaultValue={p.filters.q ?? ''} aria-label="Search inventory" placeholder="Search roll, fabric, color or barcode…" /></form>
                    <div className="fabric-live-chip"><span /> Live stock</div>
                    <span className="fabric-role"><span className="fabric-role-text">{p.role}</span><span className="fabric-avatar">{p.role.slice(0, 1).toUpperCase()}</span></span>
                </header>
                <main id="fabric-main" tabIndex={-1}>
                    <div className="fabric-heading">
                        <div className="fabric-heading-copy"><p>{meta.eyebrow}</p><h1>{title}</h1><span>{meta.description}</span></div>
                        <div className="fabric-heading-actions"><Link className="fabric-link-button" href="/fabric/pos"><Barcode size={17} /> Open POS</Link></div>
                    </div>
                    {success && <div className="fabric-notice" role="status"><PackageCheck size={18} /><div><strong>Saved successfully</strong><span>{success}</span></div></div>}
                    {Object.keys(errors).length > 0 && <div className="fabric-error" role="alert"><div><strong>Please review the highlighted fields</strong><span>{Object.values(errors).join(' ')}</span></div></div>}
                    {children}
                </main>
                <footer>Fabric stock, customer/vendor balances and money movements remain traceable to their source documents.</footer>
            </div>
            <nav className="fabric-mobile-nav" aria-label="Quick navigation">
                <Link aria-current={p.section === 'dashboard' ? 'page' : undefined} className={p.section === 'dashboard' ? 'active' : ''} href="/fabric"><Gauge /><span>Home</span></Link>
                <Link aria-current={p.section === 'pos' ? 'page' : undefined} className={p.section === 'pos' ? 'active' : ''} href="/fabric/pos"><ShoppingCart /><span>POS</span></Link>
                <Link aria-current={p.section === 'inventory' ? 'page' : undefined} className={p.section === 'inventory' ? 'active' : ''} href="/fabric/inventory"><Boxes /><span>Stock</span></Link>
                <Link aria-current={p.section === 'customers' ? 'page' : undefined} className={p.section === 'customers' ? 'active' : ''} href="/fabric/customers"><Users /><span>Customers</span></Link>
            </nav>
        </div>
    );
}

function Dashboard({ p }: { p: Props }) {
    const d = p.dashboard ?? {};
    const currency = s(p.business.currency) || 'PKR';
    const from = s(p.period?.from_date) || p.period?.from?.slice(0, 10) || '';
    const to = s(p.period?.to_date) || p.period?.to?.slice(0, 10) || '';
    const reportHref = (report: string, status = '') => `/fabric/reports?${new URLSearchParams({ report, from, to, ...(status ? { status } : {}) }).toString()}`;
    const cards = (p.role === 'cashier' ? [
        ['Net sales', money(d.net_sales, currency), ShoppingCart, '/fabric/pos'],
        ['Collections', money(d.collections, currency), Banknote, '/fabric/customers'],
        ['Low stock', s(d.low_stock), PackageCheck, '/fabric/inventory'],
        ['Returns', money(d.returns, currency), RotateCcw, '/fabric/customers'],
    ] : [
        ['Net sales', money(d.net_sales, currency), ShoppingCart, reportHref('sales')],
        ['Collections', money(d.collections, currency), Banknote, reportHref('cash')],
        ['Purchases', money(d.purchases, currency), PackageOpen, reportHref('purchases')],
        ['Vendor payments', money(d.vendor_payments, currency), Building2, reportHref('cash')],
        ['Customer outstanding', money(d.customer_outstanding, currency), Users, reportHref('customers')],
        ['Vendor outstanding', money(d.vendor_outstanding, currency), Building2, reportHref('vendors')],
        ['Customer advances', money(d.customer_advances, currency), WalletCards, reportHref('advances', 'customer')],
        ['Vendor advances', money(d.vendor_advances, currency), WalletCards, reportHref('advances', 'vendor')],
        ['Stock value', money(d.stock_value, currency), Boxes, reportHref('stock')],
        ['Gross profit', money(d.gross_profit, currency), ChartNoAxesCombined, reportHref('profit')],
        ['Net profit', money(d.net_profit, currency), BadgeDollarSign, reportHref('profit')],
        ['Expenses', money(d.expenses, currency), ReceiptText, reportHref('expenses')],
        ['Low-stock products', s(d.low_stock_products), PackageCheck, reportHref('stock')],
        ['Low-stock rolls', s(d.low_stock_rolls), PackageOpen, reportHref('stock')],
        ['Installments overdue', s(d.installments_overdue), ClipboardCheck, reportHref('installments', 'overdue')],
        ['Installments due today', s(d.installments_due_today), ClipboardCheck, reportHref('installments', 'due_today')],
        ['Installments upcoming', s(d.installments_upcoming), ClipboardCheck, reportHref('installments', 'upcoming')],
        ['Customer returns', money(d.returns, currency), RotateCcw, reportHref('returns')],
        ['Customer refunds', money(d.customer_refunds, currency), Banknote, reportHref('returns')],
        ['Vendor returns', money(d.vendor_returns, currency), RotateCcw, reportHref('returns')],
    ]) as readonly [string, string, ComponentType<{ size?: number }>, string][];
    return <>
        <form className="fabric-period" action="/fabric"><select name="period" defaultValue={s(p.filters.period) || (p.filters.from || p.filters.to ? 'custom' : 'today')}><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option><option value="custom">Custom dates</option></select><input name="from" type="date" defaultValue={p.filters.from ?? ''} /><input name="to" type="date" defaultValue={p.filters.to ?? ''} /><Button type="submit" secondary>Apply</Button></form>
        <div className="fabric-metrics">{cards.map(([label, value, Icon, href]) => <Link className="fabric-metric" href={href} key={label}><span><Icon /></span><div><small>{label}</small><strong>{value}</strong></div><ChevronRight className="fabric-metric-chevron" size={17} aria-hidden="true" /></Link>)}</div>
        {p.role !== 'cashier' && <div className="fabric-balance-strip" aria-label="Available credits"><span>Customer retained credit <strong>{money(d.customer_credits, currency)}</strong></span><span>Vendor retained credit <strong>{money(d.vendor_credits, currency)}</strong></span></div>}
        <div className="fabric-grid two"><SectionCard title="Recent fabric sales"><Table rows={p.recent_sales ?? []} columns={[{ label: 'Invoice', value: (r) => <Link href={`/fabric/document/invoice/${s(r.id)}`}>{s(r.reference)}</Link> }, { label: 'Total', value: (r) => money(r.total, currency) }, { label: 'Paid', value: (r) => money(r.paid, currency) }, { label: 'Balance', value: (r) => money(r.current_balance ?? r.balance, currency) }]} /></SectionCard>
            {p.role !== 'cashier' && <SectionCard title="Installment reminders"><Table rows={p.due_installments ?? []} columns={[{ label: 'Vendor', value: (r) => s(r.vendor_name) }, { label: 'Due', value: (r) => new Date(s(r.due_at)).toLocaleDateString() }, { label: 'Amount', value: (r) => money(r.amount, currency) }, { label: 'Status', value: (r) => <Status value={r.display_status ?? r.status} /> }]} /></SectionCard>}</div>
    </>;
}

type CartLine = { roll_id: string; quantity: string; unit_price: string; manual_discount: string };
function QuickCustomerForm() {
    const a = useAction({ name: '', phone: '', address: '' }, '/fabric/action/customer-save', () => router.reload({ only: ['customers'] }));
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}><Field label="Customer name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="Phone"><input value={a.data.phone} onChange={(e) => a.setData({ ...a.data, phone: e.target.value })} /></Field><Field label="Address"><textarea value={a.data.address} onChange={(e) => a.setData({ ...a.data, address: e.target.value })} /></Field><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>{a.processing ? 'Saving…' : 'Create customer'}</Button></form>;
}
function Pos({ p }: { p: Props }) {
    const sellable = p.rolls.filter((r) => bool(r.active) && ['sellable', 'remnant'].includes(s(r.condition)) && n(r.available_qty_milli) > 0);
    const [barcode, setBarcode] = useState('');
    const barcodeInput = useRef<HTMLInputElement>(null);
    const barcodeValue = useRef('');
    const scanQueue = useRef(Promise.resolve());
    const pendingScans = useRef(0);
    const [scanning, setScanning] = useState(false);
    const [scannedRolls, setScannedRolls] = useState<Row[]>([]);
    const [scanError, setScanError] = useState('');
    const [unstockedProduct, setUnstockedProduct] = useState<Row | null>(null);
    const [lines, setLines] = useState<CartLine[]>([]);
    const [customer, setCustomer] = useState('');
    const [account, setAccount] = useState(() => s(p.accounts.find((a) => bool(a.active))?.id) || s(p.accounts[0]?.id));
    const [payment, setPayment] = useState('');
    const [account2, setAccount2] = useState('');
    const [payment2, setPayment2] = useState('');
    const [credit, setCredit] = useState(false);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Errors>({});
    const [rollSearch, setRollSearch] = useState('');
    const [lastSaleId, setLastSaleId] = useState<string | number | null>(p.last_sale_id ?? null);
    const [autoPrint, setAutoPrint] = useState(true);
    const [printSaleId, setPrintSaleId] = useState<string | number | null>(null);
    const receiptFrame = useRef<HTMLIFrameElement>(null);
    const submittingSale = useRef(false);
    const saleRequest = useRef<{ snapshot: string; key: string; saleAt: string } | null>(null);
    const lastSale = p.sales?.find((sale) => s(sale.id) === s(lastSaleId));
    const dirty = lines.length > 0 || customer !== '' || payment !== '' || payment2 !== '';
    useUnsavedChanges(dirty);

    useEffect(() => {
        const afterPrint = (event: MessageEvent) => {
            if (event.origin === window.location.origin && event.source === receiptFrame.current?.contentWindow && event.data?.type === 'fabric-receipt-printed') {
                barcodeInput.current?.focus();
            }
        };
        window.addEventListener('message', afterPrint);
        return () => window.removeEventListener('message', afterPrint);
    }, []);

    useEffect(() => {
        if (!account && p.accounts?.length > 0) {
            const first = s(p.accounts.find((a) => bool(a.active))?.id) || s(p.accounts[0]?.id);
            if (first) setAccount(first);
        }
    }, [p.accounts, account]);

    const currency = s(p.business.currency) || 'PKR';
    const rollById = useMemo(() => new Map([...p.rolls, ...scannedRolls].map((roll) => [s(roll.id), roll])), [p.rolls, scannedRolls]);
    const estimated = lines.reduce((sum, line) => {
        const qv = Number(line.quantity || 0);
        const price = Number(line.unit_price || 0);
        const discount = Number(line.manual_discount || 0);
        return sum + Math.max(0, Math.round((qv * price - discount) * 100));
    }, 0);

    const filteredRolls = useMemo(() => {
        const term = rollSearch.toLowerCase().trim();
        if (!term) return sellable.slice(0, 50);
        return sellable.filter((r) =>
            s(r.product_name).toLowerCase().includes(term) ||
            s(r.color_name).toLowerCase().includes(term) ||
            s(r.roll_number).toLowerCase().includes(term) ||
            s(r.barcode).toLowerCase().includes(term)
        ).slice(0, 50);
    }, [sellable, rollSearch]);

    const addRoll = (roll: Row) => {
        if (submittingSale.current) return;
        const rollIdStr = s(roll.id);
        const maxAvail = n(roll.available_qty_milli) / 1000;
        if (maxAvail <= 0) return;
        setScannedRolls((current) => [...current.filter((item) => s(item.id) !== rollIdStr), roll]);
        setLines((current) => {
            if (current.some((line) => line.roll_id === rollIdStr)) {
                return current.map((item) => item.roll_id === rollIdStr
                    ? { ...item, quantity: String(Number(Math.min(maxAvail, Number(item.quantity || 0) + 1).toFixed(3))) }
                    : item);
            }
            return [...current, {
                roll_id: rollIdStr,
                quantity: String(Math.min(1, maxAvail)),
                unit_price: (n(roll.selling_price) / 100).toFixed(2),
                manual_discount: '0.00'
            }];
        });
    };

    const lookupBarcode = async (code: string) => {
        setScanError('');
        setUnstockedProduct(null);
        try {
            const response = await fetch(`/fabric/barcode/${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' } });
            const result = await response.json() as { type: string; record: Row; rolls?: Row[]; has_rolls?: boolean; message?: string };
            if (!response.ok) throw new Error(result.message || 'No matching roll or product found.');
            if (result.type === 'roll') addRoll(result.record);
            else if (result.rolls?.length) addRoll(result.rolls[0]);
            else {
                if (result.has_rolls === false) {
                    setUnstockedProduct(result.record);
                    setScanError(`${s(result.record.name)} is saved in the catalogue, but no stock quantity has been recorded. ${p.role === 'cashier' ? 'Ask a manager to add opening stock or receive purchased rolls.' : 'Add the quantity you already have through Opening stock, or receive purchased rolls in Purchases.'}`);
                } else {
                    setScanError(`${s(result.record.name)} has no stock available for sale. Check Inventory for sold-out, reserved, damaged or inactive rolls.`);
                }
                return;
            }
        } catch (error) { setScanError(`${code}: ${error instanceof Error ? error.message : 'Barcode lookup failed.'}`); }
    };

    const scan = () => {
        const code = barcodeValue.current.trim();
        if (!code || submittingSale.current) return;
        barcodeValue.current = '';
        setBarcode('');
        barcodeInput.current?.focus();
        pendingScans.current++;
        setScanning(true);
        scanQueue.current = scanQueue.current.then(() => lookupBarcode(code)).finally(() => {
            pendingScans.current--;
            if (pendingScans.current === 0) setScanning(false);
        });
    };

    const submit = () => {
        if (submittingSale.current || pendingScans.current > 0) return;
        setErrors({});

        if (lines.length === 0) {
            setErrors({ lines: 'Please add at least one roll to the sale.' });
            return;
        }

        for (let i = 0; i < lines.length; i++) {
            const line = lines[i];
            const roll = rollById.get(line.roll_id);
            const q = Number(line.quantity);
            if (!line.quantity || isNaN(q) || q <= 0) {
                setErrors({ [`lines.${i}.quantity`]: `Please enter a valid quantity for ${roll?.product_name ?? 'item'}.` });
                return;
            }
            const maxAvail = n(roll?.available_qty_milli) / 1000;
            if (maxAvail > 0 && q > maxAvail) {
                setErrors({ [`lines.${i}.quantity`]: `Quantity (${q}) exceeds available stock (${maxAvail}) for roll ${roll?.roll_number ?? ''}.` });
                return;
            }
        }

        const effectiveAccount = account || s(p.accounts.find((a) => bool(a.active))?.id) || s(p.accounts[0]?.id);
        if (!credit && !effectiveAccount) {
            setErrors({ account: 'Please select a payment account for cash/bank collection.' });
            return;
        }
        if (credit && !customer) {
            setErrors({ customer: 'Customer selection is required when authorizing a credit sale.' });
            return;
        }

        const estTotal = estimated / 100;

        let actualP1 = '';
        let actualP2 = '';
        if (credit) {
            actualP1 = payment !== '' && payment !== '0.00' ? payment : '';
            actualP2 = payment2 !== '' ? payment2 : '';
        } else {
            if (payment === '' && payment2 === '') {
                actualP1 = estTotal.toFixed(2);
            } else if (payment2 === '') {
                actualP1 = payment;
            } else {
                actualP1 = payment;
                actualP2 = payment2;
            }
        }

        const payload = {
            customer_id: customer || null,
            credit_authorized: credit,
            manual_discount_reason: reason,
            lines,
            payments: [
                { account_id: effectiveAccount, amount: actualP1, external_reference: '' },
                { account_id: account2, amount: actualP2, external_reference: '' }
            ].filter((row) => row.amount !== '' && Number(row.amount) !== 0),
        };
        const snapshot = JSON.stringify(payload);
        if (saleRequest.current?.snapshot !== snapshot) {
            saleRequest.current = { snapshot, key: crypto.randomUUID(), saleAt: localInput(s(p.business.timezone) || 'UTC') };
        }
        submittingSale.current = true;
        setProcessing(true);
        allowNextUnsavedNavigation();
        router.post('/fabric/action/sale', { ...payload, idempotency_key: saleRequest.current.key, sale_at: saleRequest.current.saleAt }, {
            preserveScroll: true,
            onSuccess: (page) => {
                setLines([]);
                setCustomer('');
                setPayment('');
                setAccount2('');
                setPayment2('');
                setCredit(false);
                setReason('');
                setScannedRolls([]);
                setScanError('');
                setUnstockedProduct(null);
                saleRequest.current = null;
                const id = page?.props?.last_sale_id;
                if (id) {
                    setLastSaleId(id as string | number);
                    if (autoPrint) setPrintSaleId(id as string | number);
                }
                barcodeInput.current?.focus();
            },
            onError: setErrors,
            onFinish: () => {
                submittingSale.current = false;
                setProcessing(false);
                barcodeInput.current?.focus();
            }
        });
    };

    const tenderedTotal = (payment !== '' ? Number(payment) : 0) + (payment2 !== '' ? Number(payment2) : 0);
    const estTotalMajor = estimated / 100;
    const changeReturn = tenderedTotal > estTotalMajor && estimated > 0 ? tenderedTotal - estTotalMajor : 0;

    return <div className="fabric-grid pos-grid">
        <SectionCard title="Scan & add fabric" aside={<span className="fabric-pill"><Barcode size={14} /> exact roll tracking</span>}>
            <div className="fabric-scan">
                <input ref={barcodeInput} autoFocus disabled={processing} aria-label="Barcode or search" aria-describedby="scanner-help" autoComplete="off" value={barcode} onChange={(e) => { barcodeValue.current = e.target.value; setBarcode(e.target.value); }} onKeyDown={(e) => { if ((e.key === 'Enter' || e.key === 'Tab') && barcodeValue.current.trim()) { e.preventDefault(); scan(); } }} placeholder="Scan barcode, roll #, or product name" />
                <Button disabled={processing} onClick={scan}>Add</Button>
            </div>
            <p id="scanner-help"><small>USB scanner: use Enter or Tab after each barcode. Click this field to resume scanning.</small></p>
            {scanning && <p role="status">Adding scanned items…</p>}
            {scanError && <div className="fabric-error compact">{scanError}</div>}
            {unstockedProduct && p.role !== 'cashier' && bool(unstockedProduct.active) && <Expand key={s(unstockedProduct.id)} title="Add opening stock">
                <OpeningStockForm p={p} productId={s(unstockedProduct.id)} onRecorded={() => {
                    setScanError('');
                    setUnstockedProduct(null);
                }} />
            </Expand>}
            <div style={{ marginTop: '10px' }}>
                <input className="pos-search-input" placeholder="🔍 Quick filter rolls by product, color, or roll #..." value={rollSearch} onChange={(e) => setRollSearch(e.target.value)} />
            </div>
            <div className="fabric-stock-picker">
                {filteredRolls.length === 0 ? (
                    <div className="fabric-empty" style={{ gridColumn: '1 / -1', padding: '16px' }}>
                        <small>{sellable.length === 0 ? 'No sellable rolls in stock. Receive goods in Purchases or add Opening Stock.' : 'No rolls match your search term.'}</small>
                    </div>
                ) : (
                    filteredRolls.map((roll) => (
                        <button type="button" key={s(roll.id)} onClick={() => addRoll(roll)}>
                            <span>{s(roll.product_name)} · {s(roll.color_name)}</span>
                            <strong>{s(roll.roll_number)}</strong>
                            <small>{qty(roll.available_qty_milli, s(roll.unit))} available</small>
                        </button>
                    ))
                )}
            </div>
        </SectionCard>
        <SectionCard title="Current invoice" aside={<Expand title="Quick customer"><QuickCustomerForm /></Expand>}>
            {lastSaleId && (
                <div className="pos-success-banner">
                    <span>✅ Invoice #{s(lastSaleId)} posted successfully!</span>
                    {lastSale && <span>Total: {money(lastSale.total, currency)}{lastSale.tendered != null && <> · Change: {money(Math.max(0, n(lastSale.tendered) - n(lastSale.paid)), currency)}</>}</span>}
                    <div className="pos-success-links">
                        <a href={`/fabric/document/thermal/${s(lastSaleId)}`} target="_blank" rel="noreferrer">
                            <Printer size={13} /> Print Thermal
                        </a>
                        <a href={`/fabric/document/invoice/${s(lastSaleId)}`} target="_blank" rel="noreferrer">
                            <FileText size={13} /> View Invoice
                        </a>
                        <button type="button" onClick={() => setLastSaleId(null)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#09634d', fontWeight: 'bold' }}>✕</button>
                    </div>
                </div>
            )}
            <div className="fabric-form-grid two">
                <Field label="Customer">
                    <Select value={customer} onChange={setCustomer} rows={options(p.customers, (r) => `${s(r.name)} · ${s(r.phone)}`)} placeholder="Walk-in (fully paid)" />
                </Field>
            </div>
            {lines.length === 0 ? (
                <div className="fabric-empty"><ShoppingCart /><strong>Scan or choose a roll to start.</strong></div>
            ) : (
                <div>
                    <div className="pos-line-header">
                        <span>Fabric & Roll</span>
                        <span style={{ textAlign: 'center' }}>Quantity</span>
                        <span>Unit Price</span>
                        <span>Discount</span>
                        <span style={{ textAlign: 'right' }}>Subtotal</span>
                        <span></span>
                    </div>
                    {lines.map((line, index) => {
                        const roll = rollById.get(line.roll_id) ?? {};
                        const maxAvail = n(roll.available_qty_milli) / 1000;
                        const lineSubtotal = Math.max(0, (Number(line.quantity || 0) * Number(line.unit_price || 0)) - Number(line.manual_discount || 0));
                        return (
                            <div className="pos-line" key={line.roll_id}>
                                <div>
                                    <strong>{s(roll.product_name)}</strong>
                                    <small>{s(roll.color_name)} · {s(roll.roll_number)} · max {qty(roll.available_qty_milli, s(roll.unit))}</small>
                                    <button
                                        type="button"
                                        className="pos-quick-btn"
                                        style={{ fontSize: '9px', padding: '1px 6px', marginTop: '2px' }}
                                        onClick={() => setLines((current) => current.map((item, i) => i === index ? { ...item, quantity: String(maxAvail) } : item))}
                                    >
                                        Full Roll ({qty(roll.available_qty_milli, s(roll.unit))})
                                    </button>
                                </div>
                                <div className="pos-qty-wrap">
                                    <button
                                        type="button"
                                        className="pos-qty-step-btn"
                                        title="Decrease quantity"
                                        onClick={() => {
                                            const cur = Number(line.quantity || 0);
                                            const step = s(roll.unit) === 'piece' ? 1 : 1;
                                            const next = Math.max(s(roll.unit) === 'piece' ? 1 : 0.1, cur - step);
                                            setLines((current) => current.map((item, i) => i === index ? { ...item, quantity: String(Number(next.toFixed(3))) } : item));
                                        }}
                                    >
                                        -
                                    </button>
                                    <input
                                        aria-label="Quantity"
                                        placeholder={s(roll.unit) === 'piece' ? 'Pcs' : 'Meters'}
                                        value={line.quantity}
                                        onChange={(e) => setLines((current) => current.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))}
                                    />
                                    <button
                                        type="button"
                                        className="pos-qty-step-btn"
                                        title="Increase quantity"
                                        onClick={() => {
                                            const cur = Number(line.quantity || 0);
                                            const step = s(roll.unit) === 'piece' ? 1 : 1;
                                            const next = Math.min(maxAvail > 0 ? maxAvail : 9999, cur + step);
                                            setLines((current) => current.map((item, i) => i === index ? { ...item, quantity: String(Number(next.toFixed(3))) } : item));
                                        }}
                                    >
                                        +
                                    </button>
                                </div>
                                <input
                                    aria-label="Unit price"
                                    value={line.unit_price}
                                    onChange={(e) => setLines((current) => current.map((item, i) => i === index ? { ...item, unit_price: e.target.value } : item))}
                                />
                                <input
                                    aria-label="Manual discount"
                                    placeholder="0.00"
                                    value={line.manual_discount}
                                    onChange={(e) => setLines((current) => current.map((item, i) => i === index ? { ...item, manual_discount: e.target.value } : item))}
                                />
                                <span className="pos-line-total">{money(Math.round(lineSubtotal * 100), currency)}</span>
                                <button type="button" aria-label="Remove line" onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                                    <X size={16} />
                                </button>
                            </div>
                        );
                    })}
                </div>
            )}
            <div className="fabric-form-grid two">
                <Field label="Payment account 1">
                    <Select value={account} onChange={setAccount} rows={options(p.accounts.filter((a) => bool(a.active)), (r) => `${s(r.name)} · ${s(r.kind)}`)} />
                </Field>
                <Field label="Paid on account 1">
                    <input value={payment} onChange={(e) => setPayment(e.target.value)} inputMode="decimal" placeholder={lines.length > 0 && !credit ? (estimated / 100).toFixed(2) : '0.00'} />
                </Field>
                <Field label="Payment account 2 (optional)">
                    <Select value={account2} onChange={setAccount2} rows={options(p.accounts.filter((a) => bool(a.active) && s(a.id) !== account), (r) => `${s(r.name)} · ${s(r.kind)}`)} />
                </Field>
                <Field label="Paid on account 2">
                    <input value={payment2} onChange={(e) => setPayment2(e.target.value)} inputMode="decimal" placeholder="0.00" />
                </Field>
            </div>
            {changeReturn > 0 && !credit && (
                <div className="pos-change-banner">
                    <span>💵 Tendered: {money(Math.round(tenderedTotal * 100), currency)}</span>
                    <strong>Change to return: {money(Math.round(changeReturn * 100), currency)}</strong>
                </div>
            )}
            {lines.length > 0 && (
                <div className="pos-quick-actions">
                    <button type="button" className="pos-quick-btn" onClick={() => { setPayment((estimated / 100).toFixed(2)); setPayment2(''); setCredit(false); }}>
                        💵 Exact: {money(estimated, currency)}
                    </button>
                    {estTotalMajor < 500 && (
                        <button type="button" className="pos-quick-btn" onClick={() => { setPayment('500.00'); setPayment2(''); setCredit(false); }}>
                            💵 500
                        </button>
                    )}
                    {estTotalMajor < 1000 && (
                        <button type="button" className="pos-quick-btn" onClick={() => { setPayment('1000.00'); setPayment2(''); setCredit(false); }}>
                            💵 1,000
                        </button>
                    )}
                    {estTotalMajor < 5000 && (
                        <button type="button" className="pos-quick-btn" onClick={() => { setPayment('5000.00'); setPayment2(''); setCredit(false); }}>
                            💵 5,000
                        </button>
                    )}
                    {customer && p.role !== 'cashier' && (
                        <button type="button" className="pos-quick-btn" onClick={() => { setPayment('0.00'); setPayment2(''); setCredit(true); }}>
                            📋 100% Credit
                        </button>
                    )}
                </div>
            )}
            {p.role !== 'cashier' ? (
                <label className="fabric-check" style={{ marginTop: '8px' }}>
                    <input type="checkbox" checked={credit} onChange={(e) => setCredit(e.target.checked)} /> Authorize credit balance (owner/manager only)
                </label>
            ) : null}
            <Field label="Manual discount reason">
                <textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Required when manual discount is used" />
            </Field>
            <ErrorSummary errors={errors} />
            <div className="pos-total">
                <span>Estimated before automatic promotions</span>
                <strong>{money(estimated, currency)}</strong>
            </div>
            <label className="fabric-check"><input type="checkbox" checked={autoPrint} onChange={(e) => setAutoPrint(e.target.checked)} /> Print receipt after sale</label>
            <Button onClick={submit} disabled={processing || scanning || lines.length === 0}>
                {processing ? 'Posting sale…' : `Post sale (${money(estimated, currency)})`}
            </Button>
        </SectionCard>
        {printSaleId && <iframe ref={receiptFrame} key={printSaleId} title="Receipt print" src={`/fabric/document/thermal/${s(printSaleId)}?autoprint=1`} style={{ position: 'fixed', left: '-10000px', top: 0, width: `${n(p.fabric_settings.thermal_width_mm) || 80}mm`, height: '1px', border: 0 }} />}
        <SectionCard title="Recent invoices">
            <Table rows={p.sales ?? []} columns={[
                { label: 'Invoice', value: (r) => <Link href={`/fabric/document/invoice/${s(r.id)}`}>{s(r.reference)}</Link> },
                { label: 'Customer', value: (r) => s(r.customer_name) || 'Walk-in' },
                { label: 'Total', value: (r) => money(r.total, currency) },
                { label: 'Paid at sale', value: (r) => money(r.paid, currency) },
                { label: 'Balance', value: (r) => money(r.current_balance ?? r.balance, currency) },
                { label: 'Print', value: (r) => <span className="fabric-actions"><a href={`/fabric/document/thermal/${s(r.id)}`} target="_blank" rel="noreferrer">Thermal</a></span> }
            ]} />
        </SectionCard>
    </div>;
}

function PreviousDebtForm({ p, partyType }: { p: Props; partyType: 'vendor' | 'customer' }) {
    const vendor = partyType === 'vendor';
    const a = useAction({ party_type: partyType, party_id: '', kind: vendor ? 'payable' : 'receivable', amount: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), reason: '', idempotency_key: crypto.randomUUID() }, '/fabric/action/opening-balance', undefined,
        (current) => ({ ...current, amount: '', reason: '' }));
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}>
        <p className="fabric-hint">{vendor ? 'Enter money you already owe this vendor.' : 'Enter money this customer already owes you.'} Use this for previous udhaar that has no bill in this system. Stock and cash remain unchanged. Do not enter an existing purchase or sale again.</p>
        <Field label={vendor ? 'Vendor' : 'Customer'}><Select value={a.data.party_id} onChange={(v) => a.setData({ ...a.data, party_id: v, amount: '', reason: '' })} rows={options(vendor ? p.vendors.filter((r) => bool(r.active)) : p.customers, (r) => s(vendor ? r.shop_name : r.name))} /></Field>
        <Field label={vendor ? 'Previous payable amount' : 'Previous receivable amount'}><input type="number" min="0.01" step="0.01" required value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /></Field>
        <Field label="Balance date"><input type="datetime-local" required value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field>
        <Field label="Reason / old reference"><textarea required maxLength={2000} value={a.data.reason} onChange={(e) => a.setData({ ...a.data, reason: e.target.value })} /></Field>
        <ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing || !a.data.party_id}>{a.processing ? 'Saving…' : 'Save previous udhaar'}</Button>
    </form>;
}

function PreviousDebtAdvanceForm({ p, partyType }: { p: Props; partyType: 'vendor' | 'customer' }) {
    const vendor = partyType === 'vendor';
    const a = useAction({ party_id: '', opening_balance_id: '', source_type: '', source_id: '', amount: '', idempotency_key: crypto.randomUUID() }, '/fabric/action/advance-to-previous-debt', undefined,
        (current) => ({ ...current, opening_balance_id: '', source_type: '', source_id: '', amount: '' }));
    const debts = (p.opening_balances ?? []).filter((r) => s(r.party_id) === a.data.party_id && s(r.kind) === (vendor ? 'payable' : 'receivable') && n(r.remaining) > 0);
    const sources = [
        ...(vendor ? p.vendor_advances ?? [] : p.customer_advances ?? []).filter((r) => s(vendor ? r.vendor_id : r.customer_id) === a.data.party_id).map((r) => ({ ...r, source_type: vendor ? 'vendor_payment' : 'customer_receipt' } as Row)),
        ...(p.opening_balances ?? []).filter((r) => s(r.party_id) === a.data.party_id && r.kind === 'advance' && n(r.remaining) > 0).map((r) => ({ ...r, source_type: 'opening_advance', reference: `OPEN-${s(r.id)}`, available: r.remaining } as Row)),
    ];
    const debt = debts.find((r) => s(r.id) === a.data.opening_balance_id);
    const source = sources.find((r) => s(r.id) === a.data.source_id && r.source_type === a.data.source_type);
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}>
        <p className="fabric-hint">Use an existing advance to reduce previous udhaar. This adjustment does not record a second cash payment. For a purchase bill or sale invoice, use {vendor ? 'Advance / credit' : 'Apply customer advance'}.</p>
        <Field label={vendor ? 'Vendor' : 'Customer'}><Select value={a.data.party_id} onChange={(v) => a.setData({ ...a.data, party_id: v, opening_balance_id: '', source_id: '', source_type: '', amount: '' })} rows={options(vendor ? p.vendors : p.customers, (r) => s(vendor ? r.shop_name : r.name))} /></Field>
        <Field label="Previous udhaar entry"><Select value={a.data.opening_balance_id} onChange={(v) => a.setData({ ...a.data, opening_balance_id: v, amount: '' })} rows={options(debts, (r) => `OPEN-${s(r.id)} · ${s(r.reason)} · due ${money(r.remaining, s(p.business.currency))}`)} /></Field>
        <Field label="Existing advance"><Select value={a.data.source_id ? `${a.data.source_type}:${a.data.source_id}` : ''} onChange={(v) => {
            const [type = '', id = ''] = v.split(':');
            a.setData({ ...a.data, source_type: type, source_id: id, amount: '' });
        }} rows={sources.map((r) => ({ value: `${s(r.source_type)}:${s(r.id)}`, label: `${s(r.reference)} · available ${money(r.available, s(p.business.currency))}` }))} /></Field>
        {debt && source && <p className="fabric-hint">Available to adjust: {money(Math.min(n(debt.remaining), n(source.available)), s(p.business.currency))}</p>}
        <Field label="Amount to adjust"><input type="number" min="0.01" step="0.01" required value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /></Field>
        <ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing || !debt || !source}>{a.processing ? 'Saving…' : 'Adjust existing advance'}</Button>
    </form>;
}

function PreviousDebtSection({ p, partyType }: { p: Props; partyType: 'vendor' | 'customer' }) {
    const vendor = partyType === 'vendor';
    const parties = vendor ? p.vendors : p.customers;
    return <SectionCard title="Previous udhaar">
        <p className="fabric-hint">{vendor ? 'Payable is money you owe the vendor. Advance is money already paid to the vendor. New credit purchases become payable when you post their purchase bills.' : 'Receivable is money a customer owes you. Advance is money already received. For a new credit sale, select the customer in POS and authorize the unpaid balance.'}</p>
        <div className="fabric-toolbar">
            {p.role === 'owner' && <Expand title="Add previous udhaar"><PreviousDebtForm p={p} partyType={partyType} /></Expand>}
            {p.role !== 'cashier' && <Expand title="Adjust advance against udhaar"><PreviousDebtAdvanceForm p={p} partyType={partyType} /></Expand>}
            <Link className="fabric-button secondary" href={vendor ? '/fabric/purchases' : '/fabric/pos'}>{vendor ? 'New purchase / credit purchase' : 'New sale / credit sale'}</Link>
        </div>
        <Table rows={(p.opening_balances ?? []).filter((r) => r.kind === (vendor ? 'payable' : 'receivable'))} columns={[
            { label: vendor ? 'Vendor' : 'Customer', value: (r) => { const party = parties.find((x) => s(x.id) === s(r.party_id)); return s(vendor ? party?.shop_name : party?.name) || `#${s(r.party_id)}`; } },
            { label: 'Reference', value: (r) => `OPEN-${s(r.id)}` },
            { label: 'Original udhaar', value: (r) => money(r.amount, s(p.business.currency)) },
            { label: 'Settled', value: (r) => money(n(r.amount) - n(r.remaining), s(p.business.currency)) },
            { label: 'Remaining', value: (r) => money(r.remaining, s(p.business.currency)) },
            { label: 'Date', value: (r) => localInput(s(p.business.timezone) || 'UTC', s(r.effective_at)).replace('T', ' ') },
            { label: 'Reason', value: (r) => s(r.reason) },
        ]} />
    </SectionCard>;
}

function CustomerReceiptForm({ p, customerId = '' }: { p: Props; customerId?: string }) {
    const accounts = p.accounts.filter((r) => bool(r.active));
    const a = useAction({ customer_id: customerId, collection_mode: 'outstanding', account_id: s((accounts.find((r) => r.kind === 'cash') ?? accounts[0])?.id), amount: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), sale_id: '', notes: '', idempotency_key: crypto.randomUUID() }, '/fabric/action/customer-receipt', undefined,
        (current) => ({ ...current, sale_id: '', amount: '', notes: '' }));
    const selected = (p.customer_accounts ?? []).find((r) => s(r.id) === a.data.customer_id);
    const balance = selected?.fabric as Row | undefined;
    const sales = (p.customer_open_sales ?? []).filter((r) => s(r.customer_id) === a.data.customer_id);
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}>
        <Field label="Customer"><Select value={a.data.customer_id} onChange={(v) => a.setData({ ...a.data, customer_id: v, sale_id: '', amount: '' })} rows={options(p.customers, (r) => s(r.name))} /></Field>
        {selected && <p className="fabric-hint" role="status">Outstanding: {money(balance?.outstanding, s(p.business.currency))} · Advance: {money(balance?.advance, s(p.business.currency))}</p>}
        <Field label="Collection purpose"><Select value={a.data.collection_mode} onChange={(v) => a.setData({ ...a.data, collection_mode: v, sale_id: '', amount: '' })} rows={[
            { value: 'outstanding', label: 'Collect outstanding / udhaar' }, { value: 'invoice', label: 'Collect a specific invoice' }, { value: 'advance', label: 'Advance collection' },
        ]} /></Field>
        <p className="fabric-hint">{a.data.collection_mode === 'advance' ? 'Money received stays as an advance until you apply it to an invoice or previous udhaar.' : a.data.collection_mode === 'invoice' ? 'Collection settles the chosen invoice. Extra money remains as an advance.' : 'Collection settles previous udhaar first, then the oldest unpaid invoices up to the collection date. Extra money stays as an advance.'}</p>
        {a.data.collection_mode === 'invoice' && <Field label="Outstanding invoice"><Select value={a.data.sale_id} onChange={(v) => { const sale = sales.find((r) => s(r.id) === v); a.setData({ ...a.data, sale_id: v, amount: sale ? (n(sale.outstanding) / 100).toFixed(2) : '' }); }} rows={options(sales, (r) => `${s(r.reference)} · due ${money(r.outstanding, s(p.business.currency))}`)} /></Field>}
        <div className="fabric-form-grid two">
            <Field label="Amount"><input type="number" min="0.01" step="0.01" required value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /></Field>
            <Field label="Account"><Select value={a.data.account_id} onChange={(v) => a.setData({ ...a.data, account_id: v })} rows={options(accounts, (r) => s(r.name))} /></Field>
            <Field label="Date"><input type="datetime-local" required value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field>
            <Field label="Note"><input maxLength={2000} value={a.data.notes} onChange={(e) => a.setData({ ...a.data, notes: e.target.value })} /></Field>
        </div>
        <ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing || !a.data.customer_id || !a.data.account_id || (a.data.collection_mode === 'invoice' && !a.data.sale_id)}>{a.processing ? 'Saving…' : 'Record collection'}</Button>
    </form>;
}

function CustomerAdvanceApplyForm({ p }: { p: Props }) {
    const a = useAction({ receipt_id: '', sale_id: '', amount: '' }, '/fabric/action/customer-advance-apply', () => router.reload());
    const selected = (p.customer_advances ?? []).find((r) => s(r.id) === a.data.receipt_id);
    return <form className="fabric-form" onSubmit={a.submit}><Field label="Available receipt advance"><Select value={a.data.receipt_id} onChange={(v) => a.setData({ ...a.data, receipt_id: v, sale_id: '' })} rows={options(p.customer_advances ?? [], (r) => `${s(r.reference)} · ${money(r.available, s(p.business.currency))}`)} /></Field><Field label="Customer invoice"><Select value={a.data.sale_id} onChange={(v) => a.setData({ ...a.data, sale_id: v })} rows={options((p.customer_open_sales ?? []).filter((r) => !selected || s(r.customer_id) === s(selected.customer_id)), (r) => `${s(r.reference)} · due ${money(r.outstanding, s(p.business.currency))}`)} /></Field><Field label="Amount to apply"><input value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /></Field><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Apply advance</Button></form>;
}

function CustomerCreditActionForm({ p }: { p: Props }) {
    const [mode, setMode] = useState('apply');
    const [credit, setCredit] = useState(''); const [sale, setSale] = useState(''); const [account, setAccount] = useState(s(p.accounts[0]?.id)); const [amount, setAmount] = useState(''); const [busy, setBusy] = useState(false); const [errors, setErrors] = useState<Errors>({});
    const selected = (p.customer_credits ?? []).find((r) => s(r.id) === credit);
    const submit = (e: FormEvent) => { e.preventDefault(); if (busy) return; setBusy(true); setErrors({}); const url = mode === 'apply' ? '/fabric/action/customer-credit-apply' : '/fabric/action/customer-credit-refund'; const payload = mode === 'apply' ? { credit_id: credit, sale_id: sale, amount } : { credit_id: credit, account_id: account, amount, effective_at: localInput(s(p.business.timezone) || 'UTC'), reason: 'Customer retained credit refund', idempotency_key: crypto.randomUUID() }; router.post(url, payload, { preserveScroll: true, onError: setErrors, onSuccess: () => { setAmount(''); router.reload(); }, onFinish: () => setBusy(false) }); };
    return <form className="fabric-form" onSubmit={submit}><Field label="Action"><Select value={mode} onChange={setMode} rows={[{ value: 'apply', label: 'Apply to unpaid invoice' }, { value: 'refund', label: 'Refund through cash / bank' }]} /></Field><Field label="Available return credit"><Select value={credit} onChange={(v) => { setCredit(v); setSale(''); }} rows={options(p.customer_credits ?? [], (r) => `${s(r.reference)} · ${money(r.available, s(p.business.currency))}`)} /></Field>{mode === 'apply' ? <Field label="Customer invoice"><Select value={sale} onChange={setSale} rows={options((p.customer_open_sales ?? []).filter((r) => !selected || s(r.customer_id) === s(selected.customer_id)), (r) => `${s(r.reference)} · due ${money(r.outstanding, s(p.business.currency))}`)} /></Field> : <Field label="Refund account"><Select value={account} onChange={setAccount} rows={options(p.accounts.filter((r) => bool(r.active)), (r) => s(r.name))} /></Field>}<Field label="Amount"><input value={amount} onChange={(e) => setAmount(e.target.value)} /></Field><ErrorSummary errors={errors} /><Button type="submit" disabled={busy}>{busy ? 'Saving…' : mode === 'apply' ? 'Apply credit' : 'Post refund'}</Button></form>;
}

function Customers({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR';
    return <>
        <div className="fabric-toolbar">
            <Expand title="Record later collection"><CustomerReceiptForm p={p} /></Expand>
            <Expand title="Apply customer advance"><CustomerAdvanceApplyForm p={p} /></Expand>
            <Expand title="Use / refund return credit"><CustomerCreditActionForm p={p} /></Expand>
        </div>
        <SectionCard title="Customer fabric accounts"><Table rows={p.customer_accounts ?? []} columns={[
            { label: 'Customer', value: (r) => <strong>{s(r.name)}</strong> },
            { label: 'Phone', value: (r) => s(r.phone) },
            { label: 'Outstanding / receivable', value: (r) => money((r.fabric as Row)?.outstanding, currency) },
            { label: 'Advance', value: (r) => money((r.fabric as Row)?.advance, currency) },
            { label: 'Return credit', value: (r) => money((r.fabric as Row)?.credit, currency) },
            { label: 'Statement', value: (r) => <a href={`/fabric/document/customer-statement/${s(r.id)}`}>Print</a> },
            { label: 'Collect', value: (r) => <Expand title="Collect udhaar"><CustomerReceiptForm p={p} customerId={s(r.id)} /></Expand> },
        ]} /></SectionCard>
        <PreviousDebtSection p={p} partyType="customer" />
        <SectionCard title="Recent collections"><Table rows={p.receipts ?? []} columns={[
            { label: 'Receipt', value: (r) => <a href={`/fabric/document/customer-receipt/${s(r.id)}`}>{s(r.reference)}</a> },
            { label: 'Customer', value: (r) => s(p.customers.find((c) => s(c.id) === s(r.customer_id))?.name) || `#${s(r.customer_id)}` },
            { label: 'Amount', value: (r) => money(r.amount, currency) },
            { label: 'Date', value: (r) => localInput(s(p.business.timezone) || 'UTC', s(r.effective_at)).replace('T', ' ') },
        ]} /></SectionCard>
    </>;
}

function VendorForm({ p }: { p: Props }) {
    const a = useAction({ shop_name: '', owner_name: '', phone: '', email: '', address: '', notes: '', active: true }, '/fabric/action/vendor-save', () => router.reload());
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Shop name"><input value={a.data.shop_name} onChange={(e) => a.setData({ ...a.data, shop_name: e.target.value })} /></Field><Field label="Owner name"><input value={a.data.owner_name} onChange={(e) => a.setData({ ...a.data, owner_name: e.target.value })} /></Field><Field label="Phone"><input value={a.data.phone} onChange={(e) => a.setData({ ...a.data, phone: e.target.value })} /></Field><Field label="Email"><input type="email" value={a.data.email} onChange={(e) => a.setData({ ...a.data, email: e.target.value })} /></Field></div><Field label="Address"><textarea value={a.data.address} onChange={(e) => a.setData({ ...a.data, address: e.target.value })} /></Field><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Save vendor</Button></form>;
}

function VendorExistingForm({ row }: { row: Row }) {
    const a = useAction({ shop_name: s(row.shop_name), owner_name: s(row.owner_name), phone: s(row.phone), email: s(row.email), address: s(row.address), notes: s(row.notes), active: bool(row.active) }, `/fabric/action/vendor-save/${s(row.id)}`, () => router.reload());
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Shop name"><input value={a.data.shop_name} onChange={(e) => a.setData({ ...a.data, shop_name: e.target.value })} /></Field><Field label="Owner name"><input value={a.data.owner_name} onChange={(e) => a.setData({ ...a.data, owner_name: e.target.value })} /></Field><Field label="Phone"><input value={a.data.phone} onChange={(e) => a.setData({ ...a.data, phone: e.target.value })} /></Field><Field label="Email"><input type="email" value={a.data.email} onChange={(e) => a.setData({ ...a.data, email: e.target.value })} /></Field></div><Field label="Address"><textarea value={a.data.address} onChange={(e) => a.setData({ ...a.data, address: e.target.value })} /></Field><Field label="Internal note"><textarea value={a.data.notes} onChange={(e) => a.setData({ ...a.data, notes: e.target.value })} /></Field><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>{a.processing ? 'Saving…' : 'Save vendor changes'}</Button></form>;
}
function VendorEditor({ p }: { p: Props }) {
    const [selected, setSelected] = useState('');
    const row = p.vendors.find((r) => s(r.id) === selected);
    return <div className="fabric-form"><Field label="Vendor to edit"><Select value={selected} onChange={setSelected} rows={options(p.vendors, (r) => `${s(r.shop_name)}${bool(r.active) ? '' : ' · archived'}`)} /></Field>{row && <VendorExistingForm key={selected} row={row} />}</div>;
}

function VendorPaymentForm({ p, vendorId = '' }: { p: Props; vendorId?: string }) {
    const currency = s(p.business.currency) || 'PKR';
    const accounts = p.accounts.filter((r) => bool(r.active));
    const a = useAction({
        vendor_id: vendorId, payment_mode: 'outstanding', purchase_bill_id: '',
        account_id: s((accounts.find((r) => s(r.kind) === 'cash') ?? accounts[0])?.id), amount: '', effective_at: localInput(s(p.business.timezone) || 'UTC'),
        notes: '', idempotency_key: crypto.randomUUID(),
    }, '/fabric/action/vendor-payment', undefined, (current) => ({ ...current, amount: '', purchase_bill_id: '', notes: '' }));
    const selected = (p.vendor_accounts ?? []).find((r) => s(r.id) === a.data.vendor_id);
    const account = selected?.account as Row | undefined;
    const bills = (p.vendor_open_bills ?? []).filter((r) => s(r.vendor_id) === a.data.vendor_id);
    const bill = bills.find((r) => s(r.id) === a.data.purchase_bill_id);

    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}>
        <ErrorSummary errors={a.errors} />
        <Field label="Vendor"><Select value={a.data.vendor_id} onChange={(v) => a.setData({ ...a.data, vendor_id: v, purchase_bill_id: '', amount: '' })} rows={options(p.vendors.filter((r) => bool(r.active)), (r) => s(r.shop_name))} /></Field>
        {selected && <div className="fabric-hint" role="status">
            <strong>{s(selected.shop_name)}</strong> · Payable: {money(account?.payable, currency)} · Advance: {money(account?.advance, currency)} · Return credit: {money(account?.credit, currency)}
            {(n(account?.advance) > 0 || n(account?.credit) > 0) && <p>To use money already paid or return credit against a bill, open Advance / credit.</p>}
        </div>}
        <Field label="Payment purpose"><Select value={a.data.payment_mode} onChange={(v) => a.setData({ ...a.data, payment_mode: v, purchase_bill_id: '', amount: '' })} rows={[
            { value: 'outstanding', label: 'Pay outstanding balance' },
            { value: 'bill', label: 'Pay a specific bill' },
            { value: 'advance', label: 'Advance payment' },
        ]} /></Field>
        <p className="fabric-hint">{a.data.payment_mode === 'advance'
            ? 'This payment stays as a vendor advance. You can apply it to a purchase bill later through Advance / credit.'
            : a.data.payment_mode === 'bill'
                ? 'This payment settles the selected bill. Any amount above its unpaid balance stays as a vendor advance.'
                : 'Payment settles opening payable first, then the oldest unpaid bills up to the payment date. Any extra amount stays as a vendor advance.'}</p>
        {selected && a.data.payment_mode === 'outstanding' && n(account?.payable) <= 0 && <p className="fabric-hint">No unpaid balance. Choose Advance payment to pay on account, or post a bill in Purchases first.</p>}
        {a.data.payment_mode === 'bill' && <Field label="Purchase bill"><Select value={a.data.purchase_bill_id} onChange={(v) => {
            const chosen = bills.find((r) => s(r.id) === v);
            a.setData({ ...a.data, purchase_bill_id: v, amount: chosen ? (n(chosen.outstanding) / 100).toFixed(2) : '' });
        }} rows={options(bills, (r) => `${s(r.reference)} · due ${money(r.outstanding, currency)}`)} placeholder={a.data.vendor_id ? 'Select an unpaid bill' : 'Choose a vendor first'} /></Field>}
        <div className="fabric-form-grid two">
            <Field label="Amount"><input type="number" min="0.01" step="0.01" required value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /></Field>
            <Field label="Cash / bank"><Select value={a.data.account_id} onChange={(v) => a.setData({ ...a.data, account_id: v })} rows={options(accounts, (r) => s(r.name))} /></Field>
            <Field label="Date"><input type="datetime-local" required value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field>
            <Field label="Note"><input maxLength={2000} value={a.data.notes} onChange={(e) => a.setData({ ...a.data, notes: e.target.value })} /></Field>
        </div>
        {bill && <p className="fabric-hint">Selected bill unpaid: {money(bill.outstanding, currency)}.</p>}
        <p className="fabric-hint">Record the cash or bank payment you have made to the vendor. The amount is deducted from the selected account in your ledger.</p>
        <Button type="submit" disabled={a.processing || !a.data.vendor_id || !a.data.account_id || (a.data.payment_mode === 'bill' && !a.data.purchase_bill_id)}>{a.processing ? 'Saving…' : 'Record vendor payment'}</Button>
    </form>;
}

function VendorAdvanceCreditForm({ p }: { p: Props }) {
    const [mode, setMode] = useState('advance'); const [source, setSource] = useState(''); const [bill, setBill] = useState(''); const [account, setAccount] = useState(s(p.accounts[0]?.id)); const [amount, setAmount] = useState(''); const [busy, setBusy] = useState(false); const [errors, setErrors] = useState<Errors>({});
    const advance = (p.vendor_advances ?? []).find((r) => s(r.id) === source); const credit = (p.vendor_credits ?? []).find((r) => s(r.id) === source); const selected = mode === 'advance' ? advance : credit;
    const submit = (e: FormEvent) => { e.preventDefault(); if (busy) return; setBusy(true); setErrors({}); let url = '/fabric/action/vendor-advance-apply'; let payload: Payload = { payment_id: source, purchase_bill_id: bill, amount }; if (mode === 'credit') { url = '/fabric/action/vendor-credit-apply'; payload = { credit_id: source, purchase_bill_id: bill, amount }; } if (mode === 'refund') { url = '/fabric/action/vendor-credit-refund'; payload = { credit_id: source, account_id: account, amount, effective_at: localInput(s(p.business.timezone) || 'UTC'), reason: 'Vendor cash refund against retained credit', idempotency_key: crypto.randomUUID() }; } router.post(url, payload, { preserveScroll: true, onError: setErrors, onSuccess: () => { setAmount(''); router.reload(); }, onFinish: () => setBusy(false) }); };
    const sources = mode === 'advance' ? (p.vendor_advances ?? []) : (p.vendor_credits ?? []);
    return <form className="fabric-form" onSubmit={submit}><Field label="Action"><Select value={mode} onChange={(v) => { setMode(v); setSource(''); setBill(''); }} rows={[{ value: 'advance', label: 'Apply vendor payment advance' }, { value: 'credit', label: 'Apply vendor return credit' }, { value: 'refund', label: 'Record vendor cash refund' }]} /></Field><Field label={mode === 'advance' ? 'Available payment advance' : 'Available vendor credit'}><Select value={source} onChange={(v) => { setSource(v); setBill(''); }} rows={options(sources, (r) => `${s(r.reference)} · ${money(r.available, s(p.business.currency))}`)} /></Field>{mode === 'refund' ? <Field label="Receiving account"><Select value={account} onChange={setAccount} rows={options(p.accounts.filter((r) => bool(r.active)), (r) => s(r.name))} /></Field> : <Field label="Purchase bill"><Select value={bill} onChange={setBill} rows={options((p.vendor_open_bills ?? []).filter((r) => !selected || s(r.vendor_id) === s(selected.vendor_id)), (r) => `${s(r.reference)} · due ${money(r.outstanding, s(p.business.currency))}`)} /></Field>}<Field label="Amount"><input value={amount} onChange={(e) => setAmount(e.target.value)} /></Field><ErrorSummary errors={errors} /><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Post action'}</Button></form>;
}

function InstallmentForm({ p }: { p: Props }) {
    const create = useAction({ vendor_id: '', purchase_bill_id: '', cadence: 'monthly', amount: '', first_due_at: localInput(s(p.business.timezone) || 'UTC'), count: '1', notes: '' }, '/fabric/action/installment-create', () => router.reload());
    return <form ref={create.formRef} className="fabric-form" onSubmit={create.submit}><div className="fabric-form-grid two"><Field label="Vendor"><Select value={create.data.vendor_id} onChange={(v) => create.setData({ ...create.data, vendor_id: v, purchase_bill_id: '' })} rows={options(p.vendors.filter((r) => bool(r.active)), (r) => s(r.shop_name))} /></Field><Field label="Purchase bill (optional)"><Select value={create.data.purchase_bill_id} onChange={(v) => create.setData({ ...create.data, purchase_bill_id: v })} rows={options((p.vendor_open_bills ?? []).filter((r) => !create.data.vendor_id || s(r.vendor_id) === create.data.vendor_id), (r) => s(r.reference))} /></Field><Field label="Cadence"><Select value={create.data.cadence} onChange={(v) => create.setData({ ...create.data, cadence: v })} rows={[{ value: 'daily', label: 'Daily' }, { value: 'weekly', label: 'Weekly' }, { value: 'monthly', label: 'Monthly' }]} /></Field><Field label="Each installment"><input value={create.data.amount} onChange={(e) => create.setData({ ...create.data, amount: e.target.value })} /></Field><Field label="First due"><input type="datetime-local" value={create.data.first_due_at} onChange={(e) => create.setData({ ...create.data, first_due_at: e.target.value })} /></Field><Field label="Number of installments"><input type="number" min="1" max="120" value={create.data.count} onChange={(e) => create.setData({ ...create.data, count: e.target.value })} /></Field></div><Field label="Note"><input value={create.data.notes} onChange={(e) => create.setData({ ...create.data, notes: e.target.value })} /></Field><ErrorSummary errors={create.errors} /><Button type="submit" disabled={create.processing}>Create schedule</Button></form>;
}

function InstallmentSettleForm({ p }: { p: Props }) {
    const [installment, setInstallment] = useState(''); const [payment, setPayment] = useState(''); const [busy, setBusy] = useState(false); const [errors, setErrors] = useState<Errors>({}); const row = (p.installments ?? []).find((r) => s(r.id) === installment);
    const submit = (e: FormEvent) => { e.preventDefault(); if (!installment || !payment || busy) return; setBusy(true); setErrors({}); router.post(`/fabric/action/installment-settle/${installment}`, { payment_id: payment }, { preserveScroll: true, onError: setErrors, onSuccess: () => router.reload(), onFinish: () => setBusy(false) }); };
    return <form className="fabric-form" onSubmit={submit}><Field label="Due installment"><Select value={installment} onChange={(v) => { setInstallment(v); setPayment(''); }} rows={options((p.installments ?? []).filter((r) => s(r.status) === 'due'), (r) => `#${s(r.id)} · vendor ${s(r.vendor_id)} · ${money(r.amount, s(p.business.currency))}`)} /></Field><Field label="Recorded vendor payment"><Select value={payment} onChange={setPayment} rows={options((p.vendor_payments ?? []).filter((r) => !row || s(r.vendor_id) === s(row.vendor_id)), (r) => `${s(r.reference)} · ${money(r.amount, s(p.business.currency))}`)} /></Field><ErrorSummary errors={errors} /><Button type="submit" disabled={busy}>Mark installment paid</Button></form>;
}

function Vendors({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR';
    return <>
        <div className="fabric-toolbar">{p.role !== 'cashier' && <>
            <Expand title="New vendor"><VendorForm p={p} /></Expand>
            <Expand title="Edit vendor"><VendorEditor p={p} /></Expand>
            <Expand title="Vendor payment"><VendorPaymentForm p={p} /></Expand>
            <Expand title="Advance / credit"><VendorAdvanceCreditForm p={p} /></Expand>
            <Expand title="Installment schedule"><InstallmentForm p={p} /></Expand>
            <Expand title="Settle installment"><InstallmentSettleForm p={p} /></Expand>
        </>}</div>
        <SectionCard title="Vendor accounts"><Table rows={p.vendor_accounts ?? []} columns={[
            { label: 'Vendor', value: (r) => <strong>{s(r.shop_name)}</strong> },
            { label: 'Owner', value: (r) => s(r.owner_name) },
            { label: 'Payable', value: (r) => money((r.account as Row)?.payable, currency) },
            { label: 'Advance', value: (r) => money((r.account as Row)?.advance, currency) },
            { label: 'Vendor credit', value: (r) => money((r.account as Row)?.credit, currency) },
            { label: 'State', value: (r) => <Status value={bool(r.active) ? 'active' : 'archived'} /> },
            { label: 'Statement', value: (r) => <a href={`/fabric/document/vendor-statement/${s(r.id)}`}>Print</a> },
            { label: 'Action', value: (r) => p.role !== 'cashier' ? <div className="fabric-actions">
                {bool(r.active) && <Expand title="Pay vendor"><VendorPaymentForm p={p} vendorId={s(r.id)} /></Expand>}
                <InlineAction label={bool(r.active) ? 'Archive' : 'Reactivate'} url={`/fabric/action/vendor-toggle/${s(r.id)}`} />
            </div> : '—' },
        ]} /></SectionCard>
        <PreviousDebtSection p={p} partyType="vendor" />
        <SectionCard title="Recent vendor payments"><Table rows={p.vendor_payments ?? []} columns={[
            { label: 'Payment', value: (r) => <strong>{s(r.reference)}</strong> },
            { label: 'Vendor', value: (r) => s(r.vendor_name) },
            { label: 'Date', value: (r) => new Date(`${s(r.effective_at).replace(' ', 'T')}Z`).toLocaleString(undefined, { timeZone: s(p.business.timezone) || 'UTC' }) },
            { label: 'Paid from', value: (r) => s(r.account_name) },
            { label: 'Amount', value: (r) => money(r.amount, currency) },
            { label: 'Applied to payable', value: (r) => money(r.allocated, currency) },
            { label: 'Remaining advance', value: (r) => money(r.available, currency) },
            { label: 'Note', value: (r) => s(r.notes) || '—' },
        ]} /></SectionCard>
        <SectionCard title="Installment reminders"><Table rows={p.installments ?? []} columns={[
            { label: 'Vendor ID', value: (r) => s(r.vendor_id) },
            { label: 'Due', value: (r) => new Date(s(r.due_at)).toLocaleString() },
            { label: 'Amount', value: (r) => money(r.amount, currency) },
            { label: 'Status', value: (r) => <Status value={r.display_status ?? r.status} /> },
            { label: 'Payment', value: (r) => s(r.payment_id) || '—' },
        ]} /></SectionCard>
    </>;
}

type PurchaseLine = { product_id: string; color_id: string; quantity: string; purchase_rate: string; discount: string; additional_cost: string };
function PurchaseForm({ p }: { p: Props }) {
    const [vendor, setVendor] = useState(''); const [supplier, setSupplier] = useState(''); const [date, setDate] = useState(localInput(s(p.business.timezone) || 'UTC')); const [notes, setNotes] = useState(''); const [lines, setLines] = useState<PurchaseLine[]>([{ product_id: '', color_id: '', quantity: '', purchase_rate: '', discount: '0.00', additional_cost: '0.00' }]); const [busy, setBusy] = useState(false); const [errors, setErrors] = useState<Errors>({});
    useUnsavedChanges(vendor !== '' || supplier !== '' || notes !== '' || lines.some((l) => l.product_id || l.quantity));
    const submit = (event: FormEvent) => { event.preventDefault(); if (busy) return; setBusy(true); setErrors({}); allowNextUnsavedNavigation(); router.post('/fabric/action/purchase-create', { vendor_id: vendor, supplier_reference: supplier, bill_date: date, notes, lines }, { preserveScroll: true, onError: setErrors, onSuccess: () => { setVendor(''); setSupplier(''); setNotes(''); setLines([{ product_id: '', color_id: '', quantity: '', purchase_rate: '', discount: '0.00', additional_cost: '0.00' }]); }, onFinish: () => setBusy(false) }); };
    return <form className="fabric-form" onSubmit={submit}><div className="fabric-form-grid two"><Field label="Vendor"><Select value={vendor} onChange={setVendor} rows={options(p.vendors.filter((v) => bool(v.active)), (r) => s(r.shop_name))} /></Field><Field label="Supplier bill reference"><input value={supplier} onChange={(e) => setSupplier(e.target.value)} /></Field><Field label="Bill date"><input type="datetime-local" value={date} onChange={(e) => setDate(e.target.value)} /></Field></div>{lines.map((line, index) => <div className="purchase-line" key={index}><Select value={line.product_id} onChange={(v) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, product_id: v } : r))} rows={options(p.products.filter((x) => bool(x.active)), (r) => `${s(r.name)} · ${s(r.unit)}`)} /><Select value={line.color_id} onChange={(v) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, color_id: v } : r))} rows={options(p.colors.filter((x) => bool(x.active)), (r) => s(r.name))} /><input placeholder="Qty" value={line.quantity} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, quantity: e.target.value } : r))} /><input placeholder="Purchase rate" value={line.purchase_rate} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, purchase_rate: e.target.value } : r))} /><input placeholder="Discount" value={line.discount} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, discount: e.target.value } : r))} /><input placeholder="Extra cost" value={line.additional_cost} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, additional_cost: e.target.value } : r))} /><button type="button" onClick={() => setLines((rows) => rows.filter((_, i) => i !== index))}><X size={16} /></button></div>)}<Button secondary onClick={() => setLines((rows) => [...rows, { product_id: '', color_id: '', quantity: '', purchase_rate: '', discount: '0.00', additional_cost: '0.00' }])}>Add line</Button><Field label="Internal purchase note"><textarea value={notes} onChange={(e) => setNotes(e.target.value)} /></Field><ErrorSummary errors={errors} /><Button type="submit" disabled={busy}>{busy ? 'Saving…' : 'Create purchase bill'}</Button></form>;
}

function PurchaseDraftForm({ p, row }: { p: Props; row: Row }) {
    const initialLines = ((row.lines ?? []) as Row[]).map((line) => ({
        product_id: s(line.product_id), color_id: s(line.color_id), quantity: (n(line.ordered_qty_milli) / 1000).toFixed(3),
        purchase_rate: (n(line.purchase_rate) / 100).toFixed(2), discount: (n(line.discount_amount) / 100).toFixed(2), additional_cost: (n(line.allocated_cost) / 100).toFixed(2),
    }));
    const [vendor, setVendor] = useState(s(row.vendor_id)); const [supplier, setSupplier] = useState(s(row.supplier_reference)); const [date, setDate] = useState(localInput(s(p.business.timezone) || 'UTC', s(row.bill_date))); const [notes, setNotes] = useState(s(row.notes)); const [lines, setLines] = useState<PurchaseLine[]>(initialLines); const [busy, setBusy] = useState(false); const [errors, setErrors] = useState<Errors>({});
    const baseline = useRef(JSON.stringify({ vendor: s(row.vendor_id), supplier: s(row.supplier_reference), date: localInput(s(p.business.timezone) || 'UTC', s(row.bill_date)), notes: s(row.notes), lines: initialLines }));
    const dirty = JSON.stringify({ vendor, supplier, date, notes, lines }) !== baseline.current;
    useUnsavedChanges(dirty);
    const submit = (event: FormEvent) => { event.preventDefault(); if (busy) return; setBusy(true); setErrors({}); allowNextUnsavedNavigation(); router.post(`/fabric/action/purchase-update/${s(row.id)}`, { vendor_id: vendor, supplier_reference: supplier, bill_date: date, notes, lines }, { preserveScroll: true, onError: setErrors, onSuccess: () => router.reload(), onFinish: () => setBusy(false) }); };
    return <form className="fabric-form" onSubmit={submit}><div className="fabric-form-grid two"><Field label="Vendor"><Select value={vendor} onChange={setVendor} rows={options(p.vendors.filter((v) => bool(v.active) || s(v.id) === vendor), (r) => s(r.shop_name))} /></Field><Field label="Supplier bill reference"><input value={supplier} onChange={(e) => setSupplier(e.target.value)} /></Field><Field label="Bill date"><input type="datetime-local" value={date} onChange={(e) => setDate(e.target.value)} /></Field></div>{lines.map((line, index) => <div className="purchase-line" key={index}><Select value={line.product_id} onChange={(v) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, product_id: v } : r))} rows={options(p.products.filter((x) => bool(x.active) || s(x.id) === line.product_id), (r) => `${s(r.name)} · ${s(r.unit)}`)} /><Select value={line.color_id} onChange={(v) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, color_id: v } : r))} rows={options(p.colors.filter((x) => bool(x.active) || s(x.id) === line.color_id), (r) => s(r.name))} /><input placeholder="Qty" value={line.quantity} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, quantity: e.target.value } : r))} /><input placeholder="Purchase rate" value={line.purchase_rate} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, purchase_rate: e.target.value } : r))} /><input placeholder="Discount" value={line.discount} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, discount: e.target.value } : r))} /><input placeholder="Extra cost" value={line.additional_cost} onChange={(e) => setLines((rows) => rows.map((r, i) => i === index ? { ...r, additional_cost: e.target.value } : r))} /><button type="button" onClick={() => setLines((rows) => rows.filter((_, i) => i !== index))}><X size={16} /></button></div>)}<Button secondary onClick={() => setLines((rows) => [...rows, { product_id: '', color_id: '', quantity: '', purchase_rate: '', discount: '0.00', additional_cost: '0.00' }])}>Add line</Button><Field label="Internal purchase note"><textarea value={notes} onChange={(e) => setNotes(e.target.value)} /></Field><p className="fabric-help">Draft totals and lines can be changed only before any roll is received. Posted or received history must be corrected through returns/adjustments.</p><ErrorSummary errors={errors} /><Button type="submit" disabled={busy || !dirty}>{busy ? 'Saving…' : 'Save draft changes'}</Button></form>;
}
function PurchaseEditor({ p }: { p: Props }) {
    const [selected, setSelected] = useState('');
    const drafts = (p.purchase_bills ?? []).filter((row) => s(row.state) === 'draft' && ((row.lines ?? []) as Row[]).every((line) => n(line.received_qty_milli) === 0));
    const row = drafts.find((item) => s(item.id) === selected);
    return <div className="fabric-form"><Field label="Draft bill to edit"><Select value={selected} onChange={setSelected} rows={options(drafts, (r) => `${s(r.reference)} · ${s(r.vendor_name)}`)} /></Field>{row ? <PurchaseDraftForm key={selected} p={p} row={row} /> : <p className="fabric-help">Only unreceived draft bills can be edited.</p>}</div>;
}

function ReceiptForm({ p }: { p: Props }) {
    const a = useAction({ bill: '', line: '', quantity: '', roll: '', barcode: '', price: '', notes: '' }, '/fabric/action/receipt-create', () => router.reload(), (current) => ({ ...current, line: '', quantity: '', roll: '', barcode: '', price: '', notes: '' }));
    const selected = (p.purchase_bills ?? []).find((r) => s(r.id) === a.data.bill);
    const lines = ((selected?.lines ?? []) as Row[]).filter((r) => n(r.received_qty_milli) < n(r.ordered_qty_milli));
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!selected || !a.data.line || a.processing) return;
        a.post({
            purchase_bill_id: a.data.bill,
            vendor_id: s(selected.vendor_id),
            received_at: localInput(s(p.business.timezone) || 'UTC'),
            notes: a.data.notes,
            lines: [{ purchase_line_id: a.data.line, quantity: a.data.quantity, roll_number: a.data.roll, barcode: a.data.barcode, selling_price: a.data.price || undefined }],
        });
    };
    return <form ref={a.formRef} className="fabric-form" onSubmit={submit}><ErrorSummary errors={a.errors} /><div className="fabric-form-grid two"><Field label="Purchase bill"><Select value={a.data.bill} onChange={(v) => a.setData({ ...a.data, bill: v, line: '' })} rows={options((p.purchase_bills ?? []).filter((r) => ['draft', 'posted'].includes(s(r.state))), (r) => `${s(r.reference)} · ${s(r.vendor_name)}`)} /></Field><Field label="Purchase line"><Select value={a.data.line} onChange={(v) => a.setData({ ...a.data, line: v })} rows={options(lines, (r) => `${s(r.product_name)} / ${s(r.color_name)} · pending ${qty(n(r.ordered_qty_milli) - n(r.received_qty_milli), s(r.unit))}`)} /></Field><Field label="Received quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} inputMode="decimal" /></Field><Field label="Roll number (optional)"><input value={a.data.roll} onChange={(e) => a.setData({ ...a.data, roll: e.target.value })} /></Field><Field label="Barcode (optional)" hint="Leave blank to generate a unique stock barcode."><input value={a.data.barcode} onChange={(e) => a.setData({ ...a.data, barcode: e.target.value })} /></Field><Field label="Selling price"><input value={a.data.price} onChange={(e) => a.setData({ ...a.data, price: e.target.value })} inputMode="decimal" /></Field><Field label="Receipt note"><input value={a.data.notes} onChange={(e) => a.setData({ ...a.data, notes: e.target.value })} /></Field></div><Button type="submit" disabled={a.processing || !selected || !a.data.line}>{a.processing ? 'Posting receipt…' : 'Post goods receipt'}</Button></form>;
}

function Purchases({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR';
    return <><div className="fabric-toolbar">{p.role !== 'cashier' && <><Expand title="New purchase"><PurchaseForm p={p} /></Expand><Expand title="Edit draft"><PurchaseEditor p={p} /></Expand><Expand title="Receive rolls"><ReceiptForm p={p} /></Expand></>}</div><SectionCard title="Purchase bills"><Table rows={p.purchase_bills ?? []} columns={[{ label: 'Bill', value: (r) => <strong>{s(r.reference)}</strong> }, { label: 'Vendor', value: (r) => s(r.vendor_name) }, { label: 'State', value: (r) => <Status value={r.state} /> }, { label: 'Total', value: (r) => money(r.total, currency) }, { label: 'Outstanding', value: (r) => money(r.outstanding, currency) }, { label: 'Receiving', value: (r) => { const rows = (r.lines ?? []) as Row[]; const ordered = rows.reduce((sum, x) => sum + n(x.ordered_qty_milli), 0); const received = rows.reduce((sum, x) => sum + n(x.received_qty_milli), 0); return `${(received / 1000).toFixed(3)} / ${(ordered / 1000).toFixed(3)}`; } }, { label: 'Action', value: (r) => s(r.state) === 'draft' && p.role !== 'cashier' ? <InlineAction label="Post bill" url={`/fabric/action/purchase-post/${s(r.id)}`} /> : '—' }]} /></SectionCard></>;
}

function ProductForm({ p }: { p: Props }) {
    const a = useAction({ category_id: s(p.categories[0]?.id), name: '', sku: '', barcode: '', unit: 'meter', default_selling_price: '0.00', low_stock: '5.000', active: true }, '/fabric/action/product-save', () => router.reload());
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}><p className="fabric-help">This saves product details only. To make it available in POS, record its quantity through Opening stock or Purchases → Receive rolls. The low stock threshold is a warning level, not the quantity on hand.</p><div className="fabric-form-grid two"><Field label="Category"><Select value={a.data.category_id} onChange={(v) => a.setData({ ...a.data, category_id: v })} rows={options(p.categories.filter((r) => bool(r.active)), (r) => s(r.name))} /></Field><Field label="Fabric / product name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="SKU"><input value={a.data.sku} onChange={(e) => a.setData({ ...a.data, sku: e.target.value })} /></Field><Field label="Product barcode"><input value={a.data.barcode} onChange={(e) => a.setData({ ...a.data, barcode: e.target.value })} /></Field><Field label="Selling unit"><Select value={a.data.unit} onChange={(v) => a.setData({ ...a.data, unit: v })} rows={[{ value: 'meter', label: 'Meters' }, { value: 'piece', label: 'Pieces' }]} /></Field><Field label="Default selling price"><input value={a.data.default_selling_price} onChange={(e) => a.setData({ ...a.data, default_selling_price: e.target.value })} /></Field><Field label="Low stock threshold"><input value={a.data.low_stock} onChange={(e) => a.setData({ ...a.data, low_stock: e.target.value })} /></Field></div><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Save product</Button></form>;
}

function ProductExistingForm({ p, row }: { p: Props; row: Row }) {
    const a = useAction({ category_id: s(row.category_id), name: s(row.name), sku: s(row.sku), barcode: s(row.barcode), unit: s(row.unit) || 'meter', default_selling_price: (n(row.default_selling_price) / 100).toFixed(2), low_stock: (n(row.low_stock_milli) / 1000).toFixed(3), active: bool(row.active) }, `/fabric/action/product-save/${s(row.id)}`, () => router.reload());
    return <form ref={a.formRef} className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Category"><Select value={a.data.category_id} onChange={(v) => a.setData({ ...a.data, category_id: v })} rows={options(p.categories.filter((r) => bool(r.active) || s(r.id) === a.data.category_id), (r) => s(r.name))} /></Field><Field label="Fabric / product name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="SKU"><input value={a.data.sku} onChange={(e) => a.setData({ ...a.data, sku: e.target.value })} /></Field><Field label="Product barcode"><input value={a.data.barcode} onChange={(e) => a.setData({ ...a.data, barcode: e.target.value })} /></Field><Field label="Selling unit"><Select value={a.data.unit} onChange={(v) => a.setData({ ...a.data, unit: v })} rows={[{ value: 'meter', label: 'Meters' }, { value: 'piece', label: 'Pieces' }]} /></Field><Field label="Default selling price"><input value={a.data.default_selling_price} onChange={(e) => a.setData({ ...a.data, default_selling_price: e.target.value })} /></Field><Field label="Low stock threshold"><input value={a.data.low_stock} onChange={(e) => a.setData({ ...a.data, low_stock: e.target.value })} /></Field></div><p className="fabric-help">Unit cannot be changed after purchase, stock or sale history exists. Historical documents keep their saved product snapshots.</p><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>{a.processing ? 'Saving…' : 'Save product changes'}</Button></form>;
}
function ProductEditor({ p }: { p: Props }) {
    const [selected, setSelected] = useState('');
    const row = p.products.find((r) => s(r.id) === selected);
    return <div className="fabric-form"><Field label="Product to edit"><Select value={selected} onChange={setSelected} rows={options(p.products, (r) => `${s(r.name)} · ${s(r.sku)}`)} /></Field>{row && <ProductExistingForm key={selected} p={p} row={row} />}</div>;
}

function OpeningStockForm({ p, productId = '', onRecorded }: { p: Props; productId?: string; onRecorded?: () => void }) {
    const product = p.products.find((row) => s(row.id) === productId);
    const defaultColor = p.colors.find((row) => bool(row.active) && s(row.name) === 'Unspecified');
    const a = useAction({ product_id: productId, color_id: s(defaultColor?.id), vendor_id: '', quantity: '', unit_cost: '', selling_price: product ? (n(product.default_selling_price) / 100).toFixed(2) : '', roll_number: '', barcode: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), reason: '' }, '/fabric/action/opening-stock', onRecorded ?? (() => router.reload()));
    return <form className="fabric-form" onSubmit={a.submit} ref={a.formRef}><div className="fabric-form-grid two"><Field label="Product"><Select value={a.data.product_id} onChange={(v) => a.setData({ ...a.data, product_id: v })} rows={options(p.products.filter((r) => bool(r.active)), (r) => `${s(r.name)} · ${s(r.unit)}`)} /></Field><Field label="Color"><Select value={a.data.color_id} onChange={(v) => a.setData({ ...a.data, color_id: v })} rows={options(p.colors.filter((r) => bool(r.active)), (r) => s(r.name))} /></Field><Field label="Source vendor (optional)"><Select value={a.data.vendor_id} onChange={(v) => a.setData({ ...a.data, vendor_id: v })} rows={options(p.vendors.filter((r) => bool(r.active)), (r) => s(r.shop_name))} /></Field><Field label="Opening quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} /></Field><Field label="Cost per meter / piece"><input value={a.data.unit_cost} onChange={(e) => a.setData({ ...a.data, unit_cost: e.target.value })} /></Field><Field label="Selling price"><input value={a.data.selling_price} onChange={(e) => a.setData({ ...a.data, selling_price: e.target.value })} /></Field><Field label="Roll number (optional)"><input value={a.data.roll_number} onChange={(e) => a.setData({ ...a.data, roll_number: e.target.value })} /></Field><Field label="Barcode (optional)" hint="Leave blank to generate a unique stock barcode."><input value={a.data.barcode} onChange={(e) => a.setData({ ...a.data, barcode: e.target.value })} /></Field><Field label="Effective date"><input type="datetime-local" value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field></div><Field label="Opening-stock reason"><textarea value={a.data.reason} onChange={(e) => a.setData({ ...a.data, reason: e.target.value })} placeholder="Example: physical stock on hand at system go-live" /></Field><p className="fabric-help">Opening stock creates inventory and its audit movement only. It is not recorded as a new purchase.</p><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>{a.processing ? 'Saving…' : 'Record opening roll'}</Button></form>;
}

function StockAdjustForm({ p }: { p: Props }) {
    const a = useAction({ roll_id: '', quantity: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), reason: '' }, '/fabric/action/stock-adjust', () => router.reload());
    return <form className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Roll"><Select value={a.data.roll_id} onChange={(v) => a.setData({ ...a.data, roll_id: v })} rows={options(p.rolls, (r) => `${s(r.roll_number)} · ${s(r.product_name)} · ${qty(r.current_qty_milli, s(r.unit))}`)} /></Field><Field label="New physical quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} /></Field><Field label="Date"><input type="datetime-local" value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field><Field label="Reason"><input value={a.data.reason} onChange={(e) => a.setData({ ...a.data, reason: e.target.value })} /></Field></div><Button type="submit">Post adjustment</Button></form>;
}

function ReservationForm({ p }: { p: Props }) {
    const a = useAction({ roll_id: '', customer_id: '', quantity: '', expires_at: '', note: '' }, '/fabric/action/reserve', () => router.reload());
    return <form className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Roll"><Select value={a.data.roll_id} onChange={(v) => a.setData({ ...a.data, roll_id: v })} rows={options(p.rolls.filter((r) => n(r.available_qty_milli) > 0 && ['sellable', 'remnant'].includes(s(r.condition))), (r) => `${s(r.roll_number)} · ${s(r.product_name)} · ${qty(r.available_qty_milli, s(r.unit))}`)} /></Field><Field label="Quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} /></Field><Field label="Customer (optional)"><Select value={a.data.customer_id} onChange={(v) => a.setData({ ...a.data, customer_id: v })} rows={options(p.customers, (r) => s(r.name))} /></Field><Field label="Expires (optional)"><input type="datetime-local" value={a.data.expires_at} onChange={(e) => a.setData({ ...a.data, expires_at: e.target.value })} /></Field><Field label="Note"><input value={a.data.note} onChange={(e) => a.setData({ ...a.data, note: e.target.value })} /></Field></div><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Reserve stock</Button></form>;
}

function StockCountForm({ p }: { p: Props }) {
    const a = useAction({ roll_id: '', quantity: '', counted_at: localInput(s(p.business.timezone) || 'UTC'), reason: '', note: '' }, '/fabric/action/stock-count', () => router.reload());
    const submit = (e: FormEvent) => { e.preventDefault(); a.post({ counted_at: a.data.counted_at, note: a.data.note, lines: [{ roll_id: a.data.roll_id, quantity: a.data.quantity, reason: a.data.reason }] }); };
    return <form className="fabric-form" onSubmit={submit}><div className="fabric-form-grid two"><Field label="Roll"><Select value={a.data.roll_id} onChange={(v) => a.setData({ ...a.data, roll_id: v })} rows={options(p.rolls, (r) => `${s(r.roll_number)} · system ${qty(r.current_qty_milli, s(r.unit))}`)} /></Field><Field label="Measured quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} /></Field><Field label="Counted at"><input type="datetime-local" value={a.data.counted_at} onChange={(e) => a.setData({ ...a.data, counted_at: e.target.value })} /></Field><Field label="Difference reason"><input value={a.data.reason} onChange={(e) => a.setData({ ...a.data, reason: e.target.value })} /></Field></div><Field label="Count note"><input value={a.data.note} onChange={(e) => a.setData({ ...a.data, note: e.target.value })} /></Field><ErrorSummary errors={a.errors} /><Button type="submit">Save draft count</Button></form>;
}


function Inventory({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR';
    return <><div className="fabric-toolbar">{p.role !== 'cashier' && <><Expand title="New product"><ProductForm p={p} /></Expand><Expand title="Edit product"><ProductEditor p={p} /></Expand><Expand title="Opening stock"><OpeningStockForm p={p} /></Expand><Expand title="Stock adjustment"><StockAdjustForm p={p} /></Expand><Expand title="Physical count"><StockCountForm p={p} /></Expand></>} {bool(p.fabric_settings.reservations_enabled) && <Expand title="Reserve stock"><ReservationForm p={p} /></Expand>}</div>{p.role !== 'cashier' && <SectionCard title="Fabric / product catalogue"><Table rows={p.products} columns={[{ label: 'Product', value: (r) => <strong>{s(r.name)}</strong> }, { label: 'Category', value: (r) => s(r.category_name) }, { label: 'SKU', value: (r) => s(r.sku) }, { label: 'Product barcode', value: (r) => s(r.barcode) || 'Use individual stock label' }, { label: 'Unit', value: (r) => s(r.unit) }, { label: 'Default sell', value: (r) => money(r.default_selling_price, currency) }, { label: 'Low stock', value: (r) => qty(r.low_stock_milli, s(r.unit)) }, { label: 'State', value: (r) => <Status value={bool(r.active) ? 'active' : 'archived'} /> }, { label: 'Action', value: (r) => <InlineAction label={bool(r.active) ? 'Archive' : 'Reactivate'} url={`/fabric/action/product-toggle/${s(r.id)}`} /> }]} /></SectionCard>}<SectionCard title="Individual roll inventory"><Table rows={p.rolls} columns={[{ label: 'Roll', value: (r) => <div><strong>{s(r.roll_number)}</strong><small className="block">{s(r.barcode)}</small></div> }, { label: 'Fabric', value: (r) => `${s(r.product_name)} / ${s(r.color_name)}` }, { label: 'Condition', value: (r) => <Status value={r.condition} /> }, { label: 'Received', value: (r) => qty(r.original_qty_milli, s(r.unit)) }, { label: 'Current', value: (r) => qty(r.current_qty_milli, s(r.unit)) }, { label: 'Reserved', value: (r) => qty(r.reserved_qty_milli, s(r.unit)) }, { label: 'Available', value: (r) => <strong>{qty(r.available_qty_milli, s(r.unit))}</strong> }, ...(p.role === 'cashier' ? [] : [{ label: 'Cost', value: (r: Row) => money(r.unit_cost, currency) }]), { label: 'Sell', value: (r) => money(r.selling_price, currency) }, { label: 'Barcode label', value: (r) => <div className="inventory-barcode"><img loading="lazy" src={`/fabric/barcode-image/${s(r.id)}`} alt={`Barcode ${s(r.barcode)}`} /><code>{s(r.barcode)}</code><div className="fabric-actions"><a href={`/fabric/document/barcode-label/${s(r.id)}`} target="_blank" rel="noopener noreferrer">Print label</a><a href={`/fabric/document/barcode/${s(r.id)}`}>PDF</a></div></div> }]} /></SectionCard>{bool(p.fabric_settings.reservations_enabled) && <SectionCard title="Active reservations"><Table rows={p.reservations ?? []} columns={[{ label: 'Roll ID', value: (r) => s(r.roll_id) }, { label: 'Quantity', value: (r) => (n(r.quantity_milli) / 1000).toFixed(3) }, { label: 'Customer', value: (r) => s(r.customer_id) || '—' }, { label: 'Expires', value: (r) => r.expires_at ? new Date(s(r.expires_at)).toLocaleString() : '—' }, { label: 'Action', value: (r) => <InlineAction label="Release" url={`/fabric/action/reservation-release/${s(r.id)}`} /> }]} /></SectionCard>}<SectionCard title="Physical counts"><Table rows={p.stock_counts ?? []} columns={[{ label: 'Count', value: (r) => s(r.reference) }, { label: 'Date', value: (r) => new Date(s(r.counted_at)).toLocaleString() }, { label: 'State', value: (r) => <Status value={r.state} /> }, { label: 'Action', value: (r) => s(r.state) === 'draft' && p.role !== 'cashier' ? <InlineAction label="Post adjustments" url={`/fabric/action/stock-count-post/${s(r.id)}`} /> : '—' }]} /></SectionCard><SectionCard title="Recent stock movements"><Table rows={p.movements ?? []} columns={[{ label: 'Roll', value: (r) => s(r.roll_number) }, { label: 'Movement', value: (r) => s(r.kind) }, { label: 'Quantity Δ', value: (r) => (n(r.qty_delta_milli) / 1000).toFixed(3) }, { label: 'Reference', value: (r) => `${s(r.reference_type)} #${s(r.reference_id)}` }, { label: 'Date', value: (r) => new Date(s(r.effective_at)).toLocaleString() }]} /></SectionCard></>;
}

function Returns({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR';
    const empty = { sale_id: '', sale_line_id: '', quantity: '', condition: 'resalable', resolution: 'reduce_balance', account_id: s(p.accounts[0]?.id), reason: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), idempotency_key: crypto.randomUUID() };
    const a = useAction(empty, '/fabric/action/customer-return', () => router.reload());
    const submit = (event: FormEvent) => {
        event.preventDefault();
        a.post({
            sale_id: a.data.sale_id,
            effective_at: a.data.effective_at,
            resolution: a.data.resolution,
            reason: a.data.reason,
            account_id: a.data.resolution === 'refund' ? a.data.account_id : null,
            idempotency_key: a.data.idempotency_key,
            lines: [{ sale_line_id: a.data.sale_line_id, quantity: a.data.quantity, condition: a.data.condition }],
        });
    };
    return <><div className="fabric-grid two"><SectionCard title="Customer return"><form ref={a.formRef} className="fabric-form" onSubmit={submit}><ErrorSummary errors={a.errors} /><Field label="Sale invoice ID"><input value={a.data.sale_id} onChange={(e) => a.setData({ ...a.data, sale_id: e.target.value })} /></Field><Field label="Sale line"><Select value={a.data.sale_line_id} onChange={(v) => a.setData({ ...a.data, sale_line_id: v })} rows={options((p.sale_lines ?? []).filter((r) => !a.data.sale_id || s(r.sale_id) === a.data.sale_id), (r) => `${s(r.sale_reference)} · ${s(r.product_name)} · ${(n(r.quantity_milli) / 1000).toFixed(3)}`)} /></Field><div className="fabric-form-grid two"><Field label="Return quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} /></Field><Field label="Condition"><Select value={a.data.condition} onChange={(v) => a.setData({ ...a.data, condition: v })} rows={[{ value: 'resalable', label: 'Resalable remnant' }, { value: 'damaged', label: 'Damaged / inspection' }]} /></Field><Field label="Financial resolution"><Select value={a.data.resolution} onChange={(v) => a.setData({ ...a.data, resolution: v })} rows={[{ value: 'reduce_balance', label: 'Reduce original balance' }, { value: 'credit', label: 'Keep customer credit' }, { value: 'refund', label: 'Cash / bank refund' }]} /></Field>{a.data.resolution === 'refund' && <Field label="Refund account"><Select value={a.data.account_id} onChange={(v) => a.setData({ ...a.data, account_id: v })} rows={options(p.accounts, (r) => s(r.name))} /></Field>}<Field label="Effective date"><input type="datetime-local" value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field></div><Field label="Reason"><textarea value={a.data.reason} onChange={(e) => a.setData({ ...a.data, reason: e.target.value })} /></Field><Button type="submit" disabled={a.processing}>{a.processing ? 'Posting…' : 'Post customer return'}</Button></form></SectionCard><SectionCard title="Vendor return"><p className="fabric-help">Vendor returns are posted against the original bill and physical roll. Use the roll and purchase IDs shown in Purchases/Inventory; the backend verifies they belong to the same vendor and bill.</p><VendorReturnQuick p={p} /></SectionCard></div><SectionCard title="Return history"><Table rows={p.customer_returns ?? []} columns={[{ label: 'Return', value: (r) => s(r.reference) }, { label: 'Sale ID', value: (r) => s(r.sale_id) }, { label: 'Credit', value: (r) => money(r.credit_amount, currency) }, { label: 'Resolution', value: (r) => <Status value={r.resolution} /> }, { label: 'Reason', value: (r) => s(r.reason) }]} /></SectionCard></>;
}

function VendorReturnQuick({ p }: { p: Props }) {
    const a = useAction({ vendor_id: '', purchase_bill_id: '', roll_id: '', quantity: '', reason: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), idempotency_key: crypto.randomUUID() }, '/fabric/action/vendor-return', () => router.reload());
    const submit = (event: FormEvent) => { event.preventDefault(); a.post({ ...a.data, lines: [{ roll_id: a.data.roll_id, quantity: a.data.quantity }] }); };
    return <form ref={a.formRef} className="fabric-form" onSubmit={submit}><ErrorSummary errors={a.errors} /><div className="fabric-form-grid two"><Field label="Vendor"><Select value={a.data.vendor_id} onChange={(v) => a.setData({ ...a.data, vendor_id: v })} rows={options(p.vendors, (r) => s(r.shop_name))} /></Field><Field label="Purchase bill ID"><input value={a.data.purchase_bill_id} onChange={(e) => a.setData({ ...a.data, purchase_bill_id: e.target.value })} /></Field><Field label="Roll"><Select value={a.data.roll_id} onChange={(v) => a.setData({ ...a.data, roll_id: v })} rows={options(p.rolls.filter((r) => !a.data.vendor_id || s(r.vendor_id) === a.data.vendor_id), (r) => `${s(r.roll_number)} · ${s(r.product_name)}`)} /></Field><Field label="Quantity"><input value={a.data.quantity} onChange={(e) => a.setData({ ...a.data, quantity: e.target.value })} /></Field></div><Field label="Reason"><textarea value={a.data.reason} onChange={(e) => a.setData({ ...a.data, reason: e.target.value })} /></Field><Button type="submit" disabled={a.processing}>{a.processing ? 'Posting…' : 'Return to vendor'}</Button></form>;
}

function CashBank({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR'; const a = useAction({ from_account_id: '', to_account_id: '', amount: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), note: '', idempotency_key: crypto.randomUUID() }, '/fabric/action/transfer', () => router.reload());
    return <><div className="fabric-metrics">{(p.account_balances ?? []).map((account) => <div className="fabric-metric" key={s(account.id)}><span><WalletCards /></span><div><small>{s(account.name)} · {s(account.kind)}</small><strong>{money(account.balance, currency)}</strong></div></div>)}</div>{p.role !== 'cashier' && <SectionCard title="Transfer between accounts"><form ref={a.formRef} className="fabric-form inline" onSubmit={a.submit}><ErrorSummary errors={a.errors} /><Select value={a.data.from_account_id} onChange={(v) => a.setData({ ...a.data, from_account_id: v })} rows={options(p.accounts, (r) => s(r.name))} /><ArrowLeftRight /><Select value={a.data.to_account_id} onChange={(v) => a.setData({ ...a.data, to_account_id: v })} rows={options(p.accounts, (r) => s(r.name))} /><input placeholder="Amount" value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /><Button type="submit" disabled={a.processing}>{a.processing ? 'Transferring…' : 'Transfer'}</Button></form></SectionCard>}<SectionCard title="Cash / bank ledger"><Table rows={p.account_entries ?? []} columns={[{ label: 'Date', value: (r) => new Date(s(r.effective_at)).toLocaleString() }, { label: 'Account', value: (r) => s(r.account_id) }, { label: 'Type', value: (r) => s(r.kind) }, { label: 'Direction', value: (r) => <Status value={r.direction} /> }, { label: 'Amount', value: (r) => money(r.amount, currency) }, { label: 'Source', value: (r) => `${s(r.reference_type)} #${s(r.reference_id)}` }]} /></SectionCard></>;
}

function Expenses({ p }: { p: Props }) {
    const currency = s(p.business.currency) || 'PKR';
    const empty = { category: 'Operating', amount: '', account_id: s(p.accounts[0]?.id), effective_at: localInput(s(p.business.timezone) || 'UTC'), note: '', receipt: null as File | null, idempotency_key: crypto.randomUUID() };
    const a = useAction(empty, '/fabric/action/expense', () => router.reload());
    const submit = (event: FormEvent) => {
        event.preventDefault();
        a.post(a.data, true);
    };
    return <><SectionCard title="Post operating expense"><form ref={a.formRef} className="fabric-form" onSubmit={submit}><ErrorSummary errors={a.errors} /><div className="fabric-form-grid two"><Field label="Category"><input value={a.data.category} onChange={(e) => a.setData({ ...a.data, category: e.target.value })} /></Field><Field label="Amount"><input value={a.data.amount} onChange={(e) => a.setData({ ...a.data, amount: e.target.value })} /></Field><Field label="Cash / bank"><Select value={a.data.account_id} onChange={(v) => a.setData({ ...a.data, account_id: v })} rows={options(p.accounts, (r) => s(r.name))} /></Field><Field label="Date"><input type="datetime-local" value={a.data.effective_at} onChange={(e) => a.setData({ ...a.data, effective_at: e.target.value })} /></Field><Field label="Private receipt"><input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => a.setData({ ...a.data, receipt: e.target.files?.[0] ?? null })} /></Field><Field label="Note"><input value={a.data.note} onChange={(e) => a.setData({ ...a.data, note: e.target.value })} /></Field></div><Button type="submit" disabled={a.processing}>{a.processing ? 'Posting…' : 'Post expense'}</Button></form></SectionCard><SectionCard title="Expense history"><Table rows={p.expenses ?? []} columns={[{ label: 'Reference', value: (r) => s(r.reference) }, { label: 'Category', value: (r) => s(r.category) }, { label: 'Amount', value: (r) => money(r.amount, currency) }, { label: 'Date', value: (r) => new Date(s(r.effective_at)).toLocaleString() }, { label: 'Receipt', value: (r) => r.receipt_path ? <a href={`/fabric/expense-receipt/${s(r.id)}`}>Open</a> : '—' }]} /></SectionCard></>;
}

function PromotionForm({ p, row }: { p: Props; row?: Row }) {
    const existingTargets = ((row?.targets as Row[] | undefined) ?? []);
    const firstType = s(existingTargets[0]?.target_type);
    const initial = {
        name: s(row?.name), percent: s(row?.percent), starts_at: row?.starts_at ? localInput(s(p.business.timezone) || 'UTC', s(row.starts_at)) : localInput(s(p.business.timezone) || 'UTC'),
        ends_at: row?.ends_at ? localInput(s(p.business.timezone) || 'UTC', s(row.ends_at)) : localInput(s(p.business.timezone) || 'UTC', new Date(Date.now() + 7 * 86400000).toISOString()),
        priority: s(row?.priority || '100'), stackable: row ? bool(row.stackable) : false, active: row ? bool(row.active) : true,
        target_type: firstType, target_ids: existingTargets.filter((t) => s(t.target_type) === firstType).map((t) => s(t.target_id)),
    };
    const a = useAction(initial, row ? `/fabric/action/promotion-save/${s(row.id)}` : '/fabric/action/promotion-save', () => router.reload());
    const targetRows = a.data.target_type === 'product' ? p.products : a.data.target_type === 'category' ? p.categories : a.data.target_type === 'color' ? p.colors : [];
    const submit = (e: FormEvent) => { e.preventDefault(); a.post({ ...a.data, targets: a.data.target_type ? a.data.target_ids.map((id) => ({ type: a.data.target_type, id })) : [] }); };
    return <form className="fabric-form" onSubmit={submit} ref={a.formRef}><ErrorSummary errors={a.errors} /><div className="fabric-form-grid two"><Field label="Name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="Discount %"><input value={a.data.percent} onChange={(e) => a.setData({ ...a.data, percent: e.target.value })} /></Field><Field label="Starts"><input type="datetime-local" value={a.data.starts_at} onChange={(e) => a.setData({ ...a.data, starts_at: e.target.value })} /></Field><Field label="Ends"><input type="datetime-local" value={a.data.ends_at} onChange={(e) => a.setData({ ...a.data, ends_at: e.target.value })} /></Field><Field label="Priority"><input type="number" value={a.data.priority} onChange={(e) => a.setData({ ...a.data, priority: e.target.value })} /></Field><Field label="Target group"><Select value={a.data.target_type} onChange={(v) => a.setData({ ...a.data, target_type: v, target_ids: [] })} rows={[{ value: 'product', label: 'Selected products' }, { value: 'category', label: 'Selected categories' }, { value: 'color', label: 'Selected colors' }]} placeholder="All fabric" /></Field></div>{a.data.target_type && <Field label="Promotion targets" hint="Select one or more. Ctrl/Cmd-click on desktop for multiple items."><select multiple size={Math.min(7, Math.max(3, targetRows.length))} value={a.data.target_ids} onChange={(e) => a.setData({ ...a.data, target_ids: Array.from(e.currentTarget.selectedOptions, (option) => option.value) })}>{targetRows.filter((r) => bool(r.active) || a.data.target_ids.includes(s(r.id))).map((r) => <option key={s(r.id)} value={s(r.id)}>{s(r.name)}</option>)}</select></Field>}<label className="fabric-check"><input type="checkbox" checked={bool(a.data.stackable)} onChange={(e) => a.setData({ ...a.data, stackable: e.target.checked })} /> Allow stacking with lower-priority promotions</label><label className="fabric-check"><input type="checkbox" checked={bool(a.data.active)} onChange={(e) => a.setData({ ...a.data, active: e.target.checked })} /> Promotion active</label><p className="fabric-help">Changing a promotion affects future pricing only. Posted sale lines retain the promotion snapshot used when the invoice was created.</p><Button type="submit" disabled={a.processing}>{a.processing ? 'Saving…' : row ? 'Save promotion changes' : 'Create promotion'}</Button></form>;
}
function PromotionEditor({ p }: { p: Props }) {
    const [selected, setSelected] = useState('');
    const row = (p.promotions ?? []).find((r) => s(r.id) === selected);
    return <div className="fabric-form"><Field label="Promotion to edit"><Select value={selected} onChange={setSelected} rows={options(p.promotions ?? [], (r) => `${s(r.name)} · ${s(r.percent)}%`)} /></Field>{row && <PromotionForm key={selected} p={p} row={row} />}</div>;
}
function Promotions({ p }: { p: Props }) {
    return <><div className="fabric-toolbar"><Expand title="New promotion"><PromotionForm p={p} /></Expand><Expand title="Edit promotion"><PromotionEditor p={p} /></Expand></div><SectionCard title="Promotion schedule"><Table rows={p.promotions ?? []} columns={[{ label: 'Name', value: (r) => <strong>{s(r.name)}</strong> }, { label: 'Percent', value: (r) => `${s(r.percent)}%` }, { label: 'Start', value: (r) => new Date(s(r.starts_at)).toLocaleString() }, { label: 'End', value: (r) => new Date(s(r.ends_at)).toLocaleString() }, { label: 'Priority', value: (r) => s(r.priority) }, { label: 'Targets', value: (r) => ((r.targets as Row[] | undefined)?.length ?? 0) ? `${(r.targets as Row[]).length} selected` : 'All fabric' }, { label: 'Stacking', value: (r) => bool(r.stackable) ? 'Allowed' : 'No' }, { label: 'State', value: (r) => <Status value={bool(r.active) ? 'active' : 'inactive'} /> }, { label: 'Action', value: (r) => <InlineAction label={bool(r.active) ? 'Deactivate' : 'Activate'} url={`/fabric/action/promotion-toggle/${s(r.id)}`} /> }]} /></SectionCard></>;
}

// ─── WhatsApp Bulk Component ─────────────────────────────────────────────────
const WA_SERVER = 'http://localhost:3001';

type WaStatus = 'disconnected' | 'qr-pending' | 'connected' | 'reconnecting' | 'unknown';
type SendProgress = {
    active: boolean;
    total: number;
    sent: number;
    failed: number;
    failedNumbers: string[];
    percent: number;
    startedAt: string | null;
    finishedAt: string | null;
};

const TEMPLATES = [
    { label: 'Sale Announcement', text: '🎉 Big Sale! Visit our store for amazing discounts on premium fabric. Limited stock available!' },
    { label: 'New Arrival', text: '✨ New fabric collection just arrived! Fresh designs, premium quality. Come check it out today.' },
    { label: 'Eid Mubarak', text: '🌙 Eid Mubarak! Wishing you and your family a blessed Eid. Visit us for festive fabric collections.' },
    { label: 'Stock Clearance', text: '🏷️ Stock clearance sale! Huge discounts on selected fabric rolls. Hurry — limited time offer!' },
    { label: 'Custom Order', text: '🧵 We accept custom fabric orders! Tell us your requirement and we will arrange it for you.' },
];

function WhatsAppBulk({ p }: { p: Props }) {
    const customers = p.customers ?? [];
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [search, setSearch] = useState('');
    const [message, setMessage] = useState('');
    const [mediaFile, setMediaFile] = useState<File | null>(null);
    const [mediaFilename, setMediaFilename] = useState<string | null>(null);
    const [mediaUploading, setMediaUploading] = useState(false);
    const [waStatus, setWaStatus] = useState<WaStatus>('unknown');
    const [qrCode, setQrCode] = useState<string | null>(null);
    const [progress, setProgress] = useState<SendProgress | null>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const filtered = useMemo(() =>
        customers.filter((c) =>
            s(c.name).toLowerCase().includes(search.toLowerCase()) ||
            s(c.phone).includes(search)
        ),
        [customers, search]
    );

    // Poll WhatsApp status
    useEffect(() => {
        const poll = async () => {
            try {
                const res = await fetch(`${WA_SERVER}/status`);
                if (!res.ok) { setWaStatus('disconnected'); return; }
                const data = await res.json();
                setWaStatus(data.status as WaStatus);
                if (data.hasQr) {
                    const qrRes = await fetch(`${WA_SERVER}/qr`);
                    if (qrRes.ok) {
                        const qrData = await qrRes.json();
                        setQrCode(qrData.qr);
                    }
                } else {
                    setQrCode(null);
                }
            } catch {
                setWaStatus('disconnected');
            }
        };
        poll();
        const interval = setInterval(poll, 3000);
        return () => clearInterval(interval);
    }, []);

    // Poll send progress
    useEffect(() => {
        if (!sending) return;
        const poll = async () => {
            try {
                const res = await fetch(`${WA_SERVER}/progress`);
                if (!res.ok) return;
                const data: SendProgress = await res.json();
                setProgress(data);
                if (!data.active && data.total > 0) {
                    setSending(false);
                    if (pollRef.current) clearInterval(pollRef.current);
                    setSuccess(`✅ Done! Sent: ${data.sent}, Failed: ${data.failed}`);
                }
            } catch { /* ignore */ }
        };
        poll();
        pollRef.current = setInterval(poll, 1500);
        return () => { if (pollRef.current) clearInterval(pollRef.current); };
    }, [sending]);

    const toggleAll = () => {
        if (selected.size === filtered.length) {
            setSelected(new Set());
        } else {
            setSelected(new Set(filtered.map((c) => n(c.id))));
        }
    };

    const toggleOne = (id: number) => {
        const next = new Set(selected);
        if (next.has(id)) next.delete(id);
        else next.add(id);
        setSelected(next);
    };

    const uploadMedia = async (file: File) => {
        setMediaUploading(true);
        setError(null);
        try {
            const fd = new FormData();
            fd.append('file', file);
            const res = await fetch(`${WA_SERVER}/upload-media`, { method: 'POST', body: fd });
            if (!res.ok) throw new Error('Upload failed');
            const data = await res.json();
            setMediaFilename(data.filename);
        } catch (e) {
            setError('Media upload failed. Make sure WhatsApp server is running.');
        } finally {
            setMediaUploading(false);
        }
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] ?? null;
        setMediaFile(file);
        setMediaFilename(null);
        if (file) uploadMedia(file);
    };

    const handleSend = async () => {
        setError(null);
        setSuccess(null);
        if (waStatus !== 'connected') {
            setError('WhatsApp is not connected. Please scan the QR code first.');
            return;
        }
        if (selected.size === 0) { setError('Please select at least one customer.'); return; }
        if (!message.trim()) { setError('Please enter a message.'); return; }
        if (mediaFile && !mediaFilename) { setError('Media is still uploading. Please wait.'); return; }

        const phones = customers
            .filter((c) => selected.has(n(c.id)))
            .map((c) => s(c.normalized_phone || c.phone));

        setSending(true);
        setProgress({ active: true, total: phones.length, sent: 0, failed: 0, failedNumbers: [], percent: 0, startedAt: null, finishedAt: null });

        try {
            const res = await fetch(`${WA_SERVER}/send-bulk`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ phones, message, mediaFilename }),
            });
            const data = await res.json();
            if (!res.ok) {
                setSending(false);
                setError(data.error || 'Failed to start sending.');
            }
        } catch {
            setSending(false);
            setError('Cannot connect to WhatsApp server. Make sure it is running on port 3001.');
        }
    };

    const handleReconnect = async () => {
        setError(null);
        try {
            await fetch(`${WA_SERVER}/reconnect`, { method: 'POST' });
        } catch {
            setError('Cannot connect to WhatsApp server.');
        }
    };

    const allSelected = filtered.length > 0 && filtered.every((c) => selected.has(n(c.id)));
    const someSelected = selected.size > 0;

    const statusColor = waStatus === 'connected' ? '#22c55e' : waStatus === 'qr-pending' ? '#f59e0b' : '#ef4444';
    const statusLabel = waStatus === 'connected' ? 'Connected' : waStatus === 'qr-pending' ? 'Scan QR Code' : waStatus === 'reconnecting' ? 'Reconnecting…' : 'Disconnected';

    return (
        <div style={{ display: 'grid', gap: '20px' }}>

            {/* ── Status Bar ── */}
            <div style={{
                display: 'flex', alignItems: 'center', gap: '12px',
                padding: '14px 18px',
                background: 'color-mix(in srgb, var(--surface-2) 80%, transparent)',
                border: '1px solid var(--border)',
                borderRadius: '10px',
                flexWrap: 'wrap',
            }}>
                <span style={{ display: 'flex', alignItems: 'center', gap: '8px', fontWeight: 700, fontSize: '13px' }}>
                    {waStatus === 'connected'
                        ? <Wifi size={18} color={statusColor} />
                        : <WifiOff size={18} color={statusColor} />}
                    <span style={{ color: statusColor }}>{statusLabel}</span>
                </span>
                {waStatus !== 'connected' && (
                    <Button secondary onClick={handleReconnect}>🔄 Reconnect</Button>
                )}
                <span style={{ marginLeft: 'auto', fontSize: '12px', color: 'var(--text-2)' }}>
                    WhatsApp server: <code style={{ fontSize: '11px' }}>localhost:3001</code>
                </span>
            </div>

            {/* ── QR Code ── */}
            {qrCode && (
                <div style={{ textAlign: 'center', padding: '24px', background: 'var(--surface-2)', borderRadius: '12px', border: '2px dashed #f59e0b' }}>
                    <p style={{ fontWeight: 700, fontSize: '14px', marginBottom: '12px', color: '#f59e0b' }}>📱 Scan this QR code with WhatsApp</p>
                    <p style={{ fontSize: '12px', color: 'var(--text-2)', marginBottom: '16px' }}>WhatsApp → Linked Devices → Link a Device</p>
                    <img src={qrCode} alt="WhatsApp QR Code" style={{ width: '220px', height: '220px', borderRadius: '8px', border: '3px solid #fff' }} />
                    <p style={{ fontSize: '11px', color: 'var(--text-2)', marginTop: '12px' }}>QR code auto-refreshes every 3 seconds</p>
                </div>
            )}

            {/* ── Error / Success ── */}
            {error && (
                <div style={{ padding: '12px 16px', background: '#fee2e2', border: '1px solid #fca5a5', borderRadius: '8px', color: '#b91c1c', fontSize: '13px' }}>
                    ❌ {error}
                </div>
            )}
            {success && (
                <div style={{ padding: '12px 16px', background: '#dcfce7', border: '1px solid #86efac', borderRadius: '8px', color: '#15803d', fontSize: '13px' }}>
                    {success}
                </div>
            )}

            {/* ── Send Progress ── */}
            {progress && (sending || (progress.total > 0 && !progress.active)) && (
                <section className="fabric-card">
                    <div className="fabric-card-head"><h2>📤 Sending Progress</h2></div>
                    <div style={{ padding: '16px', display: 'grid', gap: '12px' }}>
                        <div style={{ display: 'flex', gap: '20px', fontSize: '13px', fontWeight: 600 }}>
                            <span>Total: <strong>{progress.total}</strong></span>
                            <span style={{ color: '#22c55e' }}>Sent: <strong>{progress.sent}</strong></span>
                            <span style={{ color: '#ef4444' }}>Failed: <strong>{progress.failed}</strong></span>
                        </div>
                        <div style={{ background: 'var(--surface-2)', borderRadius: '999px', height: '10px', overflow: 'hidden' }}>
                            <div style={{
                                height: '100%',
                                width: `${progress.percent}%`,
                                background: 'linear-gradient(90deg, #25D366, #128C7E)',
                                borderRadius: '999px',
                                transition: 'width 0.4s ease',
                            }} />
                        </div>
                        <p style={{ fontSize: '12px', color: 'var(--text-2)' }}>
                            {progress.active ? `Sending… ${progress.sent + progress.failed} / ${progress.total}` : `Completed — ${progress.percent}%`}
                        </p>
                        {progress.failedNumbers.length > 0 && (
                            <div style={{ fontSize: '12px', color: '#b91c1c' }}>
                                Failed numbers: {progress.failedNumbers.join(', ')}
                            </div>
                        )}
                    </div>
                </section>
            )}

            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px' }}>

                {/* ── Customer List ── */}
                <section className="fabric-card" style={{ maxHeight: '600px', display: 'flex', flexDirection: 'column' }}>
                    <div className="fabric-card-head">
                        <h2>👥 Customers ({filtered.length})</h2>
                        <span style={{ fontSize: '12px', color: 'var(--text-2)' }}>{selected.size} selected</span>
                    </div>
                    <div style={{ padding: '0 16px 12px', display: 'flex', gap: '8px', alignItems: 'center' }}>
                        <div style={{ position: 'relative', flex: 1 }}>
                            <Search size={14} style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-2)' }} />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search name or phone…"
                                style={{ paddingLeft: '32px', width: '100%', fontSize: '13px' }}
                            />
                        </div>
                        <button
                            type="button"
                            onClick={toggleAll}
                            style={{ display: 'flex', alignItems: 'center', gap: '6px', padding: '7px 12px', fontSize: '12px', fontWeight: 600, background: 'var(--surface-2)', border: '1px solid var(--border)', borderRadius: '7px', cursor: 'pointer', whiteSpace: 'nowrap' }}
                        >
                            {allSelected ? <CheckSquare size={14} /> : <Square size={14} />}
                            {allSelected ? 'Deselect All' : 'Select All'}
                        </button>
                    </div>
                    <div style={{ overflowY: 'auto', flex: 1, padding: '0 8px 8px' }}>
                        {filtered.length === 0 && (
                            <div className="fabric-empty"><Users size={28} /><strong>No customers with phone numbers.</strong></div>
                        )}
                        {filtered.map((c) => {
                            const id = n(c.id);
                            const isSelected = selected.has(id);
                            return (
                                <label
                                    key={id}
                                    style={{
                                        display: 'flex', alignItems: 'center', gap: '12px',
                                        padding: '10px 10px',
                                        borderRadius: '8px',
                                        cursor: 'pointer',
                                        background: isSelected ? 'color-mix(in srgb, #25D366 10%, transparent)' : 'transparent',
                                        border: isSelected ? '1px solid #25D366' : '1px solid transparent',
                                        marginBottom: '4px',
                                        transition: 'all 0.15s',
                                    }}
                                >
                                    <input
                                        type="checkbox"
                                        checked={isSelected}
                                        onChange={() => toggleOne(id)}
                                        style={{ width: '16px', height: '16px', accentColor: '#25D366', cursor: 'pointer' }}
                                    />
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div style={{ fontWeight: 600, fontSize: '13px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                                            {s(c.name) || 'Unknown'}
                                        </div>
                                        <div style={{ fontSize: '11px', color: 'var(--text-2)' }}>{s(c.phone)}</div>
                                    </div>
                                </label>
                            );
                        })}
                    </div>
                </section>

                {/* ── Message Composer ── */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
                    <section className="fabric-card">
                        <div className="fabric-card-head"><h2>💬 Compose Message</h2></div>
                        <div style={{ padding: '16px', display: 'grid', gap: '14px' }}>

                            {/* Template Buttons */}
                            <div>
                                <p style={{ fontSize: '11px', fontWeight: 700, color: 'var(--text-2)', marginBottom: '8px', textTransform: 'uppercase', letterSpacing: '0.05em' }}>Quick Templates</p>
                                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '6px' }}>
                                    {TEMPLATES.map((t) => (
                                        <button
                                            key={t.label}
                                            type="button"
                                            onClick={() => setMessage(t.text)}
                                            style={{
                                                padding: '5px 10px', fontSize: '11px', fontWeight: 600,
                                                background: 'var(--surface-2)', border: '1px solid var(--border)',
                                                borderRadius: '999px', cursor: 'pointer',
                                                transition: 'all 0.15s',
                                            }}
                                        >
                                            {t.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Message textarea */}
                            <div>
                                <label style={{ fontSize: '12px', fontWeight: 600, display: 'block', marginBottom: '6px' }}>Message *</label>
                                <textarea
                                    value={message}
                                    onChange={(e) => setMessage(e.target.value)}
                                    placeholder="Type your WhatsApp message here…"
                                    rows={5}
                                    style={{ width: '100%', resize: 'vertical', fontSize: '13px', lineHeight: '1.5' }}
                                />
                                <p style={{ fontSize: '11px', color: 'var(--text-2)', marginTop: '4px' }}>{message.length} characters</p>
                            </div>

                            {/* Media Upload */}
                            <div>
                                <label style={{ fontSize: '12px', fontWeight: 600, display: 'block', marginBottom: '6px' }}>
                                    <ImageIcon size={13} style={{ display: 'inline', marginRight: '5px' }} />
                                    Attach Image / Video (optional)
                                </label>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept="image/*,video/*"
                                    onChange={handleFileChange}
                                    style={{ fontSize: '12px' }}
                                />
                                {mediaUploading && <p style={{ fontSize: '12px', color: '#f59e0b', marginTop: '4px' }}>⏳ Uploading media…</p>}
                                {mediaFilename && !mediaUploading && (
                                    <p style={{ fontSize: '12px', color: '#22c55e', marginTop: '4px' }}>✅ Media ready: {mediaFilename}</p>
                                )}
                            </div>
                        </div>
                    </section>

                    {/* Send Button */}
                    <button
                        type="button"
                        onClick={handleSend}
                        disabled={sending || !someSelected || !message.trim() || waStatus !== 'connected'}
                        style={{
                            display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '10px',
                            padding: '16px 24px',
                            background: sending || waStatus !== 'connected'
                                ? 'var(--surface-2)'
                                : 'linear-gradient(135deg, #25D366 0%, #128C7E 100%)',
                            color: sending || waStatus !== 'connected' ? 'var(--text-2)' : '#fff',
                            border: 'none',
                            borderRadius: '12px',
                            fontSize: '15px',
                            fontWeight: 700,
                            cursor: sending || !someSelected || !message.trim() || waStatus !== 'connected' ? 'not-allowed' : 'pointer',
                            opacity: !someSelected || !message.trim() ? 0.5 : 1,
                            transition: 'all 0.2s',
                            boxShadow: waStatus === 'connected' && someSelected && message.trim()
                                ? '0 4px 20px rgba(37,211,102,0.35)'
                                : 'none',
                        }}
                    >
                        <Send size={18} />
                        {sending
                            ? `Sending… ${progress ? `${progress.sent + progress.failed}/${progress.total}` : ''}`
                            : `Send to ${selected.size} customer${selected.size !== 1 ? 's' : ''}`
                        }
                    </button>

                    {waStatus !== 'connected' && (
                        <p style={{ fontSize: '12px', color: 'var(--text-2)', textAlign: 'center' }}>
                            ⚠️ Connect WhatsApp first by scanning the QR code above.
                        </p>
                    )}
                </div>
            </div>

            {/* ── Setup Instructions ── */}
            {waStatus === 'disconnected' && !qrCode && (
                <section className="fabric-card">
                    <div className="fabric-card-head"><h2>⚙️ Setup Instructions</h2></div>
                    <div style={{ padding: '16px', display: 'grid', gap: '10px', fontSize: '13px', lineHeight: '1.6' }}>
                        <p><strong>1.</strong> Open a new terminal and run:</p>
                        <pre style={{ background: 'var(--surface-2)', padding: '12px', borderRadius: '8px', fontSize: '12px', overflow: 'auto' }}>{`cd whatsapp-server\nnpm install\nnode server.js`}</pre>
                        <p><strong>2.</strong> Come back here — the QR code will appear automatically.</p>
                        <p><strong>3.</strong> Scan it with your phone: <strong>WhatsApp → Linked Devices → Link a Device</strong></p>
                        <p><strong>4.</strong> Done! Your session is saved — you only need to scan once.</p>
                    </div>
                </section>
            )}
        </div>
    );
}

function Marketing({ p }: { p: Props }) {
    const contact = useAction({ phone: '', name: '', whatsapp_consent: false as boolean, whatsapp_consent_source: '', sms_consent: false as boolean, sms_consent_source: '', opted_out: false }, '/fabric/action/marketing-contact', () => router.reload());
    const campaign = useAction({ name: '', channel: 'whatsapp', message: '', provider: '' }, '/fabric/action/campaign-save', () => router.reload());
    const importer = useAction({ file: null as File | null }, '/fabric/action/marketing-import', () => router.reload());
    return <>
        <div className="fabric-grid two">
            <SectionCard title="Consent contact"><form className="fabric-form" onSubmit={contact.submit}><ErrorSummary errors={contact.errors} /><Field label="Phone"><input value={contact.data.phone} onChange={(e) => contact.setData({ ...contact.data, phone: e.target.value })} /></Field><Field label="Name"><input value={contact.data.name} onChange={(e) => contact.setData({ ...contact.data, name: e.target.value })} /></Field><label className="fabric-check"><input type="checkbox" checked={bool(contact.data.whatsapp_consent)} onChange={(e) => contact.setData({ ...contact.data, whatsapp_consent: e.target.checked })} /> WhatsApp consent recorded</label><Field label="WhatsApp consent source"><input value={contact.data.whatsapp_consent_source} onChange={(e) => contact.setData({ ...contact.data, whatsapp_consent_source: e.target.value })} /></Field><label className="fabric-check"><input type="checkbox" checked={bool(contact.data.sms_consent)} onChange={(e) => contact.setData({ ...contact.data, sms_consent: e.target.checked })} /> SMS consent recorded</label><Field label="SMS consent source"><input value={contact.data.sms_consent_source} onChange={(e) => contact.setData({ ...contact.data, sms_consent_source: e.target.value })} /></Field><Button type="submit" disabled={contact.processing}>Save contact</Button></form></SectionCard>
            <SectionCard title="Prepare campaign"><form className="fabric-form" onSubmit={campaign.submit}><ErrorSummary errors={campaign.errors} /><Field label="Campaign name"><input value={campaign.data.name} onChange={(e) => campaign.setData({ ...campaign.data, name: e.target.value })} /></Field><Field label="Channel"><Select value={campaign.data.channel} onChange={(v) => campaign.setData({ ...campaign.data, channel: v })} rows={[{ value: 'whatsapp', label: 'WhatsApp' }, { value: 'sms', label: 'SMS' }]} /></Field><Field label="Message"><textarea value={campaign.data.message} onChange={(e) => campaign.setData({ ...campaign.data, message: e.target.value })} /></Field><Field label="Provider account"><input value={campaign.data.provider} onChange={(e) => campaign.setData({ ...campaign.data, provider: e.target.value })} placeholder="Required only for actual sending" /></Field><Button type="submit" disabled={campaign.processing}>Save draft campaign</Button></form><p className="fabric-help">Actual sending remains disabled until an approved provider connector is configured.</p></SectionCard>
        </div>
        <SectionCard title="Import contacts"><form className="fabric-form inline" onSubmit={(e) => { e.preventDefault(); importer.post({ file: importer.data.file }, true); }}><input type="file" accept=".csv,text/csv" onChange={(e) => importer.setData({ file: e.target.files?.[0] ?? null })} /><Button type="submit" disabled={!importer.data.file || importer.processing}>Import CSV</Button><span className="fabric-help">Required column: phone. Optional: name. Import never grants or revokes consent.</span></form><ErrorSummary errors={importer.errors} /></SectionCard>
        <SectionCard title="Contacts"><Table rows={p.contacts ?? []} columns={[{ label: 'Phone', value: (r) => s(r.phone) }, { label: 'Name', value: (r) => s(r.name) }, { label: 'WhatsApp', value: (r) => bool(r.whatsapp_consent) ? 'Consented' : 'No consent' }, { label: 'SMS', value: (r) => bool(r.sms_consent) ? 'Consented' : 'No consent' }, { label: 'Opt-out', value: (r) => bool(r.opted_out) ? 'Yes' : 'No' }, { label: 'Action', value: (r) => !bool(r.opted_out) && p.role !== 'cashier' ? <InlineAction label="Opt out" url={`/fabric/action/marketing-optout/${s(r.id)}`} /> : '—' }]} /></SectionCard>
        <SectionCard title="Campaigns"><Table rows={p.campaigns ?? []} columns={[{ label: 'Campaign', value: (r) => s(r.name) }, { label: 'Channel', value: (r) => s(r.channel) }, { label: 'State', value: (r) => <Status value={r.state} /> }, { label: 'Provider', value: (r) => s(r.provider) || 'Not configured' }, { label: 'Action', value: (r) => p.role === 'owner' && s(r.state) === 'draft' ? <InlineAction label="Approve" url={`/fabric/action/campaign-approve/${s(r.id)}`} /> : p.role === 'owner' && s(r.state) === 'approved' ? <InlineAction label="Try launch" url={`/fabric/campaign/${s(r.id)}/launch`} /> : '—' }]} /></SectionCard>
    </>;
}

function Reports({ p }: { p: Props }) {
    const type = p.report_type ?? 'sales';
    const currency = s(p.business.currency) || 'PKR';
    const rows = p.report_rows ?? [];
    const keys = rows.length ? Object.keys(rows[0]).slice(0, 12) : [];
    const reportTypes = [
        ['sales', 'Sales'], ['purchases', 'Purchases'], ['stock', 'Stock'], ['movements', 'Stock movements'],
        ['customers', 'Customer outstanding'], ['vendors', 'Vendor outstanding'], ['advances', 'Advances / credits'],
        ['returns', 'Returns / refunds'], ['expenses', 'Expenses'], ['installments', 'Installments'], ['cash', 'Cash / bank'], ['profit', 'Profit'],
    ].map(([value, label]) => ({ value, label }));
    const showCustomer = ['sales', 'customers', 'returns'].includes(type);
    const showVendor = ['purchases', 'vendors', 'installments', 'returns'].includes(type);
    const showProduct = ['stock', 'movements'].includes(type);
    const statusRows = type === 'sales' || type === 'purchases' ? [{ value: 'posted', label: 'Posted' }, { value: 'draft', label: 'Draft' }]
        : type === 'stock' ? [{ value: 'sellable', label: 'Sellable' }, { value: 'remnant', label: 'Remnant' }, { value: 'damaged', label: 'Damaged' }]
            : type === 'installments' ? [{ value: 'overdue', label: 'Overdue' }, { value: 'due_today', label: 'Due today' }, { value: 'upcoming', label: 'Upcoming' }, { value: 'paid', label: 'Paid' }]
                : type === 'advances' ? [{ value: 'customer', label: 'Customer advances' }, { value: 'customer_credit', label: 'Customer return credits' }, { value: 'vendor', label: 'Vendor advances' }, { value: 'vendor_credit', label: 'Vendor return credits' }]
                    : [];
    const params = new URLSearchParams({ report: type, from: p.filters.from ?? '', to: p.filters.to ?? '', status: p.filters.status ?? '', customer_id: p.filters.customer_id ?? '', vendor_id: p.filters.vendor_id ?? '', product_id: p.filters.product_id ?? '' });
    const exportHref = (format: 'csv' | 'xlsx') => `/fabric/export/${type}?${new URLSearchParams({ ...Object.fromEntries(params), format }).toString()}`;
    const moneyKeys = ['amount', 'available', 'original_amount', 'outstanding', 'total', 'paid', 'balance', 'gross', 'discount', 'cost', 'net_sales', 'collections', 'purchases', 'vendor_payments', 'expenses', 'cogs', 'gross_profit', 'net_profit', 'stock_value', 'returns', 'customer_outstanding', 'vendor_outstanding', 'customer_advances', 'vendor_advances', 'customer_credits', 'vendor_credits', 'customer_refunds', 'vendor_returns', 'vendor_refunds'];
    return <>
        <form className="fabric-report-filter" action="/fabric/reports">
            <input type="hidden" name="report" value={type} />
            <Select value={type} onChange={(v) => { window.location.href = `/fabric/reports?report=${encodeURIComponent(v)}`; }} rows={reportTypes} />
            <input type="date" name="from" defaultValue={p.filters.from ?? ''} aria-label="From date" />
            <input type="date" name="to" defaultValue={p.filters.to ?? ''} aria-label="To date" />
            {showCustomer && <select name="customer_id" defaultValue={p.filters.customer_id ?? ''} aria-label="Customer"><option value="">All customers</option>{p.customers.map((r) => <option key={s(r.id)} value={s(r.id)}>{s(r.name)}</option>)}</select>}
            {showVendor && <select name="vendor_id" defaultValue={p.filters.vendor_id ?? ''} aria-label="Vendor"><option value="">All vendors</option>{p.vendors.map((r) => <option key={s(r.id)} value={s(r.id)}>{s(r.shop_name)}</option>)}</select>}
            {showProduct && <select name="product_id" defaultValue={p.filters.product_id ?? ''} aria-label="Product"><option value="">All products</option>{p.products.map((r) => <option key={s(r.id)} value={s(r.id)}>{s(r.name)}</option>)}</select>}
            {statusRows.length > 0 && <select name="status" defaultValue={p.filters.status ?? ''} aria-label="Status"><option value="">All statuses</option>{statusRows.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}</select>}
            <Button type="submit" secondary>Apply</Button>
            <a className="fabric-link-button" href={exportHref('csv')}><FileDown size={16} /> CSV</a>
            <a className="fabric-link-button" href={exportHref('xlsx')}><FileDown size={16} /> XLSX</a>
        </form>
        <SectionCard title={`${reportTypes.find((r) => r.value === type)?.label ?? type} report`}><Table rows={rows} columns={keys.map((key) => ({ label: key.replaceAll('_', ' '), value: (r: Row) => moneyKeys.includes(key) ? money(r[key], currency) : key.endsWith('_at') && r[key] ? new Date(s(r[key])).toLocaleString() : s(r[key]) }))} /></SectionCard>
    </>;
}

function CategoryExistingForm({ row }: { row: Row }) {
    const a = useAction({ name: s(row.name), active: bool(row.active) }, `/fabric/action/category-save/${s(row.id)}`, () => router.reload());
    return <form className="fabric-form" onSubmit={a.submit}><Field label="Category name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><label className="fabric-check"><input type="checkbox" checked={bool(a.data.active)} onChange={(e) => a.setData({ ...a.data, active: e.target.checked })} /> Active</label><p className="fabric-help">A category with active products cannot be archived.</p><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Save category</Button></form>;
}
function ColorExistingForm({ row }: { row: Row }) {
    const a = useAction({ name: s(row.name), hex: s(row.hex), active: bool(row.active) }, `/fabric/action/color-save/${s(row.id)}`, () => router.reload());
    return <form className="fabric-form" onSubmit={a.submit}><Field label="Color name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="Hex color"><input value={a.data.hex} onChange={(e) => a.setData({ ...a.data, hex: e.target.value })} placeholder="#RRGGBB" /></Field><label className="fabric-check"><input type="checkbox" checked={bool(a.data.active)} onChange={(e) => a.setData({ ...a.data, active: e.target.checked })} /> Active</label><p className="fabric-help">A color with stock or quantity awaiting receipt cannot be archived.</p><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Save color</Button></form>;
}
function AccountExistingForm({ row }: { row: Row }) {
    const a = useAction({ name: s(row.name), kind: s(row.kind), opening_balance: (n(row.opening_balance) / 100).toFixed(2), active: bool(row.active) }, `/fabric/action/account-save/${s(row.id)}`, () => router.reload());
    return <form className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Account name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="Type"><Select value={a.data.kind} onChange={(v) => a.setData({ ...a.data, kind: v })} rows={[{ value: 'cash', label: 'Cash' }, { value: 'bank', label: 'Bank' }]} /></Field><Field label="Opening balance"><input value={a.data.opening_balance} onChange={(e) => a.setData({ ...a.data, opening_balance: e.target.value })} /></Field></div><label className="fabric-check"><input type="checkbox" checked={bool(a.data.active)} onChange={(e) => a.setData({ ...a.data, active: e.target.checked })} /> Active</label><p className="fabric-help">After money movement begins, account type/opening balance are locked. A non-zero account cannot be archived.</p><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Save account</Button></form>;
}
function StaffExistingForm({ row }: { row: Row }) {
    const a = useAction({ name: s(row.name), email: s(row.email), role: s(row.fabric_role) || 'cashier', password: '' }, `/fabric/action/staff-save/${s(row.id)}`, () => router.reload());
    return <form className="fabric-form" onSubmit={a.submit}><div className="fabric-form-grid two"><Field label="Name"><input value={a.data.name} onChange={(e) => a.setData({ ...a.data, name: e.target.value })} /></Field><Field label="Email"><input type="email" value={a.data.email} onChange={(e) => a.setData({ ...a.data, email: e.target.value })} /></Field><Field label="Role"><Select value={a.data.role} onChange={(v) => a.setData({ ...a.data, role: v })} rows={[{ value: 'manager', label: 'Manager' }, { value: 'cashier', label: 'Cashier' }]} /></Field><Field label="New password (optional)"><input type="password" minLength={12} value={a.data.password} onChange={(e) => a.setData({ ...a.data, password: e.target.value })} /></Field></div><p className="fabric-help">Saving role/email changes signs this staff account out of existing sessions.</p><ErrorSummary errors={a.errors} /><Button type="submit" disabled={a.processing}>Save staff account</Button></form>;
}
function MasterRecordEditors({ p }: { p: Props }) {
    const [categoryId, setCategoryId] = useState(''); const [colorId, setColorId] = useState(''); const [accountId, setAccountId] = useState('');
    const category = p.categories.find((r) => s(r.id) === categoryId); const color = p.colors.find((r) => s(r.id) === colorId); const account = p.accounts.find((r) => s(r.id) === accountId);
    return <div className="fabric-grid three"><div className="fabric-form"><Field label="Edit category"><Select value={categoryId} onChange={setCategoryId} rows={options(p.categories, (r) => `${s(r.name)}${bool(r.active) ? '' : ' · archived'}`)} /></Field>{category && <CategoryExistingForm key={categoryId} row={category} />}</div><div className="fabric-form"><Field label="Edit color"><Select value={colorId} onChange={setColorId} rows={options(p.colors, (r) => `${s(r.name)}${bool(r.active) ? '' : ' · archived'}`)} /></Field>{color && <ColorExistingForm key={colorId} row={color} />}</div><div className="fabric-form"><Field label="Edit cash / bank account"><Select value={accountId} onChange={setAccountId} rows={options(p.accounts, (r) => `${s(r.name)} · ${s(r.kind)}${bool(r.active) ? '' : ' · archived'}`)} /></Field>{account && <AccountExistingForm key={accountId} row={account} />}</div></div>;
}
function StaffEditor({ p }: { p: Props }) {
    const [id, setId] = useState(''); const row = (p.staff ?? []).find((r) => s(r.id) === id && !bool(r.is_owner));
    return <div className="fabric-form"><Field label="Staff account to edit"><Select value={id} onChange={setId} rows={options((p.staff ?? []).filter((r) => !bool(r.is_owner)), (r) => `${s(r.name)} · ${s(r.fabric_role)}`)} /></Field>{row && <StaffExistingForm key={id} row={row} />}</div>;
}

function Settings({ p }: { p: Props }) {
    const owner = p.role === 'owner'; const fs = p.fabric_settings;
    const settings = useAction({ business_name: s(p.business.name) || 'Fabric POS', currency: s(p.business.currency) || 'PKR', timezone: s(p.business.timezone) || 'Asia/Karachi', country: s(p.business.country) || 'PK', business_phone: s(p.business.phone), business_address: s(p.business.address), reservations_enabled: bool(fs.reservations_enabled), credit_sales_enabled: bool(fs.credit_sales_enabled), barcode_prefix: s(fs.barcode_prefix), default_low_stock: (n(fs.default_low_stock_milli) / 1000).toFixed(3), default_low_roll: (n(fs.default_low_roll_milli) / 1000).toFixed(3), walk_in_name: s(fs.walk_in_name), thermal_width_mm: n(fs.thermal_width_mm) || 80, barcode_width_mm: n(fs.barcode_width_mm) || 70, barcode_height_mm: n(fs.barcode_height_mm) || 40, invoice_title: s(fs.invoice_title) || 'Fabric Sale Invoice', receipt_footer: s(fs.receipt_footer) }, '/fabric/action/settings', () => router.reload());
    const category = useAction({ name: '', active: true }, '/fabric/action/category-save', () => router.reload()); const color = useAction({ name: '', hex: '', active: true }, '/fabric/action/color-save', () => router.reload()); const account = useAction({ name: '', kind: 'cash', opening_balance: '0.00', active: true }, '/fabric/action/account-save', () => router.reload());
    const opening = useAction({ party_type: 'customer', party_id: '', kind: 'receivable', amount: '', effective_at: localInput(s(p.business.timezone) || 'UTC'), reason: '' }, '/fabric/action/opening-balance', () => router.reload());
    const staff = useAction({ name: '', email: '', role: 'cashier', password: '' }, '/fabric/action/staff-save', () => router.reload());
    const partyRows = opening.data.party_type === 'customer' ? options(p.customers, (r) => `${s(r.name)} · ${s(r.phone)}`) : options(p.vendors, (r) => s(r.shop_name));
    const kinds = opening.data.party_type === 'customer' ? [{ value: 'receivable', label: 'Customer owes us' }, { value: 'advance', label: 'Customer advance / credit' }] : [{ value: 'payable', label: 'We owe vendor' }, { value: 'advance', label: 'Vendor advance paid' }];
    return <>
        {owner && <div className="fabric-grid two">
            <SectionCard title="Fabric POS settings"><form className="fabric-form" onSubmit={settings.submit}><ErrorSummary errors={settings.errors} /><div className="fabric-form-grid two"><Field label="Business name"><input value={settings.data.business_name} onChange={(e) => settings.setData({ ...settings.data, business_name: e.target.value })} /></Field><Field label="Currency"><input maxLength={3} value={settings.data.currency} onChange={(e) => settings.setData({ ...settings.data, currency: e.target.value.toUpperCase() })} /></Field><Field label="Timezone"><input value={settings.data.timezone} onChange={(e) => settings.setData({ ...settings.data, timezone: e.target.value })} /></Field><Field label="Country code"><input maxLength={2} value={settings.data.country} onChange={(e) => settings.setData({ ...settings.data, country: e.target.value.toUpperCase() })} /></Field><Field label="Business phone"><input value={settings.data.business_phone} onChange={(e) => settings.setData({ ...settings.data, business_phone: e.target.value })} /></Field><Field label="Business address"><input value={settings.data.business_address} onChange={(e) => settings.setData({ ...settings.data, business_address: e.target.value })} /></Field><Field label="Barcode prefix"><input value={settings.data.barcode_prefix} onChange={(e) => settings.setData({ ...settings.data, barcode_prefix: e.target.value })} /></Field><Field label="Walk-in customer label"><input value={settings.data.walk_in_name} onChange={(e) => settings.setData({ ...settings.data, walk_in_name: e.target.value })} /></Field><Field label="Default low-stock level"><input value={settings.data.default_low_stock} onChange={(e) => settings.setData({ ...settings.data, default_low_stock: e.target.value })} /></Field><Field label="Default low-roll level"><input value={settings.data.default_low_roll} onChange={(e) => settings.setData({ ...settings.data, default_low_roll: e.target.value })} /></Field><Field label="Thermal receipt width (mm)"><input type="number" min="58" max="120" value={settings.data.thermal_width_mm} onChange={(e) => settings.setData({ ...settings.data, thermal_width_mm: Number(e.target.value) })} /></Field><Field label="Barcode label width (mm)"><input type="number" min="30" max="150" value={settings.data.barcode_width_mm} onChange={(e) => settings.setData({ ...settings.data, barcode_width_mm: Number(e.target.value) })} /></Field><Field label="Barcode label height (mm)"><input type="number" min="20" max="100" value={settings.data.barcode_height_mm} onChange={(e) => settings.setData({ ...settings.data, barcode_height_mm: Number(e.target.value) })} /></Field><Field label="Invoice title"><input value={settings.data.invoice_title} onChange={(e) => settings.setData({ ...settings.data, invoice_title: e.target.value })} /></Field></div><Field label="Receipt footer"><textarea value={settings.data.receipt_footer} onChange={(e) => settings.setData({ ...settings.data, receipt_footer: e.target.value })} /></Field><label className="fabric-check"><input type="checkbox" checked={bool(settings.data.reservations_enabled)} onChange={(e) => settings.setData({ ...settings.data, reservations_enabled: e.target.checked })} /> Enable roll reservations</label><label className="fabric-check"><input type="checkbox" checked={bool(settings.data.credit_sales_enabled)} onChange={(e) => settings.setData({ ...settings.data, credit_sales_enabled: e.target.checked })} /> Allow authorized credit sales</label><Button type="submit" disabled={settings.processing}>Save settings</Button></form></SectionCard>
            <SectionCard title="Master records"><div className="fabric-master-forms"><form onSubmit={category.submit}><input placeholder="New category" value={category.data.name} onChange={(e) => category.setData({ ...category.data, name: e.target.value })} /><Button type="submit">Add category</Button></form><form onSubmit={color.submit}><input placeholder="New color" value={color.data.name} onChange={(e) => color.setData({ ...color.data, name: e.target.value })} /><input placeholder="#RRGGBB" value={color.data.hex} onChange={(e) => color.setData({ ...color.data, hex: e.target.value })} /><Button type="submit">Add color</Button></form><form onSubmit={account.submit}><input placeholder="Cash / bank name" value={account.data.name} onChange={(e) => account.setData({ ...account.data, name: e.target.value })} /><Select value={account.data.kind} onChange={(v) => account.setData({ ...account.data, kind: v })} rows={[{ value: 'cash', label: 'Cash' }, { value: 'bank', label: 'Bank' }]} /><input placeholder="Opening amount" value={account.data.opening_balance} onChange={(e) => account.setData({ ...account.data, opening_balance: e.target.value })} /><Button type="submit">Add account</Button></form></div></SectionCard>
            <SectionCard title="Edit master records"><MasterRecordEditors p={p} /></SectionCard>
            <SectionCard title="Opening customer/vendor balance"><form className="fabric-form" onSubmit={opening.submit}><ErrorSummary errors={opening.errors} /><div className="fabric-form-grid two"><Field label="Party type"><Select value={opening.data.party_type} onChange={(v) => opening.setData({ ...opening.data, party_type: v, party_id: '', kind: v === 'customer' ? 'receivable' : 'payable' })} rows={[{ value: 'customer', label: 'Customer' }, { value: 'vendor', label: 'Vendor' }]} /></Field><Field label="Party"><Select value={opening.data.party_id} onChange={(v) => opening.setData({ ...opening.data, party_id: v })} rows={partyRows} /></Field><Field label="Balance type"><Select value={opening.data.kind} onChange={(v) => opening.setData({ ...opening.data, kind: v })} rows={kinds} /></Field><Field label="Amount"><input value={opening.data.amount} onChange={(e) => opening.setData({ ...opening.data, amount: e.target.value })} /></Field><Field label="Effective date"><input type="datetime-local" value={opening.data.effective_at} onChange={(e) => opening.setData({ ...opening.data, effective_at: e.target.value })} /></Field></div><Field label="Reason"><textarea value={opening.data.reason} onChange={(e) => opening.setData({ ...opening.data, reason: e.target.value })} /></Field><Button type="submit">Record opening balance</Button></form></SectionCard>
            <SectionCard title="Manager / cashier access"><form className="fabric-form" onSubmit={staff.submit}><ErrorSummary errors={staff.errors} /><div className="fabric-form-grid two"><Field label="Name"><input value={staff.data.name} onChange={(e) => staff.setData({ ...staff.data, name: e.target.value })} /></Field><Field label="Email"><input type="email" value={staff.data.email} onChange={(e) => staff.setData({ ...staff.data, email: e.target.value })} /></Field><Field label="Role"><Select value={staff.data.role} onChange={(v) => staff.setData({ ...staff.data, role: v })} rows={[{ value: 'manager', label: 'Manager' }, { value: 'cashier', label: 'Cashier' }]} /></Field><Field label="Temporary password"><input type="password" minLength={12} value={staff.data.password} onChange={(e) => staff.setData({ ...staff.data, password: e.target.value })} /></Field></div><Button type="submit">Create staff login</Button></form><hr className="fabric-divider" /><StaffEditor p={p} /></SectionCard>
        </div>}
        {owner && <div className="fabric-grid two"><SectionCard title="Fabric categories"><Table rows={p.categories} columns={[{ label: 'Category', value: (r) => <strong>{s(r.name)}</strong> }, { label: 'State', value: (r) => <Status value={bool(r.active) ? 'active' : 'archived'} /> }, { label: 'Action', value: (r) => <InlineAction label={bool(r.active) ? 'Archive' : 'Reactivate'} url={`/fabric/action/category-toggle/${s(r.id)}`} /> }]} /></SectionCard><SectionCard title="Colors"><Table rows={p.colors} columns={[{ label: 'Color', value: (r) => <strong>{s(r.name)}</strong> }, { label: 'Hex', value: (r) => s(r.hex) || '—' }, { label: 'State', value: (r) => <Status value={bool(r.active) ? 'active' : 'archived'} /> }, { label: 'Action', value: (r) => <InlineAction label={bool(r.active) ? 'Archive' : 'Reactivate'} url={`/fabric/action/color-toggle/${s(r.id)}`} /> }]} /></SectionCard></div>}
        <SectionCard title="Opening balances"><Table rows={p.opening_balances ?? []} columns={[{ label: 'Party', value: (r) => `${s(r.party_type)} #${s(r.party_id)}` }, { label: 'Type', value: (r) => s(r.kind) }, { label: 'Original', value: (r) => money(r.amount, s(p.business.currency)) }, { label: 'Remaining', value: (r) => money(r.remaining, s(p.business.currency)) }, { label: 'Reason', value: (r) => s(r.reason) }]} /></SectionCard>
        {owner && <SectionCard title="Staff accounts"><Table rows={p.staff ?? []} columns={[{ label: 'Name', value: (r) => s(r.name) }, { label: 'Email', value: (r) => s(r.email) }, { label: 'Role', value: (r) => bool(r.is_owner) ? 'Owner' : s(r.fabric_role) }]} /></SectionCard>}
        <SectionCard title="Fabric audit history"><Table rows={p.audit ?? []} columns={[{ label: 'Date', value: (r) => new Date(s(r.created_at)).toLocaleString() }, { label: 'Entity', value: (r) => s(r.entity) }, { label: 'ID', value: (r) => s(r.entity_id) }, { label: 'Action', value: (r) => s(r.action) }]} /></SectionCard>
    </>;
}

export default function FabricIndex(p: Props) {
    let content: ReactNode;
    switch (p.section) {
        case 'pos': content = <Pos p={p} />; break;
        case 'customers': content = <Customers p={p} />; break;
        case 'vendors': content = <Vendors p={p} />; break;
        case 'purchases': content = <Purchases p={p} />; break;
        case 'inventory': content = <Inventory p={p} />; break;
        case 'returns': content = <Returns p={p} />; break;
        case 'cash-bank': content = <CashBank p={p} />; break;
        case 'expenses': content = <Expenses p={p} />; break;
        case 'promotions': content = <Promotions p={p} />; break;
        case 'marketing': content = <Marketing p={p} />; break;
        case 'whatsapp-bulk': content = <WhatsAppBulk p={p} />; break;
        case 'reports': content = <Reports p={p} />; break;
        case 'settings': content = <Settings p={p} />; break;
        default: content = <Dashboard p={p} />;
    }
    return <Layout p={p}>{content}</Layout>;
}

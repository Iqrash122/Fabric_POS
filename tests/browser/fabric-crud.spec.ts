import { expect, test, type Locator, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

test.use({ actionTimeout: 10_000 });

async function login(page: Page) {
    const c = JSON.parse(readFileSync(process.env.FABRIC_TEST_CREDENTIALS!, 'utf8'));
    await page.goto('/login');
    await page.getByLabel('Email address').fill(c.email);
    await page.getByLabel('Password', { exact: true }).fill(c.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/fabric/);
}

function card(page: Page, title: string) {
    return page.locator('.fabric-card').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

async function panel(page: Page, title: string) {
    await page.getByRole('button', { name: title, exact: true }).click();
    return page.getByRole('dialog', { name: title, exact: true });
}

async function close(panel: Locator, title: string) {
    await panel.getByRole('button', { name: `Close ${title}`, exact: true }).click();
    await expect(panel).not.toBeVisible();
}

async function fill(scope: Page | Locator, fields: Record<string, string>) {
    for (const [name, value] of Object.entries(fields)) await scope.getByLabel(name, { exact: true }).fill(value);
}

async function save(page: Page, scope: Page | Locator, button: string | RegExp) {
    // Follow this form's POST redirect, not a background reload from a previous save.
    const response = page.waitForResponse((r) => r.request().method() === 'GET' && r.request().redirectedFrom()?.method() === 'POST' && r.headers()['x-inertia'] === 'true');
    await scope.getByRole('button', { name: button, exact: typeof button === 'string' }).click();
    const data = await (await response).json();
    expect(data.props.errors, `Errors after ${String(button)}`).toEqual({});
    await page.waitForLoadState('networkidle');
    return data.props;
}

test.beforeEach(async ({ page }) => {
    page.on('dialog', (d) => d.accept());
    await login(page);
});

test('settings, master record creation/edit/archive, staff and every settings field persist', async ({ page }) => {
    test.setTimeout(90_000);
    await page.goto('/fabric/settings');
    const settings = card(page, 'Fabric POS settings');
    await fill(settings, {
        'Business name': 'QA Fabric Store', Currency: 'PKR', Timezone: 'Asia/Karachi', 'Country code': 'PK',
        'Business phone': '03001234567', 'Business address': 'QA Main Market', 'Barcode prefix': 'QA',
        'Walk-in customer label': 'Retail customer', 'Default low-stock level': '4.500', 'Default low-roll level': '1.250',
        'Thermal receipt width (mm)': '80', 'Barcode label width (mm)': '70', 'Barcode label height (mm)': '40',
        'Invoice title': 'QA Fabric Invoice', 'Receipt footer': 'QA receipt footer',
    });
    await settings.getByLabel('Enable roll reservations').check();
    await settings.getByLabel('Allow authorized credit sales').check();
    let props = await save(page, settings, 'Save settings');
    expect(props.business).toMatchObject({ name: 'QA Fabric Store', phone: '03001234567', address: 'QA Main Market', timezone: 'Asia/Karachi', currency: 'PKR', country: 'PK' });
    expect(props.fabric_settings).toMatchObject({ barcode_prefix: 'QA', default_low_stock_milli: 4500, default_low_roll_milli: 1250, invoice_title: 'QA Fabric Invoice', receipt_footer: 'QA receipt footer' });
    const master = card(page, 'Master records');
    const key = Date.now().toString();
    await master.getByPlaceholder('New category').fill(`QA category ${key}`);
    props = await save(page, master, 'Add category');
    const category = props.categories.find((r: any) => r.name === `QA category ${key}`);
    await master.getByPlaceholder('New color').fill(`QA color ${key}`);
    await master.getByPlaceholder('#RRGGBB').fill('#123ABC');
    props = await save(page, master, 'Add color');
    const color = props.colors.find((r: any) => r.name === `QA color ${key}`);
    await master.getByPlaceholder('Cash / bank name').fill(`QA account ${key}`);
    await master.locator('select').selectOption('bank');
    await master.getByPlaceholder('Opening amount').fill('0.00');
    props = await save(page, master, 'Add account');
    const account = props.accounts.find((r: any) => r.name === `QA account ${key}`);
    const editors = card(page, 'Edit master records');
    await editors.getByRole('combobox', { name: 'Edit category', ...{ exact: true } }).selectOption(String(category.id));
    let form = editors.locator('form').filter({ has: page.getByLabel('Category name', { exact: true }) });
    await form.getByLabel('Category name', { exact: true }).fill(`Edited category ${key}`);
    await form.getByLabel('Active', { exact: true }).uncheck();
    props = await save(page, form, 'Save category');
    expect(props.categories.find((r: any) => r.id === category.id)).toMatchObject({ name: `Edited category ${key}`, active: 0 });
    const categoryRow = card(page, 'Fabric categories').locator('tr').filter({ hasText: `Edited category ${key}` });
    await save(page, categoryRow, 'Reactivate');
    await editors.getByRole('combobox', { name: 'Edit color', ...{ exact: true } }).selectOption(String(color.id));
    form = editors.locator('form').filter({ has: page.getByLabel('Color name', { exact: true }) });
    await fill(form, { 'Color name': `Edited color ${key}`, 'Hex color': '#456DEF' });
    await form.getByLabel('Active', { exact: true }).uncheck();
    props = await save(page, form, 'Save color');
    expect(props.colors.find((r: any) => r.id === color.id)).toMatchObject({ hex: '#456DEF', active: 0 });
    await save(page, card(page, 'Colors').locator('tr').filter({ hasText: `Edited color ${key}` }), 'Reactivate');
    await editors.getByRole('combobox', { name: 'Edit cash / bank account' }).selectOption(String(account.id));
    form = editors.locator('form').filter({ has: page.getByLabel('Account name', { exact: true }) });
    await form.getByLabel('Account name').fill(`Edited account ${key}`);
    await form.getByRole('combobox', { name: 'Type', ...{ exact: true } }).selectOption('cash');
    await form.getByLabel('Active', { exact: true }).uncheck();
    props = await save(page, form, 'Save account');
    expect(props.accounts.find((r: any) => r.id === account.id)).toMatchObject({ name: `Edited account ${key}`, kind: 'cash', active: 0 });
    const staff = card(page, 'Manager / cashier access');
    const create = staff.locator('form').first();
    await fill(create, { Name: 'QA Staff', Email: `qa-${key}@example.invalid`, 'Temporary password': 'QA-password-123456' });
    await create.getByRole('combobox', { name: 'Role', ...{ exact: true } }).selectOption('cashier');
    props = await save(page, create, 'Create staff login');
    const user = props.staff.find((r: any) => r.email === `qa-${key}@example.invalid`);
    await staff.getByRole('combobox', { name: 'Staff account to edit' }).selectOption(String(user.id));
    form = staff.locator('form').last();
    await fill(form, { Name: 'Edited QA Staff', Email: `qa-edited-${key}@example.invalid`, 'New password (optional)': 'QA-new-password-123456' });
    await form.getByRole('combobox', { name: 'Role', ...{ exact: true } }).selectOption('manager');
    props = await save(page, form, 'Save staff account');
    expect(props.staff.find((r: any) => r.id === user.id)).toMatchObject({ name: 'Edited QA Staff', fabric_role: 'manager' });
});

test('product fields, POS opening stock shortcut, quantity edits, reservations and physical counts', async ({ page }) => {
    test.setTimeout(90_000);
    const key = Date.now().toString();
    const name = `QA Cloth ${key}`;
    await page.goto('/fabric/inventory');
    let dialog = await panel(page, 'New product');
    await fill(dialog, { 'Fabric / product name': name, SKU: `QA-${key}`, 'Product barcode': `BC-${key}`, 'Default selling price': '50.00', 'Low stock threshold': '2.500' });
    await dialog.getByRole('combobox', { name: 'Category', ...{ exact: true } }).selectOption({ label: 'General Fabric' });
    await dialog.getByRole('combobox', { name: 'Selling unit' }).selectOption('meter');
    let props = await save(page, dialog, 'Save product');
    const product = props.products.find((r: any) => r.name === name);
    await close(dialog, 'New product');
    dialog = await panel(page, 'Edit product');
    await dialog.getByRole('combobox', { name: 'Product to edit' }).selectOption(String(product.id));
    await dialog.getByLabel('Default selling price').fill('55.00');
    props = await save(page, dialog, 'Save product changes');
    expect(props.products.find((r: any) => r.id === product.id).default_selling_price).toBe(5500);
    await close(dialog, 'Edit product');
    await page.goto('/fabric/pos');
    await page.getByRole('textbox', { name: 'Barcode or search' }).fill(`BC-${key}`);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByText(/no stock quantity has been recorded/)).toBeVisible();
    dialog = await panel(page, 'Add opening stock');
    await expect(dialog.getByRole('combobox', { name: 'Product', exact: true })).toHaveValue(String(product.id));
    await expect(dialog.getByLabel('Selling price')).toHaveValue('55.00');
    await fill(dialog, { 'Opening quantity': '12.500', 'Cost per meter / piece': '20.00', 'Roll number (optional)': `QR-${key}`, 'Barcode (optional)': `QRBC-${key}`, 'Opening-stock reason': 'Physical count at setup' });
    props = await save(page, dialog, 'Record opening roll');
    const roll = props.rolls.find((r: any) => r.roll_number === `QR-${key}`);
    await expect(dialog).not.toBeVisible();
    await page.getByRole('textbox', { name: 'Barcode or search' }).fill(`BC-${key}`);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.locator('.pos-line')).toHaveCount(1);
    await page.getByLabel('Quantity', { exact: true }).fill('13');
    await page.getByRole('button', { name: /^Post sale/ }).click();
    await expect(page.getByText(/Quantity \(13\) exceeds available stock/)).toBeVisible();
    await page.getByLabel('Quantity', { exact: true }).fill('2.500');
    await page.getByLabel('Unit price', { exact: true }).fill('55.00');
    await page.getByLabel('Manual discount', { exact: true }).fill('2.50');
    await page.getByLabel('Manual discount reason', { exact: true }).fill('Approved QA discount');
    await page.getByRole('combobox', { name: 'Payment account 1', ...{ exact: true } }).selectOption({ label: 'Cash · cash' });
    await page.getByLabel('Paid on account 1', { exact: true }).fill('100.00');
    await page.getByRole('combobox', { name: 'Payment account 2 (optional)' }).selectOption({ label: 'Bank · bank' });
    await page.getByLabel('Paid on account 2', { exact: true }).fill('35.00');
    props = await save(page, page, /^Post sale/);
    expect(props.rolls.find((r: any) => r.id === roll.id).current_qty_milli).toBe(10000);
    await expect(page.locator('.pos-line')).toHaveCount(0);
    await page.goto('/fabric/inventory');
    dialog = await panel(page, 'Stock adjustment');
    await dialog.getByRole('combobox', { name: 'Roll', ...{ exact: true } }).selectOption(String(roll.id));
    await fill(dialog, { 'New physical quantity': '9.500', Reason: 'Measured shrinkage' });
    props = await save(page, dialog, 'Post adjustment');
    expect(props.rolls.find((r: any) => r.id === roll.id).current_qty_milli).toBe(9500);
    await close(dialog, 'Stock adjustment');
    dialog = await panel(page, 'Reserve stock');
    await dialog.getByRole('combobox', { name: 'Roll', ...{ exact: true } }).selectOption(String(roll.id));
    await fill(dialog, { Quantity: '1.500', Note: 'QA reserve' });
    props = await save(page, dialog, 'Reserve stock');
    expect(props.rolls.find((r: any) => r.id === roll.id).available_qty_milli).toBe(8000);
    const reservation = props.reservations.find((r: any) => r.roll_id === roll.id);
    await close(dialog, 'Reserve stock');
    const reservedRow = card(page, 'Active reservations').locator('tbody tr').filter({ has: page.locator(`button`) }).filter({ hasText: String(reservation.roll_id) });
    await save(page, reservedRow.first(), 'Release');
    dialog = await panel(page, 'Physical count');
    await dialog.getByRole('combobox', { name: 'Roll', ...{ exact: true } }).selectOption(String(roll.id));
    await fill(dialog, { 'Measured quantity': '9.000', 'Difference reason': 'Recount', 'Count note': 'QA physical count' });
    await save(page, dialog, 'Save draft count');
    await close(dialog, 'Physical count');
    props = await save(page, card(page, 'Physical counts').locator('tbody tr').first(), 'Post adjustments');
    expect(props.rolls.find((r: any) => r.id === roll.id).current_qty_milli).toBe(9000);
});

test('vendor fields persist through create edit archive and reactivate', async ({ page }) => {
    const key = Date.now().toString();
    await page.goto('/fabric/vendors');
    let dialog = await panel(page, 'New vendor');
    await fill(dialog, { 'Shop name': `QA Vendor ${key}`, 'Owner name': 'QA Owner', Phone: '03001234567', Email: `vendor-${key}@example.invalid`, Address: 'Warehouse 5' });
    let props = await save(page, dialog, 'Save vendor');
    const vendor = props.vendors.find((r: any) => r.shop_name === `QA Vendor ${key}`);
    expect(vendor).toMatchObject({ owner_name: 'QA Owner', phone: '03001234567', address: 'Warehouse 5' });
    await close(dialog, 'New vendor');
    dialog = await panel(page, 'Edit vendor');
    await dialog.getByRole('combobox', { name: 'Vendor to edit' }).selectOption(String(vendor.id));
    await fill(dialog, { 'Shop name': `Edited Vendor ${key}`, 'Owner name': 'Edited Owner', Phone: '03007654321', Email: `edited-${key}@example.invalid`, Address: 'Warehouse 6', 'Internal note': 'Edited instructions' });
    props = await save(page, dialog, 'Save vendor changes');
    expect(props.vendors.find((r: any) => r.id === vendor.id)).toMatchObject({ shop_name: `Edited Vendor ${key}`, phone: '03007654321', address: 'Warehouse 6', notes: 'Edited instructions' });
    await close(dialog, 'Edit vendor');
    const row = card(page, 'Vendor accounts').locator('tr').filter({ hasText: `Edited Vendor ${key}` });
    await save(page, row, 'Archive');
    await expect(row.getByText('archived', { exact: true })).toBeVisible();
    await save(page, row, 'Reactivate');
    await expect(row.getByText('active', { exact: true })).toBeVisible();
});

test('vendor row payment settles bills, separates advances and updates payment history and cash', async ({ page }) => {
    test.setTimeout(120_000);
    const key = Date.now().toString();
    const vendorName = `Payment Vendor ${key}`;
    await page.goto('/fabric/vendors');
    let dialog = await panel(page, 'New vendor');
    await fill(dialog, { 'Shop name': vendorName });
    let props = await save(page, dialog, 'Save vendor');
    const vendor = props.vendors.find((r: any) => r.shop_name === vendorName);
    await close(dialog, 'New vendor');
    dialog = await panel(page, 'New vendor');
    await fill(dialog, { 'Shop name': `Advance Vendor ${key}` });
    props = await save(page, dialog, 'Save vendor');
    const other = props.vendors.find((r: any) => r.shop_name === `Advance Vendor ${key}`);
    await close(dialog, 'New vendor');
    await page.goto('/fabric/inventory');
    dialog = await panel(page, 'New product');
    await fill(dialog, { 'Fabric / product name': `Payment Cloth ${key}`, SKU: `PAY-${key}`, 'Default selling price': '20', 'Low stock threshold': '0' });
    props = await save(page, dialog, 'Save product');
    const product = props.products.find((r: any) => r.name === `Payment Cloth ${key}`);
    await close(dialog, 'New product');
    await page.goto('/fabric/purchases');
    for (let i = 0; i < 2; i++) {
        dialog = await panel(page, 'New purchase');
        await dialog.getByRole('combobox', { name: 'Vendor', exact: true }).selectOption(String(vendor.id));
        const line = dialog.locator('.purchase-line').first();
        await line.locator('select').nth(0).selectOption(String(product.id));
        await line.locator('select').nth(1).selectOption({ label: 'Unspecified' });
        await line.getByPlaceholder('Qty', { exact: true }).fill('10');
        await line.getByPlaceholder('Purchase rate').fill('10');
        await save(page, dialog, 'Create purchase bill');
        await close(dialog, 'New purchase');
        const row = page.locator('tbody tr').filter({ hasText: vendorName }).filter({ hasText: 'draft' });
        await save(page, row, 'Post bill');
    }
    let cashResponse = page.waitForResponse((r) => r.url().endsWith('/fabric/cash-bank') && r.headers()['x-inertia'] === 'true');
    await page.getByRole('link', { name: 'Cash / Bank', exact: true }).click();
    const before = (await (await cashResponse).json()).props.account_balances.find((r: any) => r.name === 'Cash').balance;
    await page.goto('/fabric/vendors');
    const vendorRow = card(page, 'Vendor accounts').locator('tbody tr').filter({ hasText: vendorName });
    await vendorRow.getByRole('button', { name: 'Pay vendor', exact: true }).click();
    dialog = page.getByRole('dialog', { name: 'Pay vendor', exact: true });
    await expect(dialog.getByRole('combobox', { name: 'Vendor', exact: true })).toHaveValue(String(vendor.id));
    await expect(dialog.getByRole('status')).toContainText('Payable: PKR 200.00');
    await expect(dialog.getByRole('combobox', { name: 'Payment purpose' })).toHaveValue('outstanding');
    await fill(dialog, { Amount: '125', Note: 'First vendor payment' });
    props = await save(page, dialog, 'Record vendor payment');
    expect(props.vendor_accounts.find((r: any) => r.id === vendor.id).account).toMatchObject({ payable: 7500, advance: 0 });
    expect(props.vendor_payments[0]).toMatchObject({ vendor_name: vendorName, amount: 12500, allocated: 12500, available: 0, account_name: 'Cash' });
    await expect(dialog.getByLabel('Amount', { exact: true })).toHaveValue('');
    await expect(dialog.getByLabel('Note', { exact: true })).toHaveValue('');
    await dialog.getByRole('combobox', { name: 'Payment purpose' }).selectOption('bill');
    await dialog.getByRole('combobox', { name: 'Purchase bill', exact: true }).selectOption({ index: 1 });
    await expect(dialog.getByLabel('Amount', { exact: true })).toHaveValue('75.00');
    await dialog.getByRole('combobox', { name: 'Vendor', exact: true }).selectOption(String(other.id));
    await expect(dialog.getByRole('combobox', { name: 'Purchase bill', exact: true })).toHaveValue('');
    await expect(dialog.getByLabel('Amount', { exact: true })).toHaveValue('');
    await expect(dialog.getByRole('button', { name: 'Record vendor payment' })).toBeDisabled();
    await dialog.getByRole('combobox', { name: 'Vendor', exact: true }).selectOption(String(vendor.id));
    await dialog.getByRole('combobox', { name: 'Purchase bill', exact: true }).selectOption({ index: 1 });
    await fill(dialog, { Amount: '100', Note: 'Bill settled plus advance' });
    props = await save(page, dialog, 'Record vendor payment');
    expect(props.vendor_accounts.find((r: any) => r.id === vendor.id).account).toMatchObject({ payable: 0, advance: 2500 });
    expect(props.vendor_payments[0]).toMatchObject({ amount: 10000, allocated: 7500, available: 2500 });
    await dialog.getByRole('combobox', { name: 'Payment purpose' }).selectOption('outstanding');
    await expect(dialog.getByText(/No unpaid balance/)).toBeVisible();
    await dialog.getByRole('combobox', { name: 'Vendor', exact: true }).selectOption(String(other.id));
    await dialog.getByRole('combobox', { name: 'Payment purpose' }).selectOption('advance');
    await fill(dialog, { Amount: '30', Note: 'Explicit advance without bill' });
    props = await save(page, dialog, 'Record vendor payment');
    expect(props.vendor_accounts.find((r: any) => r.id === other.id).account).toMatchObject({ payable: 0, advance: 3000 });
    await close(dialog, 'Pay vendor');
    await expect(card(page, 'Recent vendor payments').getByText('Bill settled plus advance', { exact: true })).toBeVisible();
    cashResponse = page.waitForResponse((r) => r.url().endsWith('/fabric/cash-bank') && r.headers()['x-inertia'] === 'true');
    await page.getByRole('link', { name: 'Cash / Bank', exact: true }).click();
    expect((await (await cashResponse).json()).props.account_balances.find((r: any) => r.name === 'Cash').balance).toBe(before - 25500);
    await page.goto('/fabric/vendors');
    await page.setViewportSize({ width: 390, height: 844 });
    await vendorRow.getByRole('button', { name: 'Pay vendor', exact: true }).click();
    await dialog.getByRole('button', { name: 'Record vendor payment' }).scrollIntoViewIfNeeded();
    await expect(dialog.getByRole('button', { name: 'Record vendor payment' })).toBeInViewport();
    await page.screenshot({ path: 'test-results/vendor-payment-mobile.png' });
    await close(dialog, 'Pay vendor');
});

test('expense attachment, account transfer, promotion fields and promotion edit work', async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto('/fabric/expenses');
    let scope = card(page, 'Post operating expense');
    await fill(scope, { Category: 'QA Rent', Amount: '15.75', Note: 'Test receipt expense' });
    await scope.getByRole('combobox', { name: 'Cash / bank' }).selectOption({ label: 'Cash' });
    await scope.getByLabel('Private receipt').setInputFiles({ name: 'qa-receipt.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nQA receipt') });
    let props = await save(page, scope, 'Post expense');
    expect(props.expenses[0]).toMatchObject({ category: 'QA Rent', amount: 1575, note: 'Test receipt expense' });
    const attachment = await page.request.get(`/fabric/expense-receipt/${props.expenses[0].id}`);
    expect(attachment.ok()).toBeTruthy();
    await page.goto('/fabric/cash-bank');
    scope = card(page, 'Transfer between accounts');
    await scope.locator('select').nth(0).selectOption({ label: 'Cash' });
    await scope.locator('select').nth(1).selectOption({ label: 'Bank' });
    await scope.getByPlaceholder('Amount').fill('10.25');
    props = await save(page, scope, 'Transfer');
    expect(props.transfers[0].amount).toBe(1025);
    await page.goto('/fabric/promotions');
    let dialog = await panel(page, 'New promotion');
    const key = Date.now().toString();
    await fill(dialog, { Name: `QA Promo ${key}`, 'Discount %': '10.00', Priority: '2', Starts: '2030-01-01T10:00', Ends: '2030-01-02T10:00' });
    await dialog.getByRole('combobox', { name: 'Target group' }).selectOption('product');
    await dialog.getByRole('listbox', { name: 'Promotion targets' }).selectOption({ index: 0 });
    await dialog.getByLabel('Allow stacking with lower-priority promotions').check();
    props = await save(page, dialog, 'Create promotion');
    const promo = props.promotions.find((r: any) => r.name === `QA Promo ${key}`);
    expect(Number(promo.percent)).toBe(10);
    expect(promo.targets).toHaveLength(1);
    await close(dialog, 'New promotion');
    dialog = await panel(page, 'Edit promotion');
    await dialog.getByRole('combobox', { name: 'Promotion to edit' }).selectOption(String(promo.id));
    await fill(dialog, { Name: `Edited Promo ${key}`, 'Discount %': '12.50', Priority: '3' });
    await dialog.getByRole('combobox', { name: 'Target group' }).selectOption('color');
    await dialog.getByRole('listbox', { name: 'Promotion targets' }).selectOption({ label: 'Unspecified' });
    await dialog.getByLabel('Allow stacking with lower-priority promotions').uncheck();
    await dialog.getByLabel('Promotion active').uncheck();
    props = await save(page, dialog, 'Save promotion changes');
    expect(Number(props.promotions.find((r: any) => r.id === promo.id).percent)).toBe(12.5);
    await close(dialog, 'Edit promotion');
    await save(page, card(page, 'Promotion schedule').locator('tr').filter({ hasText: `Edited Promo ${key}` }), 'Activate');
});

test('marketing consent fields import optout and campaign preparation work without sending messages', async ({ page }) => {
    await page.goto('/fabric/marketing');
    const contact = card(page, 'Consent contact');
    await fill(contact, { Phone: '03001112222', Name: 'QA Contact', 'WhatsApp consent source': 'Signed paper', 'SMS consent source': 'Counter consent' });
    await contact.getByLabel('WhatsApp consent recorded').check();
    await contact.getByLabel('SMS consent recorded').check();
    let props = await save(page, contact, 'Save contact');
    expect(props.contacts.find((r: any) => r.phone === '+923001112222')).toMatchObject({ name: 'QA Contact', whatsapp_consent_source: 'Signed paper', sms_consent_source: 'Counter consent' });
    await contact.getByLabel('Name', { exact: true }).fill('Edited QA Contact');
    await contact.getByLabel('SMS consent recorded').uncheck();
    props = await save(page, contact, 'Save contact');
    expect(props.contacts.find((r: any) => r.phone === '+923001112222').sms_consent).toBe(0);
    const importer = card(page, 'Import contacts');
    await importer.locator('input[type=file]').setInputFiles({ name: 'contacts.csv', mimeType: 'text/csv', buffer: Buffer.from('phone,name\n03003334444,Imported QA\n') });
    props = await save(page, importer, 'Import CSV');
    expect(props.contacts.find((r: any) => r.phone === '+923003334444').name).toBe('Imported QA');
    const campaign = card(page, 'Prepare campaign');
    await fill(campaign, { 'Campaign name': 'QA Campaign', Message: 'QA message draft', 'Provider account': 'QA inactive provider' });
    await campaign.getByRole('combobox', { name: 'Channel' }).selectOption('sms');
    props = await save(page, campaign, 'Save draft campaign');
    expect(props.campaigns[0]).toMatchObject({ name: 'QA Campaign', channel: 'sms', message: 'QA message draft', state: 'draft' });
});

for (const party of ['vendor', 'customer'] as const) {
    test(`${party} previous udhaar, advance adjustment and later payment reconcile`, async ({ page }) => {
        test.setTimeout(90_000);
        const vendor = party === 'vendor';
        const key = Date.now().toString();
        const name = `Previous ${party} ${key}`;
        await page.goto(vendor ? '/fabric/vendors' : '/fabric/pos');
        let title = vendor ? 'New vendor' : 'Quick customer';
        let dialog = await panel(page, title);
        await dialog.getByLabel(vendor ? 'Shop name' : 'Customer name', { exact: true }).fill(name);
        if (!vendor) await dialog.getByLabel('Phone', { exact: true }).fill(`0300${key.slice(-7)}`);
        let props = await save(page, dialog, vendor ? 'Save vendor' : 'Create customer');
        const person = (vendor ? props.vendors : props.customers).find((r: any) => (vendor ? r.shop_name : r.name) === name);
        await close(dialog, title);
        await page.goto(`/fabric/${party}s`);
        title = vendor ? 'Vendor payment' : 'Record later collection';
        dialog = await panel(page, title);
        await dialog.getByRole('combobox', { name: vendor ? 'Vendor' : 'Customer', exact: true }).selectOption(String(person.id));
        await dialog.getByRole('combobox', { name: vendor ? 'Payment purpose' : 'Collection purpose' }).selectOption('advance');
        await fill(dialog, { Amount: '20' });
        props = await save(page, dialog, vendor ? 'Record vendor payment' : 'Record collection');
        await close(dialog, title);
        dialog = await panel(page, 'Add previous udhaar');
        await dialog.getByRole('combobox', { name: vendor ? 'Vendor' : 'Customer', exact: true }).selectOption(String(person.id));
        await fill(dialog, { [vendor ? 'Previous payable amount' : 'Previous receivable amount']: '50', 'Reason / old reference': `Old book ${key}` });
        props = await save(page, dialog, 'Save previous udhaar');
        const debt = props.opening_balances.find((r: any) => r.party_id === person.id && r.reason === `Old book ${key}`);
        expect(debt.remaining).toBe(5000);
        const balances = (data: any) => vendor ? data.vendor_accounts.find((r: any) => r.id === person.id).account : data.customer_accounts.find((r: any) => r.id === person.id).fabric;
        expect(balances(props)).toMatchObject({ [vendor ? 'payable' : 'outstanding']: 5000, advance: 2000 });
        await expect(dialog.getByLabel(vendor ? 'Previous payable amount' : 'Previous receivable amount', { exact: true })).toHaveValue('');
        await close(dialog, 'Add previous udhaar');
        dialog = await panel(page, 'Adjust advance against udhaar');
        await dialog.getByRole('combobox', { name: vendor ? 'Vendor' : 'Customer', exact: true }).selectOption(String(person.id));
        await dialog.getByRole('combobox', { name: 'Previous udhaar entry' }).selectOption(String(debt.id));
        await dialog.getByRole('combobox', { name: 'Existing advance' }).selectOption({ index: 1 });
        await dialog.getByLabel('Amount to adjust').fill('15');
        props = await save(page, dialog, 'Adjust existing advance');
        expect(balances(props)).toMatchObject({ [vendor ? 'payable' : 'outstanding']: 3500, advance: 500 });
        expect(props.opening_balances.find((r: any) => r.id === debt.id).remaining).toBe(3500);
        await close(dialog, 'Adjust advance against udhaar');
        const row = card(page, vendor ? 'Vendor accounts' : 'Customer fabric accounts').locator('tbody tr').filter({ hasText: name });
        title = vendor ? 'Pay vendor' : 'Collect udhaar';
        await row.getByRole('button', { name: title, exact: true }).click();
        dialog = page.getByRole('dialog', { name: title, exact: true });
        await expect(dialog.getByRole('combobox', { name: vendor ? 'Vendor' : 'Customer', exact: true })).toHaveValue(String(person.id));
        await dialog.getByLabel('Amount', { exact: true }).fill('35');
        props = await save(page, dialog, vendor ? 'Record vendor payment' : 'Record collection');
        expect(balances(props)).toMatchObject({ [vendor ? 'payable' : 'outstanding']: 0, advance: 500 });
        expect(props.opening_balances.find((r: any) => r.id === debt.id).remaining).toBe(0);
        await expect(dialog.getByLabel('Amount', { exact: true })).toHaveValue('');
        await close(dialog, title);
        await expect(card(page, 'Previous udhaar').getByText(`Old book ${key}`, { exact: true })).toBeVisible();
    });
}

test('all report types filters and CSV/XLSX exports load', async ({ page }) => {
    for (const report of ['sales', 'purchases', 'stock', 'movements', 'customers', 'vendors', 'advances', 'returns', 'expenses', 'installments', 'cash', 'profit']) {
        await page.goto(`/fabric/reports?report=${report}`);
        await expect(page.getByRole('heading', { level: 1, name: 'Reports' })).toBeVisible();
        await page.getByLabel('From date').fill('2026-01-01');
        await page.getByLabel('To date').fill('2030-12-31');
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(page).toHaveURL(/from=2026-01-01/);
        for (const name of ['CSV', 'XLSX']) {
            const href = await page.getByRole('link', { name, exact: true }).getAttribute('href');
            const response = await page.request.get(href!);
            expect(response.ok(), `${report} ${name}`).toBeTruthy();
            expect((await response.body()).length).toBeGreaterThan(0);
        }
    }
});

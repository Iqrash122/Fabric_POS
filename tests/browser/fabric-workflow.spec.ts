import { expect, test } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';

const credentialPath = process.env.FABRIC_TEST_CREDENTIALS ?? '/tmp/fabric-browser-credentials.json';

function credentials() {
    if (!existsSync(credentialPath)) throw new Error('Run scripts/qa/seed-browser.php against an isolated browser database first.');
    return JSON.parse(readFileSync(credentialPath, 'utf8')) as { email: string; password: string };
}

async function login(page: import('@playwright/test').Page) {
    const c = credentials();
    await page.goto('/login');
    await page.getByLabel('Email address').fill(c.email);
    await page.getByLabel('Password', { exact: true }).fill(c.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/fabric/);
}

async function openPanel(page: import('@playwright/test').Page, name: string) {
    await page.getByRole('button', { name, exact: true }).click();
    const dialog = page.getByRole('dialog', { name });
    await expect(dialog).toBeVisible();
    return dialog;
}

async function postAndWait(page: import('@playwright/test').Page, button: import('@playwright/test').Locator) {
    const response = page.waitForResponse((r) => r.headers()['x-inertia'] === 'true' && r.request().redirectedFrom()?.method() === 'POST');
    await button.click();
    const result = await (await response).json();
    expect(result.props.errors).toEqual({});
    await page.waitForLoadState('networkidle');
    return result.props;
}

test('fabric purchase to roll to credit sale to return to final collection reconciles', async ({ page }) => {
    test.setTimeout(90_000);
    const unique = Date.now().toString().slice(-7);
    const vendorName = `Browser Textile ${unique}`;
    const productName = `Browser Cotton ${unique}`;
    const customerName = `Browser Customer ${unique}`;

    await login(page);

    await page.goto('/fabric/vendors');
    let dialog = await openPanel(page, 'New vendor');
    await dialog.getByLabel('Shop name').fill(vendorName);
    await dialog.getByLabel('Owner name').fill('Browser Vendor');
    await dialog.getByRole('button', { name: 'Save vendor' }).click();
    await expect(page.getByText(vendorName, { exact: true })).toBeVisible();

    await page.goto('/fabric/inventory');
    dialog = await openPanel(page, 'New product');
    await dialog.getByLabel('Fabric / product name').fill(productName);
    await dialog.getByLabel('SKU').fill(`B${unique}`);
    await dialog.getByLabel('Product barcode').fill(`BP${unique}`);
    await dialog.getByLabel('Default selling price').fill('20.00');
    await dialog.getByLabel('Low stock threshold').fill('2.000');
    await dialog.getByRole('button', { name: 'Save product' }).click();
    await expect(page.getByText(productName, { exact: true })).toBeVisible();

    await page.goto('/fabric/purchases');
    dialog = await openPanel(page, 'New purchase');
    await dialog.getByRole('combobox', { name: 'Vendor' }).selectOption({ label: vendorName });
    const purchaseLine = dialog.locator('.purchase-line').first();
    await purchaseLine.locator('select').nth(0).selectOption({ label: `${productName} · meter` });
    await purchaseLine.locator('select').nth(1).selectOption({ label: 'Unspecified' });
    await purchaseLine.getByPlaceholder('Qty').fill('10.000');
    await purchaseLine.getByPlaceholder('Purchase rate').fill('10.00');
    await dialog.getByRole('button', { name: 'Create purchase bill' }).click();
    const purchaseRow = page.locator('tbody tr').filter({ hasText: vendorName }).first();
    await expect(purchaseRow).toBeVisible();
    await dialog.getByRole('button', { name: 'Close New purchase', exact: true }).click();
    await purchaseRow.getByRole('button', { name: 'Post bill' }).click();
    await expect(purchaseRow.getByText('posted', { exact: true })).toBeVisible();

    dialog = await openPanel(page, 'Receive rolls');
    await dialog.getByRole('combobox', { name: 'Purchase bill' }).selectOption({ index: 1 });
    await dialog.getByRole('combobox', { name: 'Purchase line' }).selectOption({ index: 1 });
    await dialog.getByLabel('Received quantity').fill('10.000');
    await dialog.getByLabel('Roll number (optional)').fill(`BR-${unique}`);
    await dialog.getByLabel('Selling price').fill('20.00');
    await dialog.getByRole('button', { name: 'Post goods receipt' }).click();
    // A successful receipt clears the selected line, so submit becomes disabled again.
    await expect(dialog.getByLabel('Received quantity')).toHaveValue('');
    await expect(dialog.locator('.error-summary')).toHaveCount(0);
    await page.goto('/fabric/inventory');
    await expect(page.locator('strong').filter({ hasText: `BR-${unique}` })).toBeVisible();

    await page.goto('/fabric/vendors');
    dialog = await openPanel(page, 'Vendor payment');
    await dialog.getByRole('combobox', { name: 'Vendor' }).selectOption({ label: vendorName });
    await dialog.getByRole('combobox', { name: 'Payment purpose' }).selectOption('bill');
    await dialog.getByRole('combobox', { name: 'Purchase bill', exact: true }).selectOption({ index: 1 });
    await dialog.getByLabel('Amount').fill('50.00');
    const vendorPayment = await postAndWait(page, dialog.getByRole('button', { name: 'Record vendor payment' }));
    expect(vendorPayment.vendor_payments[0].amount).toBe(5000);

    await page.goto('/fabric/pos');
    dialog = await openPanel(page, 'Quick customer');
    await dialog.getByLabel('Customer name').fill(customerName);
    await dialog.getByLabel('Phone').fill(`0300${unique.padStart(7, '0').slice(0, 7)}`);
    await dialog.getByRole('button', { name: 'Create customer' }).click();
    await expect(dialog.getByRole('button', { name: 'Create customer' })).toBeEnabled();
    await dialog.getByRole('button', { name: 'Close Quick customer', exact: true }).click();
    const customerSelect = page.getByRole('combobox', { name: 'Customer', exact: true });
    const customerValue = await customerSelect.locator('option').filter({ hasText: customerName }).getAttribute('value');
    expect(customerValue).toBeTruthy();
    await customerSelect.selectOption(customerValue!);
    await page.locator('.fabric-stock-picker button').filter({ hasText: productName }).first().click();
    await page.getByLabel('Quantity').fill('5.000');
    await page.getByLabel('Paid on account 1').fill('50.00');
    await page.getByLabel('Authorize credit balance (owner/manager only)').check();
    await page.getByLabel('Print receipt after sale').uncheck();
    await postAndWait(page, page.getByRole('button', { name: /^Post sale/ }));
    const invoiceLink = page.locator('a[href*="/fabric/document/invoice/"]').first();
    await expect(invoiceLink).toBeVisible();
    const invoiceHref = await invoiceLink.getAttribute('href');
    const saleId = invoiceHref?.match(/invoice\/(\d+)/)?.[1];
    expect(saleId).toBeTruthy();

    await page.goto('/fabric/customers');
    dialog = await openPanel(page, 'Record later collection');
    await dialog.getByRole('combobox', { name: 'Customer' }).selectOption({ label: customerName });
    await dialog.getByRole('combobox', { name: 'Collection purpose' }).selectOption('invoice');
    await dialog.getByRole('combobox', { name: 'Outstanding invoice', exact: true }).selectOption({ index: 1 });
    await dialog.getByLabel('Amount').fill('20.00');
    await postAndWait(page, dialog.getByRole('button', { name: 'Record collection' }));

    await page.goto('/fabric/returns');
    const customerReturnCard = page.locator('.fabric-card').filter({ hasText: 'Customer return' }).first();
    await customerReturnCard.getByLabel('Sale invoice ID').fill(saleId!);
    await customerReturnCard.getByRole('combobox', { name: 'Sale line' }).selectOption({ index: 1 });
    await customerReturnCard.getByLabel('Return quantity').fill('1.000');
    await customerReturnCard.getByRole('combobox', { name: 'Financial resolution' }).selectOption('reduce_balance');
    await customerReturnCard.getByLabel('Reason').fill('Browser acceptance return');
    await postAndWait(page, customerReturnCard.getByRole('button', { name: 'Post customer return' }));
    await expect(page.getByText('reduce_balance', { exact: true }).first()).toBeVisible();

    await page.goto('/fabric/customers');
    dialog = await openPanel(page, 'Record later collection');
    await dialog.getByRole('combobox', { name: 'Customer' }).selectOption({ label: customerName });
    await dialog.getByRole('combobox', { name: 'Collection purpose' }).selectOption('invoice');
    await dialog.getByRole('combobox', { name: 'Outstanding invoice', exact: true }).selectOption({ index: 1 });
    await dialog.getByLabel('Amount').fill('10.00');
    await postAndWait(page, dialog.getByRole('button', { name: 'Record collection' }));
    const customerRow = page.locator('tbody tr').filter({ hasText: customerName }).first();
    await expect(customerRow).toContainText('PKR 0.00');

    await page.goto('/fabric');
    await expect(page.getByText('Customer outstanding')).toBeVisible();
    await expect(page.getByText('Vendor outstanding')).toBeVisible();
    await page.goto('/fabric/reports?report=profit');
    await expect(page.getByRole('heading', { name: 'Reports' })).toBeVisible();
    await expect(page.getByText('Profit report')).toBeVisible();
});

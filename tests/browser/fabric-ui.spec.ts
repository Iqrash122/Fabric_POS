import { expect, test } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';

const credentialPath =
    process.env.FABRIC_TEST_CREDENTIALS ??
    '/tmp/fabric-browser-credentials.json';

function credentials() {
    if (!existsSync(credentialPath)) {
        throw new Error(
            'Run scripts/qa/seed-browser.php against an isolated browser database first.',
        );
    }
    return JSON.parse(readFileSync(credentialPath, 'utf8')) as {
        email: string;
        password: string;
    };
}

async function login(page: import('@playwright/test').Page) {
    const c = credentials();
    await page.goto('/login');
    await page.getByLabel('Email address').fill(c.email);
    await page.getByLabel('Password', { exact: true }).fill(c.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/fabric/);
}

const sections = [
    ['', 'Dashboard'],
    ['pos', 'Sales / POS'],
    ['customers', 'Customers'],
    ['vendors', 'Vendors'],
    ['purchases', 'Purchases'],
    ['inventory', 'Inventory'],
    ['returns', 'Returns / Refunds'],
    ['cash-bank', 'Cash / Bank'],
    ['expenses', 'Expenses'],
    ['promotions', 'Promotions'],
    ['marketing', 'Marketing'],
    ['whatsapp-bulk', 'WhatsApp Bulk'],
    ['reports', 'Reports'],
    ['settings', 'Settings / Audit'],
] as const;

test('fabric workspace desktop UI is complete and free of page overflow', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    await login(page);
    await page.setViewportSize({ width: 1440, height: 1000 });

    for (const [slug, title] of sections) {
        await page.goto(slug ? `/fabric/${slug}` : '/fabric');
        await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
        await expect(page.locator('.fabric-side')).toBeVisible();
        await expect(page.locator('.fabric-topbar')).toBeVisible();
        await expect(page.locator('.fabric-heading-copy > span')).not.toBeEmpty();
        await expect(page.locator('.fabric-side a[aria-current="page"]')).toHaveCount(1);
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            `${title} should not overflow the desktop viewport`,
        ).toBeTruthy();
    }

    expect(pageErrors).toEqual([]);
});

test('fabric workspace mobile navigation, forms and table cards remain usable', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });

    for (const [slug, title] of sections) {
        await page.goto(slug ? `/fabric/${slug}` : '/fabric');
        await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
        await expect(page.locator('.fabric-mobile-nav')).toBeVisible();
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
            `${title} should not overflow the mobile viewport`,
        ).toBeTruthy();

        const firstTableCell = page.locator('.fabric-table-wrap tbody td').first();
        if (await firstTableCell.count()) {
            const before = await firstTableCell.evaluate((element) =>
                getComputedStyle(element, '::before').content,
            );
            expect(before).not.toBe('none');
        }
    }

    await page.goto('/fabric');
    await page.getByRole('button', { name: 'Open navigation' }).click();
    await expect(page.locator('.fabric-side')).toHaveClass(/open/);
    await page.keyboard.press('Escape');
    await expect(page.locator('.fabric-side')).not.toHaveClass(/open/);
});

test('fabric POS keeps primary checkout actions accessible on desktop and mobile', async ({ page }) => {
    await login(page);
    await page.goto('/fabric/pos');
    await expect(page.getByRole('textbox', { name: 'Barcode or search' })).toBeVisible();
    await expect(page.getByText('Current invoice', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Post sale/ })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('textbox', { name: 'Barcode or search' })).toBeVisible();
    await expect(page.locator('.fabric-mobile-nav').getByRole('link', { name: /POS/ })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});

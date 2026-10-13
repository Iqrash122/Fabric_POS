import { defineConfig } from '@playwright/test';
export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: false,
    workers: 1,
    timeout: 60000,
    reporter: [
        ['list'],
        ['json', { outputFile: 'test-results/browser-results.json' }],
    ],
    use: {
        baseURL: process.env.FABRIC_TEST_URL ?? 'http://127.0.0.1:8000',
        headless: true,
        channel: process.env.FABRIC_TEST_BROWSER_CHANNEL || undefined,
        viewport: { width: 1440, height: 1000 },
        screenshot: 'only-on-failure',
        trace: 'off',
    },
});

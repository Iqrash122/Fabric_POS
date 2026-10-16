const { browserExecutable } = require('./lifecycle');
const puppeteer = require('puppeteer');
(async () => {
    const executablePath = browserExecutable();
    let browser;
    try {
        browser = await puppeteer.launch({ headless: true, executablePath, timeout: 30000 });
        const page = await browser.newPage();
        await page.setContent('<title>Fabric browser check</title><p>Local test only</p>');
        if (await page.title() !== 'Fabric browser check') throw new Error('Browser rendering failed.');
        console.log(JSON.stringify({ ok: true, node: process.version, browser: await browser.version(), source: executablePath ? 'installed browser' : 'Puppeteer browser', note: 'No WhatsApp connection, authentication access or messages.' }));
    } catch (error) {
        console.error(`Browser setup failed: ${error.message}\nInstall Chrome or set WHATSAPP_CHROME_PATH to its executable. Existing authentication is not modified.`);
        process.exitCode = 1;
    } finally { if (browser) await browser.close(); }
})();

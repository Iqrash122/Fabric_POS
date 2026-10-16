const fs = require('node:fs');

// Keep revoked sessions recoverable; never unlink a live Chrome profile on Windows.
function preservingAuth(LocalAuth, onError) {
    return class PreservingAuth extends LocalAuth {
        async logout() {
            try {
                if (this.client.pupBrowser?.isConnected()) await this.client.pupBrowser.close();
                if (!this.userDataDir || !fs.existsSync(this.userDataDir)) return;
                const backup = `${this.userDataDir}.revoked-${Date.now()}`;
                for (let attempt = 0; attempt < 8; attempt++) {
                    try { await fs.promises.rename(this.userDataDir, backup); return; }
                    catch (error) {
                        if (!['EBUSY', 'EPERM', 'EACCES'].includes(error.code) || attempt === 7) throw error;
                        await new Promise(resolve => setTimeout(resolve, 250 * (attempt + 1)));
                    }
                }
            } catch (error) {
                this.cleanupFailed = true;
                onError(`Session is still locked. Close the WhatsApp server and its Chrome process, then restart. Existing authentication was preserved. ${error.code || ''}`);
            }
        }
        async beforeBrowserInitialized() {
            if (this.cleanupFailed) return;
            try { return await super.beforeBrowserInitialized(); }
            catch (error) { this.cleanupFailed = true; onError(`Cannot open the saved browser profile: ${error.message}`); }
        }
    };
}

class ClientLifecycle {
    constructor(createClient, { retryMs = 8000, onState = () => {} } = {}) {
        this.createClient = createClient;
        this.retryMs = retryMs;
        this.onState = onState;
        this.client = null;
        this.pending = null;
        this.timer = null;
        this.stopped = false;
        this.generation = 0;
        this.status = 'disconnected';
        this.error = null;
    }
    state(status, error = null) { this.status = status; this.error = error; this.onState(status, error); }
    retry(error) {
        this.state('disconnected', error?.message || String(error || 'Disconnected'));
        if (this.stopped || this.timer) return;
        this.timer = setTimeout(() => { this.timer = null; this.start().catch(error => this.retry(error)); }, this.retryMs);
    }
    start() {
        if (this.pending) return this.pending;
        if (this.stopped) return Promise.resolve();
        clearTimeout(this.timer); this.timer = null;
        this.pending = this.initialize().finally(() => { this.pending = null; });
        return this.pending;
    }
    async initialize() {
        const generation = ++this.generation;
        const previous = this.client;
        this.client = null;
        if (previous) {
            try { await previous.destroy(); }
            catch (error) {
                // Do not create a competing browser if the previous one remains alive.
                if (previous.pupBrowser?.isConnected()) { this.client = previous; this.retry(error); return; }
            }
        }
        if (this.stopped) return;
        this.state('reconnecting');
        try {
            const client = this.createClient(error => this.retry(error));
            this.client = client;
            const current = () => this.client === client && this.generation === generation && !this.stopped;
            client.on('qr', () => { if (current()) this.state('qr-pending'); });
            client.on('authenticated', () => { if (current()) this.state('authenticated'); });
            client.on('ready', () => { if (current()) { clearTimeout(this.timer); this.timer = null; this.state('connected'); } });
            client.on('auth_failure', message => { if (current()) this.retry(new Error(String(message))); });
            client.on('disconnected', reason => { if (current()) this.retry(new Error(String(reason))); });
            let startupTimer;
            try {
                await Promise.race([client.initialize(), new Promise((_, reject) => { startupTimer = setTimeout(() => reject(new Error('WhatsApp startup timed out. Reconnecting; existing authentication is preserved.')), 120000); })]);
            } finally { clearTimeout(startupTimer); }
        } catch (error) { if (!this.stopped) this.retry(error); }
    }
    async stop() {
        this.stopped = true;
        clearTimeout(this.timer); this.timer = null;
        ++this.generation;
        if (this.client) { try { await this.client.destroy(); } catch {} }
        this.client = null;
        this.state('disconnected');
    }
}

function browserExecutable() {
    const candidates = [process.env.WHATSAPP_CHROME_PATH, process.platform === 'win32' && 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', process.platform === 'win32' && 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'];
    return candidates.find(candidate => candidate && fs.existsSync(candidate));
}
module.exports = { ClientLifecycle, preservingAuth, browserExecutable };

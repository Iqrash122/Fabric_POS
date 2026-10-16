const { test } = require('node:test');
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { ClientLifecycle, preservingAuth, browserExecutable } = require('../lifecycle');

test('concurrent starts share one initialization; authentication is not ready', async () => {
    let release;
    let created = 0;
    const client = new EventEmitter();
    client.initialize = () => new Promise(resolve => { release = resolve; });
    client.destroy = async () => {};
    const lifecycle = new ClientLifecycle(() => { created++; return client; });
    const first = lifecycle.start();
    const second = lifecycle.start();
    client.emit('authenticated');
    assert.equal(lifecycle.status, 'authenticated');
    assert.equal(created, 1);
    assert.equal(first, second);
    client.emit('ready');
    assert.equal(lifecycle.status, 'connected');
    release(); await first; await lifecycle.stop();
});

test('navigation failure retries once and destroys the old client before replacement', async () => {
    let created = 0;
    let destroyed = 0;
    const lifecycle = new ClientLifecycle(() => {
        const client = new EventEmitter();
        const number = ++created;
        client.initialize = async () => { if (number === 1) throw new Error('Execution context was destroyed'); client.emit('ready'); };
        client.destroy = async () => { destroyed++; };
        return client;
    }, { retryMs: 10 });
    await lifecycle.start();
    await new Promise(resolve => setTimeout(resolve, 50));
    assert.equal(created, 2);
    assert.equal(destroyed, 1);
    assert.equal(lifecycle.status, 'connected');
    await lifecycle.stop();
    await new Promise(resolve => setTimeout(resolve, 30));
    assert.equal(created, 2);
});

test('revoked auth closes browser then preserves session as backup', async () => {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'fabric-wa-test-'));
    const session = path.join(root, 'session');
    await fs.mkdir(session); await fs.writeFile(path.join(session, 'fixture'), 'preserved');
    let closed = false;
    const Auth = preservingAuth(class {}, message => assert.fail(message));
    const auth = new Auth();
    auth.userDataDir = session;
    auth.client = { pupBrowser: { isConnected: () => true, close: async () => { closed = true; } } };
    await auth.logout();
    assert.equal(closed, true);
    const files = await fs.readdir(root);
    assert.match(files[0], /^session.revoked-/);
    assert.equal(await fs.readFile(path.join(root, files[0], 'fixture'), 'utf8'), 'preserved');
    // Only the synthetic test directory is removed.
    await fs.rm(root, { recursive: true });
});

test('browser selection accepts only an existing executable path', () => {
    const browser = browserExecutable();
    if (browser) assert.equal(require('node:fs').existsSync(browser), true);
});

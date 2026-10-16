/**
 * Fabric POS — WhatsApp Bulk Messaging Server
 * Uses whatsapp-web.js to send bulk messages via WhatsApp Web
 * Run: node server.js
 * Port: 3001
 */

const express = require('express');
const cors = require('cors');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
const qrcode = require('qrcode');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

const app = express();
const crypto = require('node:crypto');
const { authorizationVerifier } = require('./authorization');
const keyPath = path.join(__dirname, '../storage/app/private/whatsapp-server.key');
fs.mkdirSync(path.dirname(keyPath), { recursive: true });
try { fs.writeFileSync(keyPath, crypto.randomBytes(32).toString('hex'), { flag: 'wx', mode: 0o600 }); } catch (error) { if (error.code !== 'EEXIST') throw error; }
const verifySend = authorizationVerifier(fs.readFileSync(keyPath, 'utf8').trim());
const PORT = Number(process.env.WHATSAPP_PORT || 3001);
const { ClientLifecycle, preservingAuth, browserExecutable } = require('./lifecycle');

const allowedOrigins = (process.env.WHATSAPP_ALLOWED_ORIGINS || 'http://127.0.0.1:8000,http://localhost:8000,http://127.0.0.1:8001,http://localhost:8001').split(',');
app.use((req, res, next) => {
    if (req.headers.origin && !allowedOrigins.includes(req.headers.origin)) return res.status(403).json({ error: 'Origin not allowed.' });
    next();
});
app.use(cors({ origin: allowedOrigins }));
app.use(express.json({ limit: '50mb' }));

// ─── Media upload storage ────────────────────────────────────────────────────
const uploadDir = path.join(__dirname, 'uploads');
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });

const storage = multer.diskStorage({
    destination: uploadDir,
    filename: (_req, file, cb) => {
        const ext = path.extname(file.originalname);
        cb(null, `media_${Date.now()}${ext}`);
    },
});
const upload = multer({ storage, limits: { fileSize: 64 * 1024 * 1024 } });

// ─── State ───────────────────────────────────────────────────────────────────
let qrCodeDataUrl = null;
let clientStatus = 'disconnected'; // disconnected | qr-pending | connected | reconnecting
let whatsappClient = null;

// Send progress tracking
let sendJob = {
    active: false,
    total: 0,
    sent: 0,
    failed: 0,
    failedNumbers: [],
    log: [],
    startedAt: null,
    finishedAt: null,
};

// ─── Initialize WhatsApp Client ───────────────────────────────────────────────
const lifecycle = new ClientLifecycle((recover) => {
    const Auth = preservingAuth(LocalAuth, message => lifecycle.state('disconnected', message));
    const client = new Client({
        authStrategy: new Auth({ dataPath: path.join(__dirname, '.wwebjs_auth') }),
        puppeteer: {
            headless: true,
            executablePath: browserExecutable(),
            args: ['--disable-dev-shm-usage'],
        },
    });
    // Upstream frame navigation invokes inject asynchronously; catch its rejection
    // here so a navigation race does not become an unhandled process rejection.
    const inject = client.inject.bind(client);
    client.inject = async (...args) => {
        if (client.authStrategy.cleanupFailed || (client.pupBrowser && !client.pupBrowser.isConnected())) return;
        try { return await inject(...args); }
        catch (error) { if (lifecycle.client === client) recover(error); }
    };
    client.on('qr', async qr => {
        try {
            const data = await qrcode.toDataURL(qr);
            if (lifecycle.client === client && lifecycle.status === 'qr-pending') qrCodeDataUrl = data;
        } catch (error) { recover(error); }
    });
    whatsappClient = client;
    return client;
}, { onState: status => {
    clientStatus = status;
    if (status !== 'qr-pending') qrCodeDataUrl = null;
} });
const initClient = () => lifecycle.start();

// ─── Helper: normalize phone to WhatsApp ID ───────────────────────────────────
function toWaId(phone) {
    // Remove spaces, dashes, parentheses
    let raw = phone.replace(/[\s\-\(\)]/g, '');
    // If starts with 0, replace with 92 (Pakistan)
    if (raw.startsWith('0')) raw = '92' + raw.slice(1);
    // If starts with +, remove it
    if (raw.startsWith('+')) raw = raw.slice(1);
    return raw + '@c.us';
}

// ─── Helper: sleep ────────────────────────────────────────────────────────────
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ─── API Routes ───────────────────────────────────────────────────────────────

// GET /status — WhatsApp connection status
app.get('/status', (_req, res) => {
    res.json({
        status: clientStatus,
        connected: clientStatus === 'connected',
        hasQr: !!qrCodeDataUrl,
        error: lifecycle.error,
    });
});

// GET /qr — Get QR code as base64 data URL
app.get('/qr', (_req, res) => {
    if (!qrCodeDataUrl) {
        return res.status(404).json({ error: 'No QR code available. Already connected or not yet initialized.' });
    }
    res.json({ qr: qrCodeDataUrl });
});

// POST /reconnect — Force re-initialize
app.post('/reconnect', (_req, res) => {
    if (sendJob.active) return res.status(409).json({ error: 'Wait for the current send job to finish.' });
    initClient().catch(error => lifecycle.retry(error));
    res.json({ message: 'Reconnecting...' });
});

// POST /upload-media — Upload image or video
app.post('/upload-media', upload.single('file'), (req, res) => {
    if (!req.file) return res.status(400).json({ error: 'No file uploaded.' });
    res.json({
        filename: req.file.filename,
        path: req.file.path,
        mimetype: req.file.mimetype,
        size: req.file.size,
    });
});

// POST /send-bulk — Start bulk send job
// Body: short-lived signed payload returned by the authenticated Laravel authorization endpoint.
app.post('/send-bulk', async (req, res) => {
    if (clientStatus !== 'connected') {
        return res.status(400).json({ error: 'WhatsApp is not connected. Please scan QR code first.' });
    }
    if (sendJob.active) {
        return res.status(400).json({ error: 'A send job is already running. Please wait.' });
    }

    let authorized;
    try { authorized = verifySend(req.body); }
    catch (error) { return res.status(403).json({ error: error.message }); }
    const { phones, message, mediaFilename } = authorized;
    if (typeof message !== 'string' || (mediaFilename != null && typeof mediaFilename !== 'string')) return res.status(422).json({ error: 'Invalid message or attachment.' });
    if (Array.isArray(phones) && (phones.length > 500 || phones.some(phone => typeof phone !== 'string' || !/^[+0-9 ()-]{7,25}$/.test(phone)))) return res.status(422).json({ error: 'Enter valid phone numbers; maximum 500 recipients.' });

    if (!phones || !Array.isArray(phones) || phones.length === 0) {
        return res.status(400).json({ error: 'No phone numbers provided.' });
    }
    if (!message || message.trim() === '') {
        return res.status(400).json({ error: 'Message cannot be empty.' });
    }

    // Validate the requested attachment before accepting any messages.
    let media = null;
    if (mediaFilename) {
        try {
            const mediaPath = path.join(uploadDir, path.basename(mediaFilename));
            if (!fs.existsSync(mediaPath)) throw new Error('Attachment is missing. Upload it again.');
            media = MessageMedia.fromFilePath(mediaPath);
        } catch (error) { return res.status(422).json({ error: error.message }); }
    }

    // Initialize job
    sendJob = {
        active: true,
        total: phones.length,
        sent: 0,
        failed: 0,
        failedNumbers: [],
        log: [],
        startedAt: new Date().toISOString(),
        finishedAt: null,
    };

    res.json({ message: `Bulk send started for ${phones.length} recipients.`, total: phones.length });

    // Send in background
    (async () => {
        for (const phone of phones) {
            try {
                const waId = toWaId(phone);
                if (media) {
                    await whatsappClient.sendMessage(waId, media, { caption: message });
                } else {
                    await whatsappClient.sendMessage(waId, message);
                }
                sendJob.sent++;
                sendJob.log.push({ phone, status: 'sent', time: new Date().toISOString() });
                console.log(`[WA] ✅ Sent to ${phone} (${sendJob.sent}/${sendJob.total})`);
            } catch (err) {
                sendJob.failed++;
                sendJob.failedNumbers.push(phone);
                sendJob.log.push({ phone, status: 'failed', error: err.message, time: new Date().toISOString() });
                console.error(`[WA] ❌ Failed to send to ${phone}:`, err.message);
            }
            // Pace the serial queue; this is not a delivery or account-limit guarantee.
            const delay = 1500 + Math.floor(Math.random() * 1500);
            await sleep(delay);
        }
        sendJob.active = false;
        sendJob.finishedAt = new Date().toISOString();
        console.log(`[WA] 🎉 Bulk send complete. Sent: ${sendJob.sent}, Failed: ${sendJob.failed}`);
    })();
});

// GET /progress — Real-time send progress
app.get('/progress', (_req, res) => {
    res.json({
        active: sendJob.active,
        total: sendJob.total,
        sent: sendJob.sent,
        failed: sendJob.failed,
        failedNumbers: sendJob.failedNumbers,
        log: sendJob.log.slice(-20), // last 20 entries
        startedAt: sendJob.startedAt,
        finishedAt: sendJob.finishedAt,
        percent: sendJob.total > 0 ? Math.round(((sendJob.sent + sendJob.failed) / sendJob.total) * 100) : 0,
    });
});

// DELETE /uploads/:filename — Clean up uploaded media
app.delete('/uploads/:filename', (req, res) => {
    const filePath = path.join(uploadDir, path.basename(req.params.filename));
    if (fs.existsSync(filePath)) {
        fs.unlinkSync(filePath);
        res.json({ message: 'File deleted.' });
    } else {
        res.status(404).json({ error: 'File not found.' });
    }
});

// ─── Start Server ─────────────────────────────────────────────────────────────
const server = app.listen(PORT, '127.0.0.1', () => {
    console.log(`\n🟢 Fabric WhatsApp Server running on http://localhost:${PORT}`);
    console.log('   Initializing WhatsApp client...\n');
    initClient().catch(console.error);
});

// Graceful shutdown
const shutdown = async () => {
    console.log('\n[WA] Shutting down WhatsApp server...');
    await lifecycle.stop();
    server.close(() => {
        console.log('[WA] Server closed cleanly.');
        process.exit(0);
    });
};

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);


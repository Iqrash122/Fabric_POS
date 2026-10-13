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
const PORT = 3001;

app.use(cors({ origin: '*' }));
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
let isInitializing = false;

async function initClient() {
    if (isInitializing) {
        console.log('[WA] Initialization already in progress, skipping duplicate call.');
        return;
    }
    isInitializing = true;

    if (whatsappClient) {
        try {
            console.log('[WA] Destroying existing client instance...');
            await whatsappClient.destroy();
        } catch (e) {
            console.warn('[WA] Error destroying client:', e.message);
        }
        whatsappClient = null;
    }

    console.log('[WA] Initializing WhatsApp client...');
    clientStatus = 'reconnecting';
    qrCodeDataUrl = null;

    const client = new Client({
        authStrategy: new LocalAuth({ dataPath: path.join(__dirname, '.wwebjs_auth') }),
        puppeteer: {
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
            ],
        },
    });

    client.on('qr', async (qr) => {
        console.log('\n[WA] ═══════════════ QR CODE RECEIVED ═══════════════');
        console.log('[WA] Scan this QR code with WhatsApp on your phone:');
        clientStatus = 'qr-pending';
        try {
            // Print small QR in terminal
            qrcode.toString(qr, { type: 'terminal', small: true }, (_err, str) => {
                if (str) console.log(str);
            });
            // Generate Data URL for browser display
            qrCodeDataUrl = await qrcode.toDataURL(qr);
        } catch (err) {
            console.error('[WA] QR generation error:', err);
        }
        console.log('[WA] ══════════════════════════════════════════════════\n');
    });

    client.on('ready', () => {
        console.log('[WA] ✅ WhatsApp connected successfully!');
        clientStatus = 'connected';
        qrCodeDataUrl = null;
        isInitializing = false;
    });

    client.on('authenticated', () => {
        console.log('[WA] ✅ Authenticated successfully');
        isInitializing = false;
    });

    client.on('auth_failure', (msg) => {
        console.error('[WA] ❌ Auth failed:', msg);
        clientStatus = 'disconnected';
        isInitializing = false;
    });

    client.on('disconnected', (reason) => {
        console.warn('[WA] ⚠️ Disconnected:', reason);
        clientStatus = 'disconnected';
        qrCodeDataUrl = null;
        isInitializing = false;
        // Auto-reconnect after 8 seconds
        setTimeout(() => {
            initClient().catch(console.error);
        }, 8000);
    });

    client.initialize().then(() => {
        isInitializing = false;
    }).catch((err) => {
        console.error('[WA] ❌ Initialize error:', err.message);
        clientStatus = 'disconnected';
        isInitializing = false;
        // Clean up broken lock/auth if needed
        setTimeout(() => {
            initClient().catch(console.error);
        }, 12000);
    });

    whatsappClient = client;
}

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
    initClient();
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
// Body: { phones: ["+923001234567", ...], message: "Hello!", mediaFilename: "media_xxx.jpg" (optional) }
app.post('/send-bulk', async (req, res) => {
    if (clientStatus !== 'connected') {
        return res.status(400).json({ error: 'WhatsApp is not connected. Please scan QR code first.' });
    }
    if (sendJob.active) {
        return res.status(400).json({ error: 'A send job is already running. Please wait.' });
    }

    const { phones, message, mediaFilename } = req.body;

    if (!phones || !Array.isArray(phones) || phones.length === 0) {
        return res.status(400).json({ error: 'No phone numbers provided.' });
    }
    if (!message || message.trim() === '') {
        return res.status(400).json({ error: 'Message cannot be empty.' });
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

    // Load media if provided
    let media = null;
    if (mediaFilename) {
        const mediaPath = path.join(uploadDir, mediaFilename);
        if (fs.existsSync(mediaPath)) {
            try {
                media = MessageMedia.fromFilePath(mediaPath);
            } catch (err) {
                console.error('[WA] Media load error:', err.message);
            }
        }
    }

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
            // Delay between messages to avoid spam detection (1.5 - 3 seconds)
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
    const filePath = path.join(uploadDir, req.params.filename);
    if (fs.existsSync(filePath)) {
        fs.unlinkSync(filePath);
        res.json({ message: 'File deleted.' });
    } else {
        res.status(404).json({ error: 'File not found.' });
    }
});

// ─── Start Server ─────────────────────────────────────────────────────────────
const server = app.listen(PORT, () => {
    console.log(`\n🟢 Fabric WhatsApp Server running on http://localhost:${PORT}`);
    console.log('   Initializing WhatsApp client...\n');
    initClient().catch(console.error);
});

// Graceful shutdown
const shutdown = async () => {
    console.log('\n[WA] Shutting down WhatsApp server...');
    if (whatsappClient) {
        try { await whatsappClient.destroy(); } catch (_) {}
    }
    server.close(() => {
        console.log('[WA] Server closed cleanly.');
        process.exit(0);
    });
};

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);


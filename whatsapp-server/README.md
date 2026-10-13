# Fabric POS — WhatsApp Bulk Server

## Setup & Start

```bash
cd whatsapp-server
npm install
node server.js
```

Server runs on: `http://localhost:3001`

## First Time Use

1. Start the server: `node server.js`
2. Open Fabric POS → **WhatsApp Bulk** menu
3. Scan the QR code with your phone (WhatsApp → Linked Devices → Link a Device)
4. Done! Now you can send bulk messages.

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/status` | Connection status |
| GET | `/qr` | QR code (base64) |
| POST | `/reconnect` | Force reconnect |
| POST | `/upload-media` | Upload image/video |
| POST | `/send-bulk` | Start bulk send |
| GET | `/progress` | Sending progress |

## Notes

- Session is saved — you only need to scan QR once
- Messages are sent with 1.5–3 second delay (anti-spam)
- Supports: text, images, videos
- Pakistan numbers (03xx) are auto-converted to international format

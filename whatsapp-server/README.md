# Fabric POS WhatsApp helper

This is the existing QR-linked WhatsApp Web helper. It has no paid provider account configured and is not an official WhatsApp Business API connector. SMS delivery remains unconfigured. Do not assume unlimited free marketing delivery or delivery guarantees.

## Start on this Windows PC

1. Install Chrome or Edge. The helper detects the installed Windows browser. For another location set `WHATSAPP_CHROME_PATH` to the absolute executable path.
2. From `whatsapp-server`, run `npm ci`, then `npm run doctor`. Browser downloads are disabled: the doctor checks an installed browser with a local blank page and never reads WhatsApp authentication or sends messages.
3. Run `npm start` and keep one server instance running. It binds only to `127.0.0.1:3001`.
4. Open Fabric POS > WhatsApp Bulk. Link the displayed QR using the phone's Linked Devices menu. `Authenticated / loading chats` is not yet `Connected`; only the ready event enables sending.
5. Record the recipient's WhatsApp number and explicit WhatsApp consent in Marketing before sending. Opted-out contacts are blocked. Sending requires owner or manager access.

Default permitted frontend origins are localhost/127.0.0.1 ports 8000 and 8001. Set `WHATSAPP_ALLOWED_ORIGINS` to a comma-separated exact allowlist for a different local installation.

## Preservation and recovery

- Existing `.wwebjs_auth` is reused. Never delete it as a first troubleshooting step.
- Revoked sessions are renamed to `session.revoked-<timestamp>` only after the browser is closed. They are retained as recoverable backups, not erased.
- Locked-session errors appear in status; retries are bounded and initialization is serialized. A browser navigation rejection schedules recovery instead of an unhandled rejection. Startup has a 120-second timeout.
- Stop the server with Ctrl+C before restarting. Do not run a second instance against the same profile. If a locked-profile message persists, close only this helper's Chrome process and restart.
- QR linking/relinking must be checked on the actual phone. No test message was sent during automated QA. Do not infer real delivery from offline tests.

## Authorization

The helper creates a random shared signing key in `storage/app/private/whatsapp-server.key` on first startup. Do not commit, share or put this key in frontend code. Laravel verifies active customers, owner/manager role, channel consent and opt-out status, then signs the recipient list, text and attachment for 60 seconds. The Node send endpoint rejects unsigned, tampered, expired and replayed requests. Changing consent is honored at the next authorization; authorize immediately before sending. Local machine administrators remain trusted.

## Verification

`npm test` exercises serialized initialization, navigation failure recovery, authentication state, backup preservation and signed-send validation without connecting to WhatsApp. `npm run doctor` checks installed Chrome compatibility. The tested workstation has Node 25.6.1 and Chrome 155.0.8059.41; use the doctor when changing runtimes.

## Dependency review, 2026-10-15

- whatsapp-web.js pinned to 1.34.7 and its compatible Puppeteer 24.38.0 retained.
- Multer upgraded to 2.4.0 using the same supported disk-storage API.
- basic-ftp overridden to 6.2.3 to address GHSA-c475-qrg2-pj4r and later fixes; this helper does not use FTP URLs. Lockfile updated.
- Audit reduced from 9 to 5 high findings. Remaining chain: extract-zip -> @puppeteer/browsers -> puppeteer-core / puppeteer -> whatsapp-web.js. extract-zip 2.0.1 has no patched release at review time. Browser archive downloading/extraction is disabled and no user archive is extracted by this application. The audit is **not clean**; replace/update this dependency chain when compatible upstream fixes are available. Do not use `npm audit fix --force` to silently swap the WhatsApp runtime.
- No DEP0190 warning occurred in doctor/tests; the application starts Node directly and introduces no shell-enabled child process. Real phone-link startup remains to be verified.

Sources: [LocalAuth lifecycle](https://docs.wwebjs.dev/authStrategies_LocalAuth.js.html), [basic-ftp releases](https://github.com/patrickjuchli/basic-ftp/releases), [unpatched extract-zip advisory](https://github.com/advisories/GHSA-jmr9-qjv8-65gv), [official WhatsApp Platform pricing](https://whatsappbusiness.com/products/platform-pricing/).

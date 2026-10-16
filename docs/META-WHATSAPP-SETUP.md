# Official Meta WhatsApp Cloud API — test setup

The WhatsApp page defaults to the official Meta connector. The old QR helper remains available only when `WHATSAPP_DRIVER=qr` is explicitly set. No Node helper or QR login is needed for Meta.

Current implementation supports credential verification and the fixed `hello_world` sample to one explicitly configured verified test recipient. It does not enable production campaigns, arbitrary bulk messages or SMS. This boundary respects the requested free-testing setup; Meta production pricing is not universally free.

## Credentials

In Meta Developer Apps, open your WhatsApp app's API Setup. Use the Meta-provided **test phone number**, not a registered production number. Add and verify your consenting test recipient there. Copy the access token, Phone Number ID and supported Graph version from that screen into the project’s local `.env`:

```dotenv
WHATSAPP_DRIVER=meta
META_WHATSAPP_ACCESS_TOKEN=
META_WHATSAPP_PHONE_NUMBER_ID=
META_GRAPH_VERSION=
META_WHATSAPP_TEST_NUMBER_CONFIRMED=false
META_WHATSAPP_TEST_RECIPIENT=
```

For the recipient use country code plus digits, no `+` or spaces. Set `META_WHATSAPP_TEST_NUMBER_CONFIRMED=true` only after checking that the Phone Number ID belongs to Meta's test number. This is a local operator declaration, not automatic detection of Meta billing status. Never point test mode at a production number on the assumption it will be free.

Run `php artisan config:clear`, then refresh WhatsApp Bulk. The owner can choose **Check Meta connection**; this only reads the phone-number ID. When ready, the owner can check the recipient confirmation and choose **Send hello_world test**. The app does not send anything during setup, page loading or credential checking.

Tokens stay on the server and are never included in page props. Temporary tokens may expire; update them locally. Do not paste tokens into chats or commit `.env`. Errors display sanitized codes, not raw provider responses. Message requests are never automatically retried after timeouts, to avoid duplicate sends. API acceptance does not prove delivery.

Production use still requires a separate scope decision: approved templates, current Meta rates, recipient consent, queues, signed webhooks and delivery-state tracking. Those are not claimed complete by this test connector.

References: [Meta's official Cloud API collection](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api), [Meta sample message](https://www.postman.com/meta/whatsapp-business-platform/request/9php9mt/send-test-message), [WhatsApp pricing](https://whatsappbusiness.com/products/platform-pricing/).

## Local discovery on 16 October 2026

The Laragon `.env`, app configuration and matching process environment variable names had no Meta access-token or Phone Number ID settings. The repository contained an existing QR authentication directory; its private contents were not inspected. Credentials may have been added in the Meta dashboard or another location; that does not automatically configure this Laravel copy.

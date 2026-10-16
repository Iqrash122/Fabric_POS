# Client report fixes and QA — 2026-10-16

Project: `C:\laragon\www\fabric-pos-only-final-updated` (confirmed by client).
Source: Fabric POS Developer Fix Report, 15 October 2026, 12 pages / 25 tracked points.

## Changes by report ID

| ID | Result | Implementation / verification |
|---|---|---|
| E01 | Fixed | Shared UTC timestamp parser accepts SQL, ISO and PostgreSQL +00/+0000/+00:00 formats. Missing/invalid values render empty input or an em dash without crashing Customers. Invalid timezone safely falls back to UTC. |
| E02 | Fixed | Vendor payment dates use the same shop-timezone formatter; no duplicate Z suffix. |
| E03 | Code fixed; phone acceptance pending | Logout closes the browser, retries Windows rename errors and preserves revoked authentication as a backup instead of unlinking a live profile. Synthetic lifecycle test passes. |
| E04 | Phone acceptance pending | QR, authenticated/loading, connected and disconnected states are distinct; only ready permits sending. Actual phone linking/relinking requires the account holder's device. |
| E05 | Code fixed; live-session acceptance pending | Single initialization promise, generation checks, one retry timer, navigation-error recovery and bounded startup timeout. Offline race/retry tests pass. |
| E06 | Fixed setup path | Installed Chrome/Edge detection, configurable executable, doctor command and disabled browser archive downloads. Actual headless Chrome startup/render/close check passes without WhatsApp login. |
| E07 | Fixed | Promotion editing uses the safe date parser; edit workflow passes browser regression. |
| U01 | Fixed | Purchase create/edit lines have visible Product, Color, Quantity, Purchase rate, Discount and Extra cost labels with units; controls shrink inside mobile drawers. |
| U02 | Fixed | Cash/bank ledger uses account names, readable transaction names and document references where available through the shared report presenter. |
| U03 | Fixed | From account / To account labels; source account excluded from destination options. Backend continues rejecting same-account transfers. |
| U04 | Fixed | Sales display and CSV/XLSX use customer or Walk-in, invoice reference and consistently scaled currency values. |
| U05 | Fixed | Purchase reports show vendor, bill/supplier references, local date, subtotal, discount, extra cost and total in major currency units. Regression confirms 200000 minor units displays/exports 2000.00. |
| U06 | Fixed | Stock reports show fabric/color, roll/barcode, quantity unit, received/current/reserved/available quantity, unit cost and stock value. Mixed meter/piece rows share stable columns. |
| U07 | Fixed | Movement reports show signed quantities, explicit units, fabric/color/roll, readable movement and source reference, reason and local date. |
| U08 | Fixed | Expense report displays reference, account, category, amount, local date and note; technical IDs/idempotency keys/private paths are omitted from visible report and exports. |
| U09 | Fixed | New customer action available on Customers, using existing validation; successful creation refreshes account rows. Browser regression passes. |
| U10 | Fixed | Read-only backend quote uses the same promotion eligibility/priority/stacking and money routines as posting. Line/checkout totals, exact tender and change use discounted values. Posting is disabled until the current cart quote arrives; stale responses are aborted. Server rejects a changed quoted total before posting stock/cash. 3000 -> 2400 / 600 discount checked in PHP and browser. |
| U11 | Fixed readiness explanation | Draft and owner approval remain separate. Approval does not imply sending. Unconfigured approved campaigns show a blocked reason instead of Try launch. No fabricated sent/delivered status. |
| U12 | Fixed | Customer return uses invoice references and associated sale items; selecting an item sets its invoice. Remaining returnable quantity shown. Vendor return uses bill references and purchased rolls filtered by vendor/bill; selecting a roll supplies its association. Opening stock is not falsely offered as a purchased roll. |
| U13 | Fixed | Return forms show local error summaries without the duplicate global alert; validation uses sale invoice, sale item, return quantity, purchase bill and roll names. Existing backend association/quantity/condition/resolution checks retained. |
| U14 | Fixed | Percentage display removes insignificant trailing zeros, e.g. 20%. |
| U15 | Automated coverage passed | All 14 pages checked for viewport overflow at 320, 390, 768, 1280, 1440 and 1920px. Existing form/modal/CRUD tests also pass. This is not a claim of physical-device testing on every browser. |
| C01 | Configuration gap explicitly retained | Client requested a free option. Existing QR helper retained and hardened; no paid official provider credentials fabricated. Laravel checks role, active customer, WhatsApp consent and opt-out, then issues a signed 60-second send authorization. Node rejects unsigned/tampered/expired/replayed sends. Official automated campaigns and SMS remain unconfigured. |
| Q01 | Verified and ambiguity fixed | Same fabric with Blue/Red purchase lines produces distinct rolls/barcodes. Scanning and sale reduce only the correct color; invoice/thermal snapshot stays Blue. Product-level searches matching multiple rolls now ask the user to choose a color/roll instead of silently selecting the first. |
| S01 | Partially resolved; upstream advisory remains | Compatible dependencies updated, Multer 2.4.0, basic-ftp 6.2.3 security override. Audit reduced 9 -> 5 high findings. Remaining extract-zip chain has no patched release; browser archive downloads disabled and installed Chrome used. Doctor/tests emit no DEP0190; actual linked-session startup remains to check. |

## QA evidence

- PHP suite: 239 tests / 1380 assertions passed (SQLite in-memory).
- Date regression: 2 tests passed, covering timestamp variants, invalid values and timezone fallback.
- WhatsApp offline tests: 5 passed (single initialization, state progression, retry recovery, auth preservation, signed-send authorization).
- Final combined browser suite: **19/19 passed** on a freshly seeded isolated fixture, including the new promotion/customer-creation regression. An earlier repeated-fixture selector collision was corrected by scoping payment history assertions to the specific vendor.
- TypeScript check, PHPStan and production build passed after application changes.
- WhatsApp doctor: Node v25.6.1, Chrome/155.0.8059.41, installed browser; local page only.
- Report export checks cover all 12 report types in CSV and XLSX.
- Existing regression coverage includes vendor/customer old debt, advances, payments, purchases/receipt, reservations/counts, returns/refunds, promotion snapshots, barcode printing and thermal receipts.

## Data preservation

Application database, `.env`, APP_KEY and existing WhatsApp authentication were not reset. Browser test writes were restricted to `storage/framework/testing/fabric-browser.sqlite`; PHP tests use memory SQLite. No Docker volumes changed. No real customer message or physical print job was sent. No existing authentication directory was deleted or renamed during QA; preservation behavior used a synthetic temporary fixture.

## Remaining external acceptance

1. Account holder opens WhatsApp Bulk and scans the QR; confirm connected, disconnect/relink and process stability with the actual phone. See `whatsapp-server/README.md` for startup/recovery steps.
2. Actual external message delivery requires a deliberately chosen consenting test recipient. Automated tests deliberately do not send messages. SDK send success is not proof of delivered/read status.
3. Official campaign/SMS delivery needs an approved configured provider; no unlimited free service was promised. The QR helper is not an official Business API connector.
4. Five high transitive npm audit findings remain. Upstream extract-zip has no patched release at review time. See the README/advisory before enabling browser downloads or changing the pinned WhatsApp/Puppeteer runtime.

## Main files

- `resources/js/pages/fabric/dates.ts`: safe timestamp normalization.
- `resources/js/pages/fabric/index.tsx`, `fabric.css`: forms, totals, selectors, display and readiness states.
- `app/Domain/FabricInventory.php`: quote and posting-total guard.
- `app/Domain/FabricReportPresenter.php`: shared screen/export representation.
- `app/Http/Controllers/FabricController.php`, `routes/web.php`: quote, report and signed messaging authorization endpoints.
- `whatsapp-server/lifecycle.js`, `authorization.js`, `doctor.js`, `server.js`: lifecycle, auth preservation, signed dispatch and setup verification.
- Tests: `tests/js/dates.test.mjs`, `tests/Feature/Fabric/WorkflowTest.php`, `tests/browser/client-report.spec.ts`, responsive/workflow tests, `whatsapp-server/tests`.

References: [LocalAuth implementation](https://docs.wwebjs.dev/authStrategies_LocalAuth.js.html), [basic-ftp fixes](https://github.com/patrickjuchli/basic-ftp/releases), [extract-zip advisory: no patched version](https://github.com/advisories/GHSA-jmr9-qjv8-65gv), [WhatsApp Platform pricing](https://whatsappbusiness.com/products/platform-pricing/).

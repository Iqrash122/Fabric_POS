# Live login verification — 16 October 2026

Target: Laragon application at http://127.0.0.1:8000, signed in with the supplied owner account through the browser.

## Verified live

- Dashboard and all 13 linked functional pages rendered: POS, Customers, Vendors, Purchases, Inventory, Returns, Cash/Bank, Expenses, Promotions, Marketing, WhatsApp Bulk, Reports and Settings.
- Customers rendered without a date crash. Vendor payment dates rendered in shop time without Invalid Date.
- Exact barcode FS-OR-00003 added the correct Test / OR-00003 stock item to the draft invoice, quantity 1, total PKR 100. A PKR 20 manual discount updated subtotal, checkout and exact tender to PKR 80.
- Product-name search for Prodcut correctly required a choice between two stocked rolls. Product barcode 0001 correctly explained that Product 2 has no recorded stock and offered Add opening stock.
- Inventory showed roll barcodes and label/PDF links. The printable OR-00003 label visually rendered its barcode and stock identity. No physical print job was sent.
- Selecting a return line automatically selected FS-00012 and filtered the available lines. Empty return submission produced one friendly validation summary; no return was posted.
- Choosing Bank as transfer source left only Cash as destination.
- Vendor opening-balance choices distinguish We owe vendor and Vendor advance paid. Existing vendor advances and settled old-debt records rendered.
- Sales report displayed names, references, local dates and major currency amounts. Stock report displayed all columns, meter/piece units and quantities matching Inventory.
- Marketing clearly explained provider readiness; WhatsApp Bulk showed Disconnected with sending disabled.

## Additional fixes found during this pass

1. Return lines for the same product had identical labels. Added historical product/color/roll identity, with a line-ID fallback for missing snapshots. Reloaded the production build and verified distinct ROLL-101 / OR-00001 labels and invoice auto-selection.
2. Vendor installment reminders and Settings opening balances showed raw party IDs. Replaced these with available party names, retaining an ID fallback for unavailable parties. Verified live names after rebuilding.

TypeScript check and production build passed after these display changes. Screenshot: [return selection](qa/live-return-verification-2026-10-16.png).

## Scope and pending checks

This was a live read/draft/validation check, not destructive CRUD against business records. No sale, payment, receipt, stock adjustment, return or customer message was posted; draft test selections were cleared. Current stock remained unchanged (OR-00003: 9 pieces).

The live Promotions table is empty, so promotion edit and automatic percentage discounts were not verified against a live campaign. Earlier isolated regression evidence is in CLIENT-REPORT-QA-2026-10-16.md (239 PHP tests and 19 browser tests); those counts are not a new full-suite run for this live pass.

WhatsApp phone linking/relinking and real message delivery remain unverified. Official campaign/SMS provider configuration and the previously documented upstream dependency findings remain open. Export downloads and physical scanners/printers were not re-tested in this live pass.

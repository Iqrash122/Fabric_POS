# Barcode checkout and receipt printing

The POS supports USB barcode scanners that act as a keyboard. Configure the scanner to send **Enter** or **Tab** after each barcode. Open **Sales / POS**, click **Barcode or search**, and scan a product or roll barcode. Rapid scans are processed in order; repeating a roll increases its quantity, up to available stock. For fabric, adjust the number of meters before billing.

**Post sale** saves the invoice and stock/payment movements first. With **Print receipt after sale** checked, the browser then opens its print dialog for that saved receipt. A failed sale does not print or clear the cart. Canceling the print dialog does not cancel the saved sale. Use **Print Thermal** in the success banner or **Thermal** beside a recent invoice to preview and reprint; reprinting never posts another sale. The A4 invoice download remains available separately.

## 80mm printer setup

1. Install the thermal printer's Windows driver and connect the printer.
2. Keep **Settings / Audit → Thermal receipt width (mm)** at **80**.
3. In the browser print dialog, select that thermal printer and its **80mm / receipt roll** paper profile.
4. Set **scale 100%**, **margins none**, and turn **headers and footers off**. Use portrait orientation. The receipt includes an inner 4mm safety margin and sizes its page height to its content.
5. Print one test receipt on the physical printer. Paper feed and automatic cutting depend on the printer driver. The application uses the browser's print dialog; it does not silently select a printer or send raw USB commands.

Receipt content includes store details, invoice reference, date, cashier, customer, products/rolls, meter or piece quantities, rates, discounts, total, payment accounts, tendered amount, change, and balances. Receipt branding comes from the existing business and receipt-footer settings.

Tendered cash is retained separately from the actual collection. For example, a PKR 450 sale paid with PKR 1,000 records PKR 450 as collection and prints PKR 550 change. Split payments preserve the bank amount and return excess from cash. Bank overpayment is rejected rather than silently reducing the recorded bank transfer. Older invoices without captured tendered values omit that information on reprints.

## Verification

Backend receipt tests cover saved tender/change, product snapshots, credit balances after later collections, duplicate submission replay, rejected bank overpayment, escaped business text, and legacy receipts. Browser tests simulate scanner keyboard input, rapid repeated scans, Enter/Tab terminators, unknown barcodes, auto-print, preview/reprint, print-only content and the 80mm width. Physical scanner/printer operation still needs a test with the actual attached devices.

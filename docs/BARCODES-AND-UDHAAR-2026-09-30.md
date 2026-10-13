# Barcode labels and previous udhaar — 30 September 2026

## Inventory barcode labels

Every individual stock row now displays its barcode image and exact barcode text. **Print label** opens a printable label with the shop, product, color, roll number and barcode; **PDF** downloads the label. Label dimensions follow Settings. Print at 100% scale with browser headers and footers disabled.

Labels encode the stored value with Code 128 using [Picqer's barcode generator](https://github.com/picqer/php-barcode-generator). The previous Code 39 renderer uppercased values and replaced unsupported symbols, which could produce a label different from its stored lookup code. The new renderer preserves lowercase and punctuation, supplies white quiet zones and rejects unencodable values instead of silently changing them. New stock entries require printable ASCII barcode values. All four existing live stock barcodes were checked read-only and are supported.

Leaving the stock barcode field blank generates a unique barcode. The stock label identifies the individual stock item; catalogue product barcodes remain visible separately.

Scanning a label adds its stock item to the POS cart. Enter the quantity being sold and post the sale. Only a successful sale deducts that quantity. Viewing, printing or scanning alone does not deduct inventory. Retrying the same sale request cannot deduct it twice.

## Previous udhaar and advance adjustment

Vendors and Customers now include a **Previous udhaar** section:

- Owners can use **Add previous udhaar** for an old balance without creating a purchase or sale. Vendor debt is payable; customer debt is receivable. These entries do not create stock or cash transactions.
- **Adjust advance against udhaar** applies an existing payment/collection advance or opening advance to that old balance. It records no second cash movement. Amount, account ownership, dates, permissions and duplicate requests are checked.
- **Pay vendor → Pay outstanding balance** settles old vendor debt before unpaid purchase bills.
- **Collect udhaar / Record later collection → Collect outstanding / udhaar** settles old customer debt before unpaid invoices. A specific invoice or an explicit advance can also be selected.
- The old-debt table shows original, settled and remaining amounts. Dashboard and outstanding reports include these adjustments.
- New credit purchases create payable when posted. For a new customer credit sale, select a customer in POS and authorize the unpaid balance; the existing 100% Credit shortcut is available to owner/manager.

Example: 5,000 previous debt minus 2,000 existing advance leaves 3,000 unpaid. An advance reduces debt when applied; it is not converted into new debt.

## Verification

- PHP suite: **235 tests passed, 1,331 assertions**.
- Browser suite: **14 tests passed** on isolated SQLite data, including vendor/customer previous-debt workflows, barcode preview, printable label/PDF, exact lowercase/slash lookup, scanner keyboard input and stock deduction.
- Browser sale example: 20.000 meters before sale, 2.500 sold, 17.500 remaining.
- TypeScript, PHPStan and production build passed.
- Barcode label screenshot reviewed at `test-results/fabric-receipt-USB-scanner-28247-c-print-and-thermal-reprint/stock-barcode-label.png`.

Tests used isolated databases and simulated browser printing/scanner keyboard input. Physical label printers and scanner hardware were not exercised. No live inventory, payment or previous-debt entries were created by this testing.

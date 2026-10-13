# Fabric POS workflow

The application is intentionally Fabric-only.

1. Create vendors, categories, colors, products and cash/bank accounts.
2. Record a purchase bill and post it.
3. Receive fabric partially or fully into individually tracked rolls.
4. Print/use roll barcodes and manage exact on-hand/reserved quantities.
5. Sell fabric through POS as walk-in, paid, split-payment or authorized credit sales.
6. Record later customer collections or apply customer advances/credits.
7. Record vendor payments, advances and installment settlements.
8. Process customer returns/refunds and vendor returns/refunds.
9. Track expenses, transfers, physical counts, promotions and reports.

No action creates, links to, or depends on a stitching order.

In Inventory, each individual stock row shows its barcode and provides **Print label** and **PDF**. Attach the stock label to the item, scan it into POS, enter the sold quantity and complete the sale to deduct stock. See [barcode and previous-udhaar instructions](BARCODES-AND-UDHAAR-2026-09-30.md).

Vendor payments: use **Vendors → Pay vendor**, choose **Pay outstanding balance**, **Pay a specific bill**, or **Advance payment**, then enter the paid amount and Cash / Bank account. Any excess stays as an advance. Use **Advance / credit** to apply money already paid to an unpaid bill. See [the verification report](QA-2026-09-24.md) for tested behavior and coverage.

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $ref }} - {{ $settings->name }}</title>
    <style>
        @page { size: auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef1f4; color: #000; font-family: Arial, sans-serif; }
        .toolbar { max-width: 560px; margin: 20px auto; padding: 0 16px; font-size: 14px; text-align: center; }
        .toolbar button, .toolbar a { display: inline-block; padding: 10px 16px; margin: 4px; border: 1px solid #183d3d; border-radius: 6px; background: #183d3d; color: white; text-decoration: none; cursor: pointer; font: inherit; }
        .toolbar p { line-height: 1.5; color: #39474d; }
        .receipt { width: {{ $widthMm }}mm; margin: 0 auto 24px; padding: 4mm; background: white; font-size: 11px; line-height: 1.4; overflow-wrap: anywhere; }
        .center { text-align: center; }
        h1 { margin: 0 0 4px; font-size: 19px; line-height: 1.2; }
        p { margin: 3px 0; }
        .logo { display: block; max-width: 40mm; max-height: 18mm; margin: 0 auto 6px; }
        .rule { border: 0; border-top: 1px dashed #000; margin: 9px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; margin: 3px 0; }
        .row > :last-child { text-align: right; }
        .row > * { min-width: 0; }
        .numbers { font-variant-numeric: tabular-nums; }
        .item { padding: 6px 0; border-bottom: 1px dotted #777; break-inside: avoid; }
        .item-name { font-weight: bold; }
        .item-meta { font-size: 10px; }
        .columns { display: grid; grid-template-columns: 1fr 1fr 1.25fr; gap: 5px; margin-top: 3px; }
        .columns > :nth-child(n+2) { text-align: right; }
        .total { font-size: 17px; font-weight: bold; border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 6px 0; margin: 8px 0; }
        .summary, .receipt-footer { break-inside: avoid; }
        .footer-message { white-space: pre-line; }
        .receipt-footer { margin-top: 12px; }
        @media print {
            html, body { width: {{ $widthMm }}mm; margin: 0 !important; padding: 0 !important; background: white; }
            .toolbar { display: none !important; }
            .receipt { margin: 0; box-shadow: none; }
        }
    </style>
    <style id="receipt-page-size"></style>
</head>
<body>
    <nav class="toolbar" aria-label="Receipt actions">
        <button type="button" id="print-receipt">Print receipt</button>
        <a href="/fabric/pos" target="_top">Back to POS</a>
        <p>{{ $widthMm }}mm thermal paper · Scale 100% · Margins none<br>Turn off browser headers and footers. Select your receipt printer in the print dialog.</p>
    </nav>
    <main class="receipt numbers" aria-label="Sale receipt">
        <header class="center">
            @if($logo)<img class="logo" src="{{ $logo }}" alt="{{ $settings->name }} logo">@endif
            <h1>{{ $settings->name }}</h1>
            @if($settings->address)<p class="footer-message">{{ $settings->address }}</p>@endif
            @if($settings->phone)<p>Tel: {{ $settings->phone }}</p>@endif
            <p><strong>SALE RECEIPT</strong></p>
        </header>
        <hr class="rule">
        <div class="row"><span>Receipt</span><strong>{{ $ref }}</strong></div>
        <div class="row"><span>Date</span><span>{{ $saleDate }}</span></div>
        @if($cashier)<div class="row"><span>Cashier</span><span>{{ $cashier }}</span></div>@endif
        <div class="row"><span>Customer</span><span>{{ $contact['name'] }}</span></div>
        @if($contact['phone'])<div class="row"><span>Phone</span><span>{{ $contact['phone'] }}</span></div>@endif
        <hr class="rule">
        <div class="row"><strong>ITEMS</strong><span>Amounts in {{ $currency }}</span></div>
        <div class="columns"><span>Qty</span><span>Rate</span><span>Amount</span></div>
        @foreach($lines as $line)
            <section class="item">
                <div class="item-name">{{ $line['name'] }}</div>
                <div class="item-meta">Roll: {{ $line['roll'] }}</div>
                <div class="columns"><span>{{ $line['qty_fmt'] }}</span><span>{{ $line['rate_fmt'] }}</span><strong>{{ $line['net_fmt'] }}</strong></div>
                @if($line['discount'] > 0)<div class="row item-meta"><span>Discount included</span><span>{{ $line['discount_fmt'] }}</span></div>@endif
            </section>
        @endforeach
        <section class="summary">
            <p>{{ count($lines) }} item line{{ count($lines) === 1 ? '' : 's' }}</p>
            <div class="row"><span>Subtotal</span><span>{{ $grossFmt }}</span></div>
            @if($discount > 0)<div class="row"><span>Discount</span><span>-{{ $discountFmt }}</span></div>@endif
            <div class="row total"><span>TOTAL</span><span>{{ $totalFmt }}</span></div>
            @foreach($payments as $payment)<div class="row"><span>{{ $payment['account'] }}</span><span>{{ $payment['amount_fmt'] }}</span></div>@endforeach
            <div class="row"><strong>Paid at sale</strong><strong>{{ $paidFmt }}</strong></div>
            @if($tendered !== null)
                <div class="row"><span>Tendered</span><span>{{ $tenderedFmt }}</span></div>
                <div class="row"><strong>Change</strong><strong>{{ $changeFmt }}</strong></div>
            @endif
            <div class="row"><span>Balance at sale</span><span>{{ $balanceAtSaleFmt }}</span></div>
            @if($balance !== $balanceAtSale)<div class="row"><span>Current balance</span><span>{{ $balanceFmt }}</span></div>@endif
        </section>
        <footer class="receipt-footer center">
            <hr class="rule">
            <p class="footer-message">{{ $footerMsg ?: 'Thank you for shopping with us!' }}</p>
            <p>Please keep this receipt for your records.</p>
            <p>{{ $ref }}</p>
        </footer>
    </main>
    <script>
        const receipt = document.querySelector('.receipt');
        function sizeReceipt() {
            const heightMm = Math.max(60, Math.ceil(receipt.getBoundingClientRect().height * 25.4 / 96) + 2);
            document.getElementById('receipt-page-size').textContent = `@page { size: {{ $widthMm }}mm ${heightMm}mm; margin: 0; }`;
        }
        async function printReceipt() {
            await document.fonts.ready;
            sizeReceipt();
            window.focus();
            window.print();
        }
        document.getElementById('print-receipt').addEventListener('click', printReceipt);
        window.addEventListener('beforeprint', sizeReceipt);
        window.addEventListener('afterprint', () => {
            if (window.parent !== window) window.parent.postMessage({ type: 'fabric-receipt-printed' }, window.location.origin);
        });
        window.addEventListener('load', async () => {
            await document.fonts.ready;
            sizeReceipt();
            if (new URLSearchParams(window.location.search).get('autoprint') === '1') await printReceipt();
        });
    </script>
</body>
</html>

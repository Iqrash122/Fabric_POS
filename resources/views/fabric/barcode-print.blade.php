<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Barcode label — {{ $roll->roll_number }}</title>
    <style>
        @page { size: {{ $width }}mm {{ $height }}mm; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f6f5; color: #111; font-family: Arial, sans-serif; }
        .tools { padding: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        button, .tools a { padding: 10px 16px; font: inherit; border: 1px solid #adc8bf; border-radius: 6px; cursor: pointer; background: white; color: #164c41; }
        button { background: #164c41; color: white; }
        .help { padding: 0 20px; max-width: 650px; font-size: 14px; line-height: 1.5; }
        .label { margin: 20px; width: {{ $width }}mm; height: {{ $height }}mm; padding: 2mm; background: white; text-align: center; display: flex; flex-direction: column; justify-content: center; gap: 1mm; overflow: hidden; }
        .shop { font-size: 9pt; font-weight: bold; }
        .product { font-size: 8pt; overflow-wrap: anywhere; }
        .roll, .code { font: 8pt monospace; overflow-wrap: anywhere; }
        .barcode { display: block; width: 100%; height: {{ max(6, min(14, $height - 20)) }}mm; }
        @media print { body { background: white; } .tools, .help { display: none; } .label { margin: 0; break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="tools"><button type="button" onclick="window.print()">Print label</button><a href="/fabric/document/barcode/{{ $roll->id }}">Download PDF</a></div>
    <p class="help">Attach this label to this stock item. In POS, scan it and enter the quantity being sold. Completing the sale deducts that quantity from this exact stock item. Print at 100% scale, with browser headers and footers off; label size {{ $width }} × {{ $height }} mm.</p>
    <main class="label" aria-label="Printable stock label">
        <div class="shop">{{ $settings->name }}</div>
        <div class="product">{{ $roll->product_name }} · {{ $roll->color_name }}</div>
        <div class="roll">{{ $roll->roll_number }}</div>
        <img class="barcode" src="{{ $barcode }}" alt="Barcode {{ $roll->barcode }}">
        <div class="code">{{ $roll->barcode }}</div>
    </main>
</body>
</html>

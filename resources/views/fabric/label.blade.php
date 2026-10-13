<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:7px}body{font-family:DejaVu Sans,sans-serif;color:#123E4A;font-size:8px;text-align:center;margin:0}.name{font-size:11px;font-weight:700}.meta{margin:2px 0}.barcode{width:100%;height:47px;object-fit:contain}
</style></head><body><div class="name">{{ $settings->name }}</div><div class="meta">{{ $roll->product_name }} · {{ $roll->color_name }} · {{ $roll->roll_number }}</div><img class="barcode" src="{{ $barcode }}"><div>{{ $roll->barcode }}</div></body></html>

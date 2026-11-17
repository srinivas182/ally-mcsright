@php
    $f = (array) $prep->facts;
    $primary = $brand['colors']['primary'] ?? '#111111';
    $accent = $brand['colors']['accent'] ?? '#C9A227';
@endphp
<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { margin: 28px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #1f2328; font-size: 10.5pt; }
    h1 { font-family: 'DejaVu Serif', serif; color: {{ $primary }}; font-size: 22pt; margin: 0; }
    .price { font-size: 18pt; font-weight: bold; color: {{ $accent }}; }
    .hero { width: 100%; height: 300px; object-fit: cover; }
    table.grid { width: 100%; border-collapse: collapse; } table.grid td { width: 33%; padding: 2px; }
    table.grid img { width: 100%; height: 120px; object-fit: cover; }
    .staged { font-size: 7pt; color: #555; }
    .foot { border-top: 3px solid {{ $accent }}; margin-top: 10px; padding-top: 8px; }
</style></head>
<body>
    @if (isset($photos[0]))<img class="hero" src="{{ $photos[0]['src'] }}" alt="">@if ($photos[0]['staged'])<div class="staged">Virtually staged</div>@endif @endif
    <h1>{{ $prep->street }}@if ($prep->city), {{ $prep->city }}@endif</h1>
    <p>@if (isset($f['price']))<span class="price">${{ number_format((int) $f['price']) }}</span> · @endif
        @if (isset($f['beds'])){{ $f['beds'] }} bd · @endif @if (isset($f['baths'])){{ $f['baths'] }} ba · @endif @if (isset($f['sqft'])){{ number_format((int) $f['sqft']) }} sq ft @endif</p>
    @if (count($photos) > 1)
        <table class="grid"><tr>@foreach (array_slice($photos, 1, 3) as $p)<td><img src="{{ $p['src'] }}" alt="">@if ($p['staged'])<div class="staged">Virtually staged</div>@endif</td>@endforeach</tr></table>
    @endif
    @if ($prep->description)<p>{{ $prep->description }}</p>@endif
    <table class="foot" style="width:100%"><tr>
        <td><strong>{{ $brand['details']['display_name'] ?? '' }}</strong><br>{{ $brand['details']['phone'] ?? '' }} · {{ $brand['details']['email'] ?? '' }}<br>{{ $brand['brokerage'] ?? '' }}@if (! empty($brand['details']['licence_line']))<br>{{ $brand['details']['licence_line'] }}@endif</td>
        @if ($qr)<td style="text-align:right;width:120px"><img src="{{ $qr }}" style="width:110px;height:110px" alt=""><br><span style="font-size:7.5pt">Scan for photos and to book a showing</span></td>@endif
    </tr></table>
    <p style="font-size:7pt;color:#666">Information deemed reliable but not guaranteed. Equal Housing Opportunity.</p>
</body></html>

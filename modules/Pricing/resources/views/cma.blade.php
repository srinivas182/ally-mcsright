@php
    $usd = fn (int $c): string => '$'.number_format(intdiv($c, 100));
    $t = fn (string $en, string $es): string => $es ? $es : $en;
    $L = $es ? [
        'title' => 'Análisis comparativo de mercado', 'range' => 'Precio sugerido', 'likely' => 'Más probable', 'conf' => ['high' => 'Confianza alta', 'medium' => 'Confianza media', 'low' => 'Confianza baja', 'none' => 'Sin datos'],
        'comps' => 'Viviendas comparables', 'sold' => 'Vendida', 'asking' => 'Precio de lista', 'adj' => 'Ajustada a', 'market' => 'El mercado', 'net' => 'Lo que recibiría', 'sum' => 'Resumen',
        'dom' => 'días en el mercado (mediana)', 'stl' => 'del precio de lista', 'moi' => 'meses de inventario', 'trend' => 'precio por pie² vs. 3 meses antes',
        'disc' => 'Esta es una opinión de precio de su agente basada en ventas comparables, no una tasación. Las comisiones son negociables. Su compañía de títulos confirma los costos de cierre.',
    ] : [
        'title' => 'Comparative market analysis', 'range' => 'Suggested price', 'likely' => 'Most likely', 'conf' => ['high' => 'High confidence', 'medium' => 'Medium confidence', 'low' => 'Low confidence', 'none' => 'No data'],
        'comps' => 'Comparable homes', 'sold' => 'Sold', 'asking' => 'Asking', 'adj' => 'Adjusted to', 'market' => 'The market', 'net' => 'What you would walk away with', 'sum' => 'Summary',
        'dom' => 'median days on market', 'stl' => 'of list price', 'moi' => 'months of homes for sale', 'trend' => 'price per sq ft vs the 3 months before',
        'disc' => 'This is your agent\'s opinion of price from comparable sales, not an appraisal. Commissions are negotiable. Your title company confirms closing costs.',
    ];
    $s = $cma->subject;
    $m = $r['market'] ?? [];
@endphp
<!doctype html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>{{ $L['title'] }} · {{ $cma->title }}</title>
    @include('pricing::_style')
</head>
<body>
<div class="wrap">
    @include('pricing::_brand')
    <h1>{{ $cma->title }}</h1>
    <p class="muted">{{ $L['title'] }} · {{ trim(($s['city'] ?? '').', '.($s['state'] ?? '').' '.($s['zip'] ?? ''), ', ') }} · {{ now()->toFormattedDateString() }}</p>

    <div class="card">
        <p class="muted">{{ $L['range'] }}</p>
        <p class="range">{{ $usd((int) ($r['low_cents'] ?? 0)) }} – {{ $usd((int) ($r['high_cents'] ?? 0)) }}</p>
        <p><strong>{{ $L['likely'] }}: {{ $usd((int) ($r['likely_cents'] ?? 0)) }}</strong> · <span class="pill">{{ $L['conf'][$r['confidence'] ?? 'none'] ?? '' }}</span></p>
        <p class="muted">{{ $r['confidence_reason'] ?? '' }}</p>
    </div>

    <h2>{{ $L['sum'] }}</h2>
    <div class="card"><p>{{ $summary }}</p></div>

    <h2>{{ $L['comps'] }}</h2>
    @foreach ($r['comps'] ?? [] as $c)
        <div class="card comp">
            @if (! $pdf && $c['photo'])<img src="{{ $c['photo'] }}" alt="{{ $c['address'] }}" loading="lazy">@elseif (! $pdf)<div></div>@endif
            <div>
                <strong>{{ $c['address'] }}</strong>@if ($c['city']), {{ $c['city'] }}@endif
                <div class="muted">{{ $c['reason'] }}</div>
                <div>{{ $c['beds'] ?? '–' }} bd · {{ $c['baths_full'] ?? '–' }}@if ($c['baths_half']).{{ $c['baths_half'] }}@endif ba · {{ $c['sqft'] ? number_format($c['sqft']).' sq ft' : '' }}@if ($c['year_built']) · {{ $c['year_built'] }}@endif</div>
                <div>{{ $r['sold'] ? $L['sold'] : $L['asking'] }} <strong>{{ $usd((int) $c['price_cents']) }}</strong>@if ($c['sold_on']) · {{ $c['sold_on'] }}@endif</div>
                @if ($c['adjustments'] !== [])
                    <table>
                        @foreach ($c['adjustments'] as $a)<tr><td>{{ $a['label'] }}</td><td class="num">{{ $a['cents'] >= 0 ? '+' : '−' }}{{ $usd(abs((int) $a['cents'])) }}</td></tr>@endforeach
                        <tr><td><strong>{{ $L['adj'] }}</strong></td><td class="num"><strong>{{ $usd((int) $c['adjusted_cents']) }}</strong></td></tr>
                    </table>
                @endif
            </div>
        </div>
    @endforeach

    @if (($m['sold_90'] ?? 0) > 0 || ($m['active'] ?? 0) > 0)
        <h2>{{ $L['market'] }} ({{ $m['zip'] }})</h2>
        <div class="card">
            <ul>
                @if ($m['median_dom'] !== null)<li>{{ $m['median_dom'] }} {{ $L['dom'] }}</li>@endif
                @if ($m['sale_to_list'] !== null)<li>{{ $m['sale_to_list'] }}% {{ $L['stl'] }}</li>@endif
                @if ($m['months_of_inventory'] !== null)<li>{{ $m['months_of_inventory'] }} {{ $L['moi'] }}</li>@endif
                @if ($m['ppsf_change'] !== null)<li>{{ $m['ppsf_change'] >= 0 ? '+' : '' }}{{ $m['ppsf_change'] }}% {{ $L['trend'] }}</li>@endif
            </ul>
        </div>
    @endif

    @if (! empty($r['net']))
        <h2>{{ $L['net'] }}</h2>
        <div class="card">
            <table>
                <tr><th></th>@foreach (['low', 'likely', 'high'] as $k)<th class="num">{{ $usd((int) $r['net'][$k]['price_cents']) }}</th>@endforeach</tr>
                @foreach ($r['net']['likely']['lines'] as $i => $line)
                    @if (collect(['low', 'likely', 'high'])->contains(fn ($k) => $r['net'][$k]['lines'][$i]['cents'] !== 0))
                        <tr><td>{{ $line['label'] }}<div class="muted">{{ $line['note'] }}</div></td>@foreach (['low', 'likely', 'high'] as $k)<td class="num">−{{ $usd(abs((int) $r['net'][$k]['lines'][$i]['cents'])) }}</td>@endforeach</tr>
                    @endif
                @endforeach
                <tr><td><strong>{{ $es ? 'Neto estimado' : 'Estimated net' }}</strong></td>@foreach (['low', 'likely', 'high'] as $k)<td class="num"><strong>{{ $usd((int) $r['net'][$k]['net_cents']) }}</strong></td>@endforeach</tr>
            </table>
        </div>
    @endif

    <footer>{{ $L['disc'] }} {{ $brand['brokerage'] ?? '' }}@if (! empty($brand['details']['licence_line'])) · {{ $brand['details']['licence_line'] }}@endif</footer>
</div>
</body>
</html>

@php
    $usd = fn (int $c): string => '$'.number_format(intdiv($c, 100));
    $L = $es ? ['title' => 'Estimado de costos para el comprador', 'price' => 'Precio', 'down' => 'Enganche', 'loan' => 'Préstamo', 'monthly' => 'Pago mensual estimado', 'pi' => 'Capital e intereses',
        'tax' => 'Impuestos a la propiedad', 'ins' => 'Seguro de vivienda', 'mi' => 'Seguro hipotecario', 'hoa' => 'Cuota de la asociación (HOA)', 'cash' => 'Efectivo para el cierre', 'closing' => 'Costos de cierre']
        : ['title' => 'Buyer cost estimate', 'price' => 'Price', 'down' => 'Down payment', 'loan' => 'Loan', 'monthly' => 'Estimated monthly payment', 'pi' => 'Principal and interest',
        'tax' => 'Property taxes', 'ins' => 'Home insurance', 'mi' => 'Mortgage insurance', 'hoa' => 'HOA dues', 'cash' => 'Cash to close', 'closing' => 'Closing costs'];
    $rows = ['principal_interest' => $L['pi'], 'taxes' => $L['tax'], 'insurance' => $L['ins'], 'mortgage_insurance' => $L['mi'], 'hoa' => $L['hoa']];
@endphp
<!doctype html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>{{ $L['title'] }}</title>
    @include('pricing::_style')
</head>
<body>
<div class="wrap">
    @include('pricing::_brand')
    <h1>{{ $sheet->title }}</h1>
    <p class="muted">{{ $L['title'] }} · {{ now()->toFormattedDateString() }}</p>
    <div class="grid3">
        <div class="card"><p class="muted">{{ $L['monthly'] }}</p><p class="range">{{ $usd((int) $r['monthly_total_cents']) }}</p></div>
        <div class="card"><p class="muted">{{ $L['cash'] }}</p><p class="range">{{ $usd((int) $r['cash_to_close_cents']) }}</p></div>
        <div class="card"><p class="muted">{{ $L['price'] }}</p><p class="range">{{ $usd((int) $r['price_cents']) }}</p></div>
    </div>
    <div class="card">
        <table>
            <tr><td>{{ $L['down'] }} ({{ rtrim(rtrim(number_format((float) $i['down_pct'], 2), '0'), '.') }}%)</td><td class="num">{{ $usd((int) $r['down_cents']) }}</td></tr>
            <tr><td>{{ $L['loan'] }} ({{ strtoupper($r['loan']) }}, {{ $i['rate'] }}%, {{ $i['years'] }} {{ $es ? 'años' : 'years' }})</td><td class="num">{{ $usd((int) $r['loan_cents']) }}</td></tr>
            <tr><td>{{ $L['closing'] }}</td><td class="num">{{ $usd((int) $r['closing_cents']) }}</td></tr>
            @foreach ($rows as $k => $label)@if ((int) $r['monthly'][$k] > 0)<tr><td>{{ $label }} / {{ $es ? 'mes' : 'month' }}</td><td class="num">{{ $usd((int) $r['monthly'][$k]) }}</td></tr>@endif @endforeach
        </table>
    </div>
    <footer>@foreach ($r['notes'] as $n){{ $n }} @endforeach {{ $brand['brokerage'] ?? '' }}</footer>
</div>
</body>
</html>

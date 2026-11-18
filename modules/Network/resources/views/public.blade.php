@extends('billing::health.layout')

@section('main')
<section class="hero"><div class="wrap">
    <h1>{{ $card['name'] }}</h1>
    <p class="lead">Real estate agent with {{ $card['brokerage'] }}. Helps {{ mb_strtolower($card['sides']) }} in {{ implode(', ', $card['areas']) }}.</p>
    @if ($card['verified'])<p><strong>Licence checked by their broker.</strong></p>@endif
    @if ($card['trust']['score'] !== null)<p>Referral Trust Score: <strong>{{ $card['trust']['score'] }} out of 100</strong>, from {{ $card['trust']['count'] }} referrals.</p>@endif
</div></section>
<section><div class="wrap">
    @if ($card['bio'])<p>{{ $card['bio'] }}</p>@endif
    <h2>Areas served</h2>
    <ul>@foreach ($card['areas'] as $a)<li>{{ $a }}</li>@endforeach</ul>
    <h2>Languages</h2>
    <p>{{ implode(', ', $card['languages']) }}</p>
    @if ($card['phone'])<p><a href="tel:{{ $card['phone'] }}">Call {{ $card['name'] }}: {{ $card['phone'] }}</a></p>@endif
    <h2>Are you an agent with a client moving here?</h2>
    <p>Agents on RightAlly can send {{ $card['name'] }} a referral from <em>Network</em> in their RightAlly. RightAlly takes no share of referral fees.</p>
</div></section>
@endsection

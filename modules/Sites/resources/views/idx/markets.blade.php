@extends('sites::layout')

@section('content')
<main id="main" class="section">
    <div class="wrap">
        <h1 class="section-title">Real estate market reports</h1>
        <p class="lead-muted">Live figures from the MLS for every area we cover: homes for sale, median prices, price per square foot and how long homes take to sell. Updated hourly.</p>
        @if ($cities === [] && $zips === [])
            <p>Market reports appear here as soon as listings are available. <a href="/contact">Ask an agent</a> about any area.</p>
        @endif
        @if ($cities !== [])
            <h2>Cities</h2>
            <ul class="place-list">@foreach ($cities as $c)<li><a href="/market/{{ $c['slug'] }}">{{ $c['name'] }}</a> <span>{{ number_format($c['count']) }} homes</span></li>@endforeach</ul>
        @endif
        @if ($zips !== [])
            <h2>ZIP codes</h2>
            <ul class="place-list">@foreach ($zips as $z)<li><a href="/market/{{ $z['slug'] }}">{{ $z['name'] }}</a> <span>{{ number_format($z['count']) }} homes</span></li>@endforeach</ul>
        @endif
    </div>
</main>
@endsection

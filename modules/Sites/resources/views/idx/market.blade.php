@extends('sites::layout')

@section('content')
<main id="main" class="market">
    <section class="section">
        <div class="wrap">
            <nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/market">Market reports</a></li>@if ($cityPath)<li><a href="{{ $cityPath }}">{{ $m['city'] }}</a></li>@endif<li aria-current="page">{{ $m['name'] }}</li></ol></nav>
            <h1 class="section-title">{{ $m['name'] }} real estate market</h1>
            <p class="updated">Updated <time datetime="{{ $m['as_of'] }}">{{ $asOf }}</time> from MLS listings</p>
            @if ($sample)<p class="sample-note">These figures come from sample listings while the brokerage's MLS connection is being approved.</p>@endif
            <p class="answer">{{ $summary }}</p>
            @if ($intro)<div class="area-intro">@foreach (preg_split('/\R{2,}/', $intro) as $para)<p>{!! nl2br(e($para)) !!}</p>@endforeach</div>@endif
            <ul class="stats">
                <li><span>Homes for sale</span><strong>{{ number_format($m['active']) }}</strong></li>
                @if ($m['median_price'] !== null)<li><span>Median list price</span><strong>${{ number_format($m['median_price']) }}</strong></li>@endif
                @if ($m['median_ppsf'] !== null)<li><span>Price per sq ft</span><strong>${{ number_format($m['median_ppsf']) }}</strong></li>@endif
                @if ($m['median_dom'] !== null)<li><span>Typical days on market</span><strong>{{ $m['median_dom'] }}</strong></li>@endif
                <li><span>New in 30 days</span><strong>{{ number_format($m['new_30']) }}</strong></li>
                <li><span>Price cuts in 30 days</span><strong>{{ $m['reduced_share'] }}%</strong></li>
            </ul>
            <p><a class="btn btn-primary" href="/homes?q={{ urlencode($m['zip'] ?? $m['city'] ?? '') }}">See all {{ number_format($m['active']) }} homes</a> <a class="btn btn-ghost" href="/contact">Thinking of selling? Ask for a price range</a></p>
        </div>
    </section>

    @if ($homes !== [])
        <section class="section section-alt">
            <div class="wrap">
                <h2 class="section-title">Newest homes in {{ $m['name'] }}</h2>
                <ul class="cards">@include('sites::idx.cards', ['listings' => $homes])</ul>
            </div>
        </section>
    @endif

    <section class="section faq">
        <div class="wrap narrow">
            <h2 class="section-title">{{ $m['name'] }} housing market questions</h2>
            @foreach ($faqs as $f)
                <details @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
            @endforeach
        </div>
    </section>

    @if ($nearby !== [])
        <section class="section">
            <div class="wrap">
                <h2>{{ $m['kind'] === 'area' ? 'More neighborhoods in '.$m['city'] : 'Neighborhoods in '.$m['city'] }}</h2>
                <ul class="place-list">@foreach ($nearby as $n)<li><a href="/market/{{ $n['slug'] }}">{{ $n['name'] }}</a> <span>{{ number_format($n['count']) }} homes</span></li>@endforeach</ul>
            </div>
        </section>
    @endif

    @if ($m['zips'] !== [])
        <section class="section">
            <div class="wrap">
                <h2>ZIP codes in {{ $m['city'] }}</h2>
                <ul class="place-list">@foreach ($m['zips'] as $z)<li><a href="/market/{{ $z }}">{{ $z }}</a></li>@endforeach</ul>
            </div>
        </section>
    @endif
</main>
@endsection

@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:24px">
    <h1>{{ $state !== '' ? 'Homes for sale in '.(collect($site['states'])->firstWhere('code', $state)['name'] ?? $state) : 'Homes for sale' }}</h1>
    <form class="find" action="/homes" method="get" role="search">
        <label class="skip" for="q">City, ZIP code or address</label>
        <input id="q" name="q" value="{{ $query['q'] ?? '' }}" placeholder="City, ZIP code or address">
        <label class="skip" for="state">State</label>
        <select id="state" name="state"><option value="">All states</option>@foreach ($site['states'] as $s)<option value="{{ $s['code'] }}" @selected($state === $s['code'])>{{ $s['name'] }}</option>@endforeach</select>
        <label class="skip" for="max_price">Highest price</label>
        <select id="max_price" name="max_price"><option value="">Any price</option>@foreach ([200000, 300000, 400000, 500000, 750000, 1000000] as $p)<option value="{{ $p }}" @selected((string) ($query['max_price'] ?? '') === (string) $p)>Up to ${{ number_format($p) }}</option>@endforeach</select>
        <label class="skip" for="beds">Bedrooms</label>
        <select id="beds" name="beds"><option value="">Any beds</option>@foreach ([1, 2, 3, 4, 5] as $b)<option value="{{ $b }}" @selected((string) ($query['beds'] ?? '') === (string) $b)>{{ $b }}+ beds</option>@endforeach</select>
        <button class="btn">Search</button>
    </form>
    <p class="muted" aria-live="polite">{{ number_format($total) }} {{ $total === 1 ? 'home' : 'homes' }}</p>
    @if ($results === [])
        <p>No homes match. Try a wider area or a higher price.</p>
    @else
        @include('movewise::_cards', ['homes' => $results])
    @endif
    @if ($pages > 1)
        <nav class="pager" aria-label="Pages">
            @if ($page > 1)<a rel="prev" href="/homes?{{ http_build_query(array_merge($query, ['page' => $page - 1])) }}">Previous</a>@endif
            <span>Page {{ $page }} of {{ $pages }}</span>
            @if ($page < $pages)<a rel="next" href="/homes?{{ http_build_query(array_merge($query, ['page' => $page + 1])) }}">Next</a>@endif
        </nav>
    @endif
</div>
@endsection

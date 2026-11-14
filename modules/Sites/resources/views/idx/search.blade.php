@extends('sites::layout')

@push('head')
    @if ($chrome['mapbox'])
        <link rel="preconnect" href="https://api.mapbox.com" crossorigin>
        <link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/v3.8.0/mapbox-gl.css">
    @endif
@endpush

@section('content')
<main id="main" class="idx {{ $chrome['mapbox'] ? 'idx-has-map' : '' }}" data-search data-mapbox="{{ $chrome['mapbox'] }}" data-bounds="{{ $query['bounds'] ?? '' }}" data-poly="{{ $query['poly'] ?? '' }}">
    <form class="filters" action="/homes" method="get" role="search" data-filters>
        <label class="sr-only" for="f-q">City, ZIP, neighborhood or address</label>
        <input id="f-q" class="f-q" name="q" value="{{ $query['q'] ?? '' }}" placeholder="City, ZIP, neighborhood or address" enterkeyhint="search">
        <label class="sr-only" for="f-min">Minimum price</label>
        <select id="f-min" name="min_price">
            <option value="">Min price</option>
            @foreach ([100000, 200000, 300000, 400000, 500000, 750000, 1000000, 1500000, 2000000, 3000000] as $v)<option value="{{ $v }}" @selected(($query['min_price'] ?? '') == $v)>${{ $v >= 1000000 ? ($v / 1000000).'M' : ($v / 1000).'k' }}</option>@endforeach
        </select>
        <label class="sr-only" for="f-max">Maximum price</label>
        <select id="f-max" name="max_price">
            <option value="">Max price</option>
            @foreach ([200000, 300000, 400000, 500000, 750000, 1000000, 1500000, 2000000, 3000000, 5000000, 10000000] as $v)<option value="{{ $v }}" @selected(($query['max_price'] ?? '') == $v)>${{ $v >= 1000000 ? ($v / 1000000).'M' : ($v / 1000).'k' }}</option>@endforeach
        </select>
        <label class="sr-only" for="f-beds">Bedrooms</label>
        <select id="f-beds" name="beds">
            <option value="">Beds</option>
            @foreach ([1, 2, 3, 4, 5] as $v)<option value="{{ $v }}" @selected(($query['beds'] ?? '') == $v)>{{ $v }}+ bd</option>@endforeach
        </select>
        <label class="sr-only" for="f-baths">Bathrooms</label>
        <select id="f-baths" name="baths">
            <option value="">Baths</option>
            @foreach ([1, 2, 3, 4] as $v)<option value="{{ $v }}" @selected(($query['baths'] ?? '') == $v)>{{ $v }}+ ba</option>@endforeach
        </select>
        <label class="sr-only" for="f-sort">Sort</label>
        <select id="f-sort" name="sort">
            @foreach (['newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'sqft_desc' => 'Largest', 'reduced' => 'Recently reduced'] as $k => $label)
                <option value="{{ $k }}" @selected(($query['sort'] ?? 'newest') === $k)>{{ $label }}</option>
            @endforeach
        </select>
        @if (! empty($query['bounds']))<input type="hidden" name="bounds" value="{{ $query['bounds'] }}">@endif
        @if (! empty($query['poly']))<input type="hidden" name="poly" value="{{ $query['poly'] }}">@endif
        <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <div class="idx-split">
        <section class="idx-list" aria-labelledby="idx-heading">
            <div class="idx-head">
                <h1 id="idx-heading" data-heading>{{ $heading }}</h1>
                @if ($chrome['mapbox'])<button type="button" class="btn btn-ghost map-toggle" data-map-toggle aria-pressed="false">Map</button>@endif
            </div>
            @if ($found['total'] === 0)
                <p class="lead-muted" data-empty>No homes match yet. Try a wider area or fewer filters, or <a href="/contact">ask an agent</a> to look for you.</p>
            @endif
            <ul class="cards cards-tight" data-cards aria-live="polite">@include('sites::idx.cards', ['listings' => $found['results']])</ul>
            <div data-pagination>@include('sites::idx.pagination', ['pages' => $pages])</div>
        </section>
        @if ($chrome['mapbox'])
            <section class="idx-map" aria-label="Map of homes">
                <div class="map" data-map></div>
                <div class="map-tools">
                    <label class="map-chip"><input type="checkbox" data-follow checked> Search as I move the map</label>
                    <button type="button" class="map-chip" data-draw>Draw an area</button>
                    <button type="button" class="map-chip" data-clear-area @if (empty($query['poly']) && empty($query['bounds'])) hidden @endif>Clear area</button>
                </div>
            </section>
        @endif
    </div>
</main>
@endsection

@push('scripts')
    @if ($chrome['mapbox'])
        <script src="https://api.mapbox.com/mapbox-gl-js/v3.8.0/mapbox-gl.js" defer></script>
    @endif
    <script src="/site-assets/search.js?v=1" defer></script>
@endpush

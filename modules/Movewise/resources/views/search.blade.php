@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:24px">
    <h1>{{ $state !== '' ? $t('home.state_link', ['state' => collect($site['states'])->firstWhere('code', $state)['name'] ?? $state]) : $t('nav.homes') }}</h1>
    <form class="find" action="{{ $u('/homes') }}" method="get" role="search">
        <label class="skip" for="q">{{ $t('search.placeholder') }}</label>
        <input id="q" name="q" value="{{ $query['q'] ?? '' }}" placeholder="{{ $t('search.placeholder') }}">
        <label class="skip" for="state">{{ $t('search.state') }}</label>
        <select id="state" name="state"><option value="">{{ $t('search.all_states') }}</option>@foreach ($site['states'] as $s)<option value="{{ $s['code'] }}" @selected($state === $s['code'])>{{ $s['name'] }}</option>@endforeach</select>
        <label class="skip" for="max_price">{{ $t('search.any_price') }}</label>
        <select id="max_price" name="max_price"><option value="">{{ $t('search.any_price') }}</option>@foreach ([200000, 300000, 400000, 500000, 750000, 1000000] as $p)<option value="{{ $p }}" @selected((string) ($query['max_price'] ?? '') === (string) $p)>{{ $t('search.up_to', ['price' => '$'.number_format($p)]) }}</option>@endforeach</select>
        <label class="skip" for="beds">{{ $t('search.any_beds') }}</label>
        <select id="beds" name="beds"><option value="">{{ $t('search.any_beds') }}</option>@foreach ([1, 2, 3, 4, 5] as $b)<option value="{{ $b }}" @selected((string) ($query['beds'] ?? '') === (string) $b)>{{ $t('search.beds', ['n' => $b]) }}</option>@endforeach</select>
        <button class="btn">{{ $t('search.short') }}</button>
    </form>
    <p class="muted" aria-live="polite">{{ $t($total === 1 ? 'search.count_one' : 'search.count', ['n' => number_format($total)]) }}
        <button class="save" type="button" data-save-search="{{ json_encode(array_intersect_key($query, array_flip(['q', 'min_price', 'max_price', 'beds', 'baths', 'state']))) }}" hidden>{{ $t('save_search') }}</button></p>
    @if ($results === [])
        <p>{{ $t('search.none') }}</p>
    @else
        @include('movewise::_cards', ['homes' => $results])
    @endif
    @if ($pages > 1)
        <nav class="pager" aria-label="{{ $t('search.page', ['page' => $page, 'pages' => $pages]) }}">
            @if ($page > 1)<a rel="prev" href="{{ $u('/homes') }}?{{ http_build_query(array_merge($query, ['page' => $page - 1])) }}">{{ $t('search.prev') }}</a>@endif
            <span>{{ $t('search.page', ['page' => $page, 'pages' => $pages]) }}</span>
            @if ($page < $pages)<a rel="next" href="{{ $u('/homes') }}?{{ http_build_query(array_merge($query, ['page' => $page + 1])) }}">{{ $t('search.next') }}</a>@endif
        </nav>
    @endif
</div>
@endsection

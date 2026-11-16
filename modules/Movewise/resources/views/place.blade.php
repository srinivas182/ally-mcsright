@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:24px">
    <nav class="small muted" aria-label="Breadcrumb"><a href="{{ $u('/'.$state['slug']) }}">{{ $state['name'] }}</a>@isset($where['hood']) › <a href="{{ $u('/'.$state['slug'].'/'.\Illuminate\Support\Str::slug($where['city'])) }}">{{ $where['city'] }}</a>@endisset</nav>
    <h1>{{ $title }}</h1>
    <h2>{{ $t('place.facts') }}</h2>
    <ul class="promises">
        <li><strong>{{ number_format($facts['count']) }}</strong>{{ $t('place.homes') }}</li>
        <li><strong>{{ $facts['median_price'] !== null ? '$'.number_format($facts['median_price']) : '—' }}</strong>{{ $t('place.median_price') }}</li>
        <li><strong>{{ $facts['median_ppsf'] !== null ? '$'.$facts['median_ppsf'] : '—' }}</strong>{{ $t('place.ppsf') }}</li>
        <li><strong>{{ $facts['median_days'] ?? '—' }}</strong>{{ $t('place.days') }}</li>
        <li><strong>{{ number_format($facts['new_week']) }}</strong>{{ $t('place.new_week') }}</li>
    </ul>
    @if ($homes !== [])
        <h2>{{ $t('place.homes') }}</h2>
        @include('movewise::_cards', ['homes' => $homes])
        <p><a href="{{ $u('/homes') }}?{{ http_build_query(['state' => $state['code'], 'q' => $where['zip'] ?? $where['hood'] ?? $where['city'] ?? '']) }}">{{ $t('place.see_all') }}</a></p>
    @endif
    <h2>{{ $t('place.report') }}</h2>
    <table class="cost">
        <thead><tr><th>{{ $t('place.month') }}</th><th>{{ $t('place.new') }}</th><th>{{ $t('place.sold') }}</th><th>{{ $t('place.median_sold') }}</th></tr></thead>
        <tbody>@foreach ($facts['months'] as $m)<tr><td>{{ $m['month'] }}</td><td>{{ $m['new'] }}</td><td>{{ $m['sold'] }}</td><td>{{ $m['median_sold'] !== null ? '$'.number_format($m['median_sold']) : '—' }}</td></tr>@endforeach</tbody>
    </table>
    <p class="small muted">{{ $t('place.as_of', ['date' => $facts['as_of']]) }}</p>
    @if ($facts['hoods'] !== [])
        <h2>{{ $t('place.hoods') }}</h2>
        <p>@foreach ($facts['hoods'] as $h)<a href="{{ $u($base_path.'/'.$h['slug']) }}">{{ $h['name'] }} ({{ $h['count'] }})</a>@if (! $loop->last) · @endif @endforeach</p>
    @endif
    @if ($facts['zips'] !== [] && ! isset($where['hood']))
        <h2>{{ $t('place.zips') }}</h2>
        <p>@foreach ($facts['zips'] as $z)<a href="{{ $u('/'.$state['slug'].'/'.$z['zip']) }}">{{ $z['zip'] }} ({{ $z['count'] }})</a>@if (! $loop->last) · @endif @endforeach</p>
    @endif
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection

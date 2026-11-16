@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:24px">
    <nav class="small muted" aria-label="Breadcrumb"><a href="{{ $u('/homes') }}">{{ $t('nav.homes') }}</a> › {{ $home['city'] ?? '' }}</nav>
    <h1>{{ $label ?: 'Home for sale' }}</h1>
    <p class="price">{{ isset($home['price']) ? '$'.number_format((int) $home['price']) : 'Price on request' }}
        <button class="save" type="button" data-save="{{ $home['ulid'] }}" aria-pressed="false" hidden>{{ $t('save') }}</button></p>
    <p class="facts">
        @isset($home['beds'])<span>{{ $home['beds'] }} bedrooms</span>@endisset
        @isset($home['baths_full'])<span>{{ $home['baths_full'] + 0.5 * (int) ($home['baths_half'] ?? 0) }} bathrooms</span>@endisset
        @isset($home['sqft'])<span>{{ number_format((int) $home['sqft']) }} sq ft</span>@endisset
        @isset($home['year_built'])<span>Built {{ $home['year_built'] }}</span>@endisset
        <span>{{ $home['status'] ?? '' }}</span>
    </p>
    @if ($home['photos'] !== [])
        <div class="gallery">
            <img src="{{ $home['photos'][0]['hero'] }}" alt="{{ $home['photos'][0]['caption'] ?? $label }}" width="900" height="675">
            <div class="side">@foreach (array_slice($home['photos'], 1, 2) as $p)<img src="{{ $p['url'] }}" alt="{{ $p['caption'] ?? $label }}" loading="lazy" width="440" height="330">@endforeach</div>
        </div>
    @endif
    <p class="small muted">@if ($updated)Last updated {{ $updated->diffForHumans() }} from the MLS.@endif {{ $home['stale'] ? 'The MLS feed is catching up; check with the listing agent before visiting.' : '' }}</p>

    @if ($cost)
        <h2>{{ $t('cost.title') }}</h2>
        <table class="cost">
            <tbody>
            @foreach ($cost['lines'] as $line)
                <tr><td><strong>{{ $line['label'] }}</strong><br><span class="small muted">{{ $line['note'] }}</span></td>
                    <td class="amt">{{ $line['amount'] !== null ? '$'.number_format($line['amount']) : 'Ask' }}</td></tr>
            @endforeach
            </tbody>
            <tfoot><tr><td>{{ $t('cost.total') }}</td><td class="amt">${{ number_format($cost['total']) }}</td></tr></tfoot>
        </table>
        <p class="small muted">{{ $t('listing.estimate') }}</p>
    @endif

    @if (! empty($home['remarks']))
        <h2>About this home</h2>
        <p class="prose">{{ $home['remarks'] }}</p>
    @endif

    <h2>Who's selling it</h2>
    <p>{{ $home['attribution'] ?? '' }}</p>
    @if (! empty($home['disclaimer']))<p class="attr">{{ $home['disclaimer'] }}</p>@endif
    @if (! empty($home['logo']))<img src="{{ $home['logo'] }}" alt="MLS" height="32" loading="lazy">@endif

    <p class="note">{{ $t('listing.ask') }} <button class="save" type="button" data-ailly-open data-listing="{{ $home['ulid'] }}" hidden>{{ $t('ailly.open') }}</button></p>
</div>
@endsection

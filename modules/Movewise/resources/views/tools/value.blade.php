@extends('movewise::tools._layout')
@section('tool')
<form class="tool" method="post" action="{{ $u('/home-value') }}">
    <label>{{ $t('value.street') }} <input name="street" value="{{ $in['street'] }}" autocomplete="street-address" required maxlength="120"></label>
    <label>{{ $t('connect.home_city') }} <input name="city" value="{{ $in['city'] }}" autocomplete="address-level2" maxlength="60"></label>
    <label>{{ $t('connect.home_state') }}
        <select name="state">@foreach ($site['states'] as $s)<option value="{{ $s['code'] }}" @selected($in['state'] === $s['code'])>{{ $s['name'] }}</option>@endforeach</select>
    </label>
    <label>{{ $t('value.zip') }} <input name="zip" value="{{ $in['zip'] }}" inputmode="numeric" maxlength="5" autocomplete="postal-code" required></label>
    <button class="btn">{{ $t('value.get') }}</button>
    @if ($person === null)<p class="small muted">{{ $t('value.account') }} <a href="{{ $u('/account') }}">{{ $t('nav.signin') }}</a></p>@endif
</form>
@if ($error)
    <p class="note" role="alert">{{ $error }} @if ($person === null)<a href="{{ $u('/account') }}">{{ $t('nav.signin') }}</a>@endif</p>
@endif
@if ($result)
    <section aria-live="polite">
        <h2>{{ $t('value.range') }}: ${{ number_format((int) ($result['low'] ?? $result['mid'])) }} – ${{ number_format((int) ($result['high'] ?? $result['mid'])) }}</h2>
        <p class="price">{{ $t('value.mid') }}: ${{ number_format($result['mid']) }}</p>
        <p class="small muted">{{ $result['address'] }}
            @if ($result['beds']) · {{ $t('listing.beds', ['n' => $result['beds']]) }}@endif
            @if ($result['sqft']) · {{ $t('listing.sqft', ['n' => number_format($result['sqft'])]) }}@endif
            @if ($result['year_built']) · {{ $t('listing.built', ['year' => $result['year_built']]) }}@endif
            @if ($result['last_sale']['price']) · {{ $t('value.last_sale', ['price' => number_format((int) $result['last_sale']['price']), 'date' => (string) $result['last_sale']['date']]) }}@endif</p>
        <p class="small muted">{{ $t('value.source', ['date' => $result['as_of']]) }}</p>
        <p><a class="btn" rel="nofollow" href="{{ $u('/connect') }}?{{ http_build_query(['type' => 'sell', 'zip' => $in['zip'], 'city' => $in['city'], 'state' => $in['state'], 'price' => $result['mid']]) }}">{{ $t('connect.sell_cta') }}</a>
            <a href="{{ $u('/net-sheet') }}?{{ http_build_query(['price' => $result['mid'], 'state' => $in['state']]) }}">{{ $t('tools.net') }}</a></p>
    </section>
@endif
@endsection

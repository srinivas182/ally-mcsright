@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:32px;max-width:720px">
    <h1>{{ $t('connect.title') }}</h1>
    <p class="lead">{{ $t('connect.lead') }}</p>
    @include('movewise::connect._errors')
    <form class="tool" method="post" action="{{ $u('/connect') }}">
        <input type="hidden" name="listing" value="{{ $listing }}">
        <label>{{ $t('connect.first_name') }} <input name="first_name" value="{{ $person->first_name }}" required maxlength="80" autocomplete="given-name"></label>
        <fieldset style="border:0;padding:0;display:flex;gap:16px">
            <label style="font-weight:400"><input type="radio" name="lead_type" value="buyer" @checked($about['lead_type'] === 'buyer')> {{ $t('connect.buying') }}</label>
            <label style="font-weight:400"><input type="radio" name="lead_type" value="seller" @checked($about['lead_type'] === 'seller')> {{ $t('connect.selling') }}</label>
        </fieldset>
        <label>{{ $t('connect.timeline') }}
            <select name="timeline">@foreach (\Modules\Movewise\Matching\Readiness::TIMELINES as $k => $label)<option value="{{ $k }}" @selected(($about['timeline'] ?? '') === $k)>{{ $label }}</option>@endforeach</select>
        </label>
        <label>{{ $t('connect.preapproved') }}
            <select name="preapproved">@foreach (['yes', 'no', 'unsure'] as $k)<option value="{{ $k }}" @selected(($about['preapproved'] ?? 'unsure') === $k)>{{ $t('connect.'.$k) }}</option>@endforeach</select>
        </label>
        <label style="font-weight:400"><input type="checkbox" name="selling_first" value="1" @checked($about['selling_first'] ?? false)> {{ $t('connect.selling_first') }}</label>
        <fieldset style="border:1px solid var(--line);border-radius:12px;padding:12px;display:grid;gap:10px">
            <legend>{{ $t('connect.selling') }}</legend>
            <label>{{ $t('connect.home_zip') }} <input name="home_zip" inputmode="numeric" maxlength="5" value="{{ $about['home_zip'] ?? '' }}"></label>
            <label>{{ $t('connect.home_city') }} <input name="home_city" maxlength="60" value="{{ $about['home_city'] ?? '' }}"></label>
            <label>{{ $t('connect.home_state') }} <select name="home_state">@foreach ($states as $code => $name)<option value="{{ $code }}" @selected(($about['home_state'] ?? '') === $code)>{{ $name }}</option>@endforeach</select></label>
            <label>{{ $t('connect.home_price') }} <input name="home_price" inputmode="numeric" value="{{ $about['home_price'] ?? '' }}"></label>
        </fieldset>
        <label>{{ $t('connect.language') }}
            <select name="language"><option value="en" @selected(($about['language'] ?? \Modules\Movewise\I18n::locale()) === 'en')>English</option><option value="es" @selected(($about['language'] ?? \Modules\Movewise\I18n::locale()) === 'es')>Español</option></select>
        </label>
        <label style="font-weight:400"><input type="checkbox" name="has_agent" value="1" @checked($person->has_agent)> {{ $t('connect.has_agent') }}</label>
        <button class="btn">{{ $t('connect.next') }}</button>
    </form>
</div>
@endsection

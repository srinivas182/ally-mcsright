@extends('movewise::layout')
@section('main')
<section class="hero"><div class="wrap">
    <h1>{{ $t('home.h1') }}</h1>
    <p class="lead">{{ $t('home.lead', ['states' => collect($site['states'])->pluck('name')->join(', ', ' & ')]) }}</p>
    <form class="find" action="{{ $u('/homes') }}" method="get" role="search">
        <label class="skip" for="q">{{ $t('search.placeholder') }}</label>
        <input id="q" name="q" placeholder="{{ $t('search.placeholder') }}" autocomplete="off">
        <label class="skip" for="state">{{ $t('search.state') }}</label>
        <select id="state" name="state"><option value="">{{ $t('search.all_states') }}</option>@foreach ($site['states'] as $s)<option value="{{ $s['code'] }}">{{ $s['name'] }}</option>@endforeach</select>
        <button class="btn">{{ $t('search.button') }}</button>
    </form>
</div></section>
<div class="wrap">
    <ul class="promises">
        <li><strong>{{ $t('promise.spam') }}</strong>{{ $t('promise.spam_body') }}</li>
        <li><strong>{{ $t('promise.choose') }}</strong>{{ $t('promise.choose_body') }}</li>
        <li><strong>{{ $t('promise.honest') }}</strong>{{ $t('promise.honest_body') }}</li>
        <li><strong>{{ $t('promise.money') }}</strong>{{ $t('promise.money_body') }} <a href="{{ $u('/how-movewise-is-paid') }}">{{ $t('how_it_works') }}</a>.</li>
    </ul>
    @if ($newest !== [])
        <h2>{{ $t('home.new') }}</h2>
        @include('movewise::_cards', ['homes' => $newest])
    @endif
    <h2>{{ $t('home.by_state') }}</h2>
    <p>@foreach ($site['states'] as $s)<a href="{{ $u('/'.$s['slug']) }}">{{ $t('home.state_link', ['state' => $s['name']]) }}</a>@if (! $loop->last) · @endif @endforeach</p>
    <p><a class="btn ghost" href="{{ $u('/connect') }}?type=sell" rel="nofollow">{{ $t('connect.sell_cta') }}</a></p>
    <h2>{{ $t('tools.title') }}</h2>
    <p><a href="{{ $u('/afford') }}">{{ $t('tools.afford') }}</a> · <a href="{{ $u('/net-sheet') }}">{{ $t('tools.net') }}</a> · <a href="{{ $u('/move-planner') }}">{{ $t('tools.planner') }}</a> · <a href="{{ $u('/home-value') }}">{{ $t('value.title') }}</a></p>
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection

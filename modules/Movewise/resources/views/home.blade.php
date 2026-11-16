@extends('movewise::layout')
@section('main')
<section class="hero"><div class="wrap">
    <h1>Find your next home. Nobody calls you unless you ask.</h1>
    <p class="lead">Every home for sale in {{ collect($site['states'])->pluck('name')->join(', ', ' and ') }}, fresh from the MLS, with the true monthly cost and the risks in plain English.</p>
    <form class="find" action="/homes" method="get" role="search">
        <label class="skip" for="q">City, ZIP code or address</label>
        <input id="q" name="q" placeholder="City, ZIP code or address" autocomplete="off">
        <label class="skip" for="state">State</label>
        <select id="state" name="state"><option value="">All states</option>@foreach ($site['states'] as $s)<option value="{{ $s['code'] }}">{{ $s['name'] }}</option>@endforeach</select>
        <button class="btn">Search homes</button>
    </form>
</div></section>
<div class="wrap">
    <ul class="promises">
        <li><strong>No spam, ever.</strong>Browse, save and ask anything. No phone number needed.</li>
        <li><strong>You choose your agent.</strong>When you're ready, pick one of three local experts. Only they contact you.</li>
        <li><strong>Honest information.</strong>The true monthly cost of every home, and when it was last updated.</li>
        <li><strong>Open about money.</strong>Agents pay Movewise for introductions. <a href="/how-movewise-is-paid">How it works</a>.</li>
    </ul>
    @if ($newest !== [])
        <h2>New on the market</h2>
        @include('movewise::_cards', ['homes' => $newest])
    @endif
    <h2>Homes by state</h2>
    <p>@foreach ($site['states'] as $s)<a href="/{{ $s['slug'] }}">Homes for sale in {{ $s['name'] }}</a>@if (! $loop->last) · @endif @endforeach</p>
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection

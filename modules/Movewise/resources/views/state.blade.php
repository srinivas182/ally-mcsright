@extends('movewise::layout')
@section('main')
<section class="hero"><div class="wrap">
    <h1>{{ $t('home.state_link', ['state' => $state['name']]) }}</h1>
    <p class="lead">{{ $t('state.lead', ['n' => number_format($total), 'state' => $state['name']]) }}</p>
    <p><a class="btn" href="{{ $u('/homes') }}?state={{ $state['code'] }}">{{ $t('state.see_all') }}</a></p>
</div></section>
<div class="wrap">
    @if ($cities !== [])
        <h2>{{ $t('state.by_city') }}</h2>
        <p>@foreach ($cities as $c)<a href="{{ $u('/'.$state['slug'].'/'.\Illuminate\Support\Str::slug($c['city'])) }}">{{ $c['city'] }} ({{ $c['count'] }})</a>@if (! $loop->last) · @endif @endforeach</p>
    @endif
    @if ($results !== [])
        <h2>{{ $t('state.newest', ['state' => $state['name']]) }}</h2>
        @include('movewise::_cards', ['homes' => $results])
    @endif
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection

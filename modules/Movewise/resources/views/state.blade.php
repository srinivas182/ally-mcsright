@extends('movewise::layout')
@section('main')
<section class="hero"><div class="wrap">
    <h1>Homes for sale in {{ $state['name'] }}</h1>
    <p class="lead">{{ number_format($total) }} homes for sale in {{ $state['name'] }} right now, fresh from the MLS, each with the true monthly cost.</p>
    <p><a class="btn" href="/homes?state={{ $state['code'] }}">See them all</a></p>
</div></section>
<div class="wrap">
    @if ($cities !== [])
        <h2>By city</h2>
        <p>@foreach ($cities as $c)<a href="{{ $u('/'.$state['slug'].'/'.\Illuminate\Support\Str::slug($c['city'])) }}">{{ $c['city'] }} ({{ $c['count'] }})</a>@if (! $loop->last) · @endif @endforeach</p>
    @endif
    @if ($results !== [])
        <h2>Newest in {{ $state['name'] }}</h2>
        @include('movewise::_cards', ['homes' => $results])
    @endif
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection

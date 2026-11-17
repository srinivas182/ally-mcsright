@extends('sites::layout')

@section('content')
<main id="main">
    <section class="section">
        <div class="wrap">
            <h1 class="section-title">Home buying calculators</h1>
            <p class="answer">Free, simple calculators to plan your move. Change any number; every answer explains how it was worked out.</p>
            <ul class="cards calc-cards">
                @foreach ($kinds as $key => $k)
                    <li class="card calc-card">
                        <h2><a href="/calculators/{{ $key }}">{{ $k['title'] }}</a></h2>
                        <p>{{ $k['description'] }}</p>
                        <p><a class="btn" href="/calculators/{{ $key }}">Open the {{ strtolower($k['short']) }} calculator</a></p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
</main>
@endsection

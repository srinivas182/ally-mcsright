@extends('movewise::pages._page')
@section('body')
<p class="lead">In each state Movewise serves, a licensed brokerage is the broker of record for Movewise and supervises its real estate activity there.</p>
@forelse ($site['states'] as $s)
    <h2>{{ $s['name'] }}</h2>
    @if ($s['partner'])
        <p><strong>{{ $s['partner']['name'] }}</strong><br>Brokerage licence {{ $s['partner']['licence'] }}<br>Managing broker: {{ $s['partner']['broker'] }}
        @if ($s['partner']['phone'])<br>Phone: {{ $s['partner']['phone'] }}@endif
        @if ($s['partner']['email'])<br>Email: <a href="mailto:{{ $s['partner']['email'] }}">{{ $s['partner']['email'] }}</a>@endif</p>
    @endif
    @if ($s['code'] === 'TX')
        <p><a href="{{ $s['partner']['iabs'] ?? 'https://www.trec.texas.gov/forms/information-about-brokerage-services' }}">Texas Real Estate Commission Information About Brokerage Services</a><br>
        <a href="https://www.trec.texas.gov/forms/consumer-protection-notice">Texas Real Estate Commission Consumer Protection Notice</a></p>
    @endif
@empty
    <p>Movewise isn't open in any state yet.</p>
@endforelse
<h2>Agents</h2>
<p>Every agent shown on Movewise is licensed in the home's state, through their own brokerage. Their licence number is on their profile, linked to the state's licence lookup.</p>
@endsection

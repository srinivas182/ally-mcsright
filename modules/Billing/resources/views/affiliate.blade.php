@extends('billing::health.layout', ['title' => 'Your RightAlly referral link', 'description' => 'Your referral link, visits, brokerages that joined and your rewards.', 'section' => 'Referrals', 'home' => '/start', 'signup' => false])

@push('head')<meta name="robots" content="noindex, nofollow">@endpush

@section('main')
@php($usd = fn (int $c): string => '$'.number_format($c / 100, 2))
<section class="hero"><div class="wrap">
    <h1>Hello, {{ $affiliate->name }}</h1>
    <p class="lead">Share your link. When a brokerage joins RightAlly through it, you earn {{ $share }}% of what it pays in its first year. Rewards are confirmed {{ $hold }} days after its first payment, then paid by our team.</p>
    <p><label>Your link <input readonly value="{{ $link }}" onclick="this.select()" style="width:100%;max-width:32rem"></label></p>
    <ul class="lead">
        <li>Visits in the last 30 days: <strong>{{ number_format($stats['clicks_30d']) }}</strong></li>
        <li>Brokerages that joined: <strong>{{ $stats['joined'] }}</strong></li>
        <li>Waiting {{ $hold }} days: <strong>{{ $usd($stats['pending_cents']) }}</strong> · Confirmed: <strong>{{ $usd($stats['approved_cents']) }}</strong> · Paid: <strong>{{ $usd($stats['paid_cents']) }}</strong></li>
    </ul>
    <p>Keep this page's address private: it is your own.</p>
</div></section>
@endsection

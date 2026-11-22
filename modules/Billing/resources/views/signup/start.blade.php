@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $host = config('core.hq_host');
    $faq = [
        ['How much does RightAlly cost?', $packages->isEmpty() ? 'Prices are being updated.' : 'Brokerage packages start at '.$usd((int) $packages->first()->monthly_cents).' a month, or less when paid yearly. Prices are on this page; there are no setup fees.'],
        ['How long until we are live?', 'Your RightAlly is created as soon as the first payment goes through. The owner gets a sign-in email straight away, and a 30-day go-live plan with a named coach starts the same day.'],
        ['Can we bring our contacts and deals?', 'Yes. Contacts, notes and tags come over from CRMs such as Follow Up Boss, Lofty, BoldTrail and Sierra, or from a spreadsheet. Deals and documents come over from dotloop, SkySlope, Lone Wolf and others. Opt-outs come across; texting consent does not, so nobody is texted until they opt in.'],
        ['Is there a contract?', 'No long contract. Pay monthly or yearly and cancel from your billing page; you keep access until the end of the period you paid for.'],
    ];
    $jsonld = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'Product', 'name' => 'RightAlly for brokerages', 'brand' => ['@type' => 'Brand', 'name' => 'RightAlly'],
            'description' => 'One platform for real estate brokerages: CRM, IDX websites, deals and compliance, e-signature, commissions and agent payouts.',
            'offers' => $packages->map(fn ($p) => ['@type' => 'Offer', 'name' => $p->name, 'price' => number_format($p->monthly_cents / 100, 2, '.', ''), 'priceCurrency' => 'USD',
                'url' => $canonical, 'availability' => 'https://schema.org/InStock'])->values()->all()],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
    ]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
@extends('billing::health.layout', ['title' => 'RightAlly prices and signup for brokerages', 'indexable' => true, 'canonical' => $canonical, 'jsonld' => $jsonld,
    'section' => 'Prices and signup', 'home' => '/start', 'signup' => true,
    'description' => 'Public prices for RightAlly, the all-in-one platform for real estate brokerages. Sign up online, go live the same day, with a 30-day go-live plan and a named coach.'])

@section('main')
<section class="hero"><div class="wrap">
    <h1>Prices and signup</h1>
    <p class="lead">One platform for your brokerage: CRM, websites, deals and compliance, e-signature, commissions and agent payouts. Public prices, no setup fee, live the same day.</p>
    @if ($cancelled)<p class="card" role="status">Payment was cancelled. Nothing was charged; you can try again below.</p>@endif
    @if ($resumed ?? false)<p class="card" role="status">Welcome back. Everything you filled in is saved: carry on where you left off.</p>@endif
</div></section>

<div class="wrap">
    @if ($packages->isEmpty())
        <p class="muted">Prices are being updated. <a href="/health-check">Run the free Health Check</a> and we will email you.</p>
    @elseif (! $open)
        <h2>Packages</h2>
        <ul class="promises">
            @foreach ($packages as $p)
                <li>
                    <h3>{{ $p->name }}</h3>
                    <p><strong style="font-size:28px">{{ $usd((int) $p->monthly_cents) }}</strong> a month<br><span class="muted small">or {{ $usd((int) $p->yearly_cents) }} a year</span></p>
                    <ul class="small">@foreach ((array) $p->features as $f)@isset($features[$f])<li>{{ $features[$f] }}</li>@endisset @endforeach</ul>
                </li>
            @endforeach
        </ul>
        <p class="card">Online signup opens soon. <a href="/health-check">Run the free Health Check</a> and tick "I'd like someone to call" — we'll set you up.</p>
    @else
        @include('billing::signup.wizard')
    @endif

    <section class="faq" aria-labelledby="faq">
        <h2 id="faq">Questions</h2>
        @foreach ($faq as [$q, $a])<details><summary>{{ $q }}</summary><p>{{ $a }}</p></details>@endforeach
    </section>
</div>
@endsection

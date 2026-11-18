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
    $v = fn ($k) => old($k, $prefill[$k] ?? '');
@endphp
@extends('billing::health.layout', ['title' => 'RightAlly prices and signup for brokerages', 'indexable' => true, 'canonical' => $canonical, 'jsonld' => $jsonld,
    'section' => 'Prices and signup', 'home' => '/start', 'signup' => true,
    'description' => 'Public prices for RightAlly, the all-in-one platform for real estate brokerages. Sign up online, go live the same day, with a 30-day go-live plan and a named coach.'])

@section('main')
<section class="hero"><div class="wrap">
    <h1>Prices and signup</h1>
    <p class="lead">One platform for your brokerage: CRM, websites, deals and compliance, e-signature, commissions and agent payouts. Public prices, no setup fee, live the same day.</p>
    @if ($cancelled)<p class="card" role="status">Payment was cancelled. Nothing was charged; you can try again below.</p>@endif
</div></section>

<div class="wrap">
    <h2>Packages</h2>
    @if ($packages->isEmpty())
        <p class="muted">Prices are being updated. <a href="/health-check">Run the free Health Check</a> and we will email you.</p>
    @else
        <ul class="promises">
            @foreach ($packages as $p)
                <li>
                    <h3>{{ $p->name }}</h3>
                    <p><strong style="font-size:28px">{{ $usd((int) $p->monthly_cents) }}</strong> a month<br><span class="muted small">or {{ $usd((int) $p->yearly_cents) }} a year</span></p>
                    <ul class="small">@foreach ((array) $p->features as $f)@isset($features[$f])<li>{{ $features[$f] }}</li>@endisset @endforeach</ul>
                </li>
            @endforeach
        </ul>
    @endif

    <h2 id="signup">Sign up</h2>
    @if (! $open)
        <p class="card">Online signup opens soon. <a href="/health-check">Run the free Health Check</a> and tick "I'd like someone to call" — we'll set you up.</p>
    @else
        @if ($errors->any())
            <div class="errors" role="alert"><strong>Please check these:</strong><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        <form method="post" action="/start" novalidate>
            @csrf
            <div class="hp" aria-hidden="true"><label>Fax<input type="text" name="fax" tabindex="-1" autocomplete="off"></label></div>
            @if (! empty($prefill['check']))<input type="hidden" name="check" value="{{ $prefill['check'] }}">@endif
            <fieldset><legend>1. Your brokerage</legend>
                <div class="two">
                    <label>Brokerage name<input type="text" name="brokerage" required maxlength="150" value="{{ $v('brokerage') }}" autocomplete="organization"></label>
                    <label>Number of agents (optional)<input type="number" name="agents" min="1" max="20000" value="{{ $v('agents') }}" inputmode="numeric"></label>
                    <label>Your RightAlly address
                        <span style="display:flex;align-items:center;gap:6px"><input type="text" name="slug" required maxlength="40" value="{{ $v('slug') }}" pattern="[a-z0-9-]+" autocapitalize="none" aria-describedby="slug-help"> <span class="muted">.{{ config('core.platform_domain') }}</span></span>
                        <span id="slug-help" class="muted small" style="font-weight:400">Letters, numbers and dashes. You can add your own domain later.</span>
                    </label>
                    <label>State (two letters, optional)<input type="text" name="state" maxlength="2" value="{{ $v('state') }}"></label>
                </div>
            </fieldset>
            <fieldset><legend>2. The owner</legend>
                <div class="two">
                    <label>Owner's name<input type="text" name="owner_name" required maxlength="120" value="{{ $v('owner_name') }}" autocomplete="name"></label>
                    <label>Owner's email (the sign-in link goes here)<input type="email" name="owner_email" required maxlength="190" value="{{ $v('owner_email') }}" autocomplete="email"></label>
                </div>
            </fieldset>
            <fieldset><legend>3. Package</legend>
                <div class="choices" role="radiogroup" aria-label="Package">
                    @foreach ($packages as $i => $p)
                        <label><input type="radio" name="package" value="{{ $p->ulid }}" @checked(old('package', $chosen ?? $packages->first()->ulid) === $p->ulid)> {{ $p->name }} — {{ $usd((int) $p->monthly_cents) }} a month or {{ $usd((int) $p->yearly_cents) }} a year</label>
                    @endforeach
                </div>
                <div class="choices" role="radiogroup" aria-label="How often to pay">
                    <label><input type="radio" name="interval" value="month" @checked(old('interval', 'month') === 'month')> Pay monthly</label>
                    <label><input type="radio" name="interval" value="year" @checked(old('interval') === 'year')> Pay yearly</label>
                </div>
                <label class="check"><input type="checkbox" name="terms" value="1" @checked(old('terms'))> I can sign up this brokerage, and I agree to pay the price shown each month or year until I cancel.</label>
                @include('core::bot-check', ['bot' => app(\Modules\Core\Security\BotProtection::class)->widget()])
                <button class="btn" type="submit">Continue to payment</button>
                <p class="muted small">You pay on our payment provider's secure page. Your RightAlly is created when the payment goes through.</p>
            </fieldset>
        </form>
    @endif

    <section class="faq" aria-labelledby="faq">
        <h2 id="faq">Questions</h2>
        @foreach ($faq as [$q, $a])<details><summary>{{ $q }}</summary><p>{{ $a }}</p></details>@endforeach
    </section>
</div>
@endsection

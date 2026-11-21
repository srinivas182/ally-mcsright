@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $faq = [
        ['How much is RightAlly for one agent?', $packages->isEmpty() ? 'Prices are being updated.' : 'Plans for one agent start at '.$usd((int) $packages->first()->monthly_cents).' a month. Prices are on this page; there is no setup fee and no long contract.'],
        ['How long does it take to start?', 'About ten minutes: sign up, open the email, bring your old CRM or a spreadsheet over, and "Who to call today" is ready the same day.'],
        ['Is it easy if I don\'t like new software?', 'Yes. New agents start in simple view: a short menu, big buttons and bigger text, in English or Spanish. You can switch to the full view any time.'],
        ['Who owns my contacts?', 'You do. Your own contacts are yours: download them any time with one click. If you join a brokerage and later leave, RightAlly emails you a private link to take your own people with you.'],
        ['What do I get?', 'Each plan above lists what it includes. Every plan starts in simple view, has the goal planner that turns "I want 24 closings" into calls a day, and comes with the portability guarantee.'],
    ];
    $jsonld = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'Product', 'name' => 'RightAlly for Agents', 'brand' => ['@type' => 'Brand', 'name' => 'RightAlly'],
            'description' => 'An all-in-one platform for solo real estate agents: CRM, AI lead response, deals, e-signature and a goal planner, in English or Spanish.',
            'offers' => $packages->map(fn ($p) => ['@type' => 'Offer', 'name' => $p->name, 'price' => number_format($p->monthly_cents / 100, 2, '.', ''), 'priceCurrency' => 'USD',
                'url' => $canonical, 'availability' => 'https://schema.org/InStock'])->values()->all()],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
    ]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
@extends('billing::health.layout', ['title' => 'RightAlly for Agents: prices and signup', 'indexable' => true, 'canonical' => $canonical, 'jsonld' => $jsonld,
    'section' => 'RightAlly for Agents', 'home' => '/agents', 'signup' => true,
    'description' => 'RightAlly for solo real estate agents: public prices, start in ten minutes, simple view in English or Spanish, and your contacts stay yours.'])

@section('main')
<section class="hero"><div class="wrap">
    <h1>RightAlly for Agents</h1>
    <p class="lead">Everything one agent needs, in one place, and simple to use. Start in ten minutes. Your contacts stay yours.</p>
    @if ($cancelled)<p class="card" role="status">Payment was cancelled. Nothing was charged; you can try again below.</p>@endif
    <ul class="promises">
        <li><strong>Ten-minute start</strong>Bring your old CRM over; "Who to call today" is ready the same day.</li>
        <li><strong>Simple view</strong>A short menu, big buttons, English or Spanish.</li>
        <li><strong>Your people are yours</strong>Download them any time, take them with you if you move.</li>
        <li><strong>A goal you can reach</strong>"24 closings this year" becomes calls a day.</li>
    </ul>
</div></section>

<div class="wrap">
    <h2>Plans</h2>
    @if ($packages->isEmpty())
        <p class="muted">Plans for agents are being updated.</p>
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

    <h2 id="signup">Start now</h2>
    @if (! $open)
        <p class="card">Online signup for agents opens soon.</p>
    @else
        @if ($errors->any())
            <div class="errors" role="alert"><strong>Please check these:</strong><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        <form method="post" action="/agents" novalidate>
            @csrf
            <div class="hp" aria-hidden="true"><label>Fax<input type="text" name="fax" tabindex="-1" autocomplete="off"></label></div>
            <fieldset><legend>1. About you</legend>
                <div class="two">
                    <label>Your name<input type="text" name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name"></label>
                    <label>Your email (your sign-in link goes here)<input type="email" name="email" required maxlength="190" value="{{ old('email') }}" autocomplete="email"></label>
                    <label>Team or business name (optional)<input type="text" name="business" maxlength="150" value="{{ old('business') }}" autocomplete="organization"></label>
                    <label>State (two letters, optional)<input type="text" name="state" maxlength="2" value="{{ old('state') }}"></label>
                    <label>Your RightAlly address
                        <span style="display:flex;align-items:center;gap:6px"><input type="text" name="slug" required maxlength="40" value="{{ old('slug') }}" pattern="[a-z0-9-]+" autocapitalize="none" aria-describedby="slug-help"> <span class="muted">.{{ config('core.platform_domain') }}</span></span>
                        <span id="slug-help" class="muted small" style="font-weight:400">Letters, numbers and dashes, for example jane-smith-homes.</span>
                    </label>
                </div>
            </fieldset>
            @include('billing::signup.boards', ['number' => 2])
            <fieldset><legend>{{ empty($idxBoards) ? 2 : 3 }}. Plan</legend>
                <div class="choices" role="radiogroup" aria-label="Plan">
                    @foreach ($packages as $p)
                        <label><input type="radio" name="package" value="{{ $p->ulid }}" @checked(old('package', $packages->first()->ulid) === $p->ulid)> {{ $p->name }} — {{ $usd((int) $p->monthly_cents) }} a month or {{ $usd((int) $p->yearly_cents) }} a year</label>
                    @endforeach
                </div>
                <div class="choices" role="radiogroup" aria-label="How often to pay">
                    <label><input type="radio" name="interval" value="month" @checked(old('interval', 'month') === 'month')> Pay monthly</label>
                    <label><input type="radio" name="interval" value="year" @checked(old('interval') === 'year')> Pay yearly</label>
                </div>
                <label class="check"><input type="checkbox" name="terms" value="1" @checked(old('terms'))> I agree to pay the price shown each month or year until I cancel.</label>
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

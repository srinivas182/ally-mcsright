@extends('sites::layout')

{{--
    AG3: one landing page design for all ten kinds, made to be shared by an agent: a full-width photo with the
    promise and the short form side by side, three reasons, the agent who shares it (by name, with a call button),
    a short plan where the kind has one, and the homes. Value pages ask for the home and answer with a price range;
    home pages save the search and email new matches.
--}}
@php
    $first = $agent !== null ? ($agent['first_name'] !== '' ? $agent['first_name'] : $agent['name']) : null;
    $tel = $agent !== null ? preg_replace('/[^0-9+]/', '', (string) $agent['phone']) : '';
    $initials = $agent !== null ? mb_strtoupper(collect(explode(' ', $agent['name']))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('')) : '';
    $check = '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M9.5 16.2 5.3 12l-1.4 1.4 5.6 5.6 11-11-1.4-1.4z"/></svg>';
@endphp

@section('content')
<main id="main" class="lp funnel funnel-{{ $funnel->kind }}">
    <section class="lp-hero">
        @if ($photo)<img class="lp-hero-bg" src="{{ $photo }}" alt="" width="1600" height="900" fetchpriority="high">@endif
        <div class="wrap lp-hero-grid">
            <div class="lp-copy">
                @if ($agent !== null)
                    <p class="lp-agent">
                        @if ($agent['photo'])<img src="{{ $agent['photo'] }}" alt="" width="40" height="40">@else<span aria-hidden="true">{{ $initials }}</span>@endif
                        With {{ $agent['name'] }}, {{ $site->displayName() }}
                    </p>
                @endif
                <h1 class="lp-title">{{ $funnel->headline }}</h1>
                @if ($value && $result)
                    @if ($result['mid'])
                        <p class="answer lp-intro">Based on {{ $result['comps'] }} similar homes for sale near {{ $result['address'] }}, your home is likely worth</p>
                        <p class="value-range lp-range"><strong>${{ number_format($result['low']) }} – ${{ number_format($result['high']) }}</strong></p>
                        <p class="lp-note">{{ $first ?? 'A local agent' }} will confirm this with recent sales and your home's condition, usually within one business day. Asking prices can differ from what homes sell for.</p>
                    @else
                        <p class="answer lp-intro">Thank you. There aren't enough similar homes for sale near {{ $result['address'] }} right now to estimate online, so {{ $first ?? 'a local agent' }} will prepare your price range from recent sales, usually within one business day.</p>
                    @endif
                @elseif ($result)
                    <p class="answer lp-intro">You're all set. New homes that match will come to your email the day they're listed, and {{ $first ?? 'a local agent' }} can answer any question. You can stop the emails any time.</p>
                @else
                    <p class="answer lp-intro">{{ $funnel->intro }}</p>
                    <ul class="lp-points">
                        @foreach ($kind['points'] as $point)<li>{!! $check !!}<span>{{ $point }}</span></li>@endforeach
                    </ul>
                    @if (! $value && $stats !== null && ($stats['total'] ?? 0) > 0)
                        <p class="lp-note">{{ number_format($stats['total']) }} {{ $stats['total'] === 1 ? 'home matches' : 'homes match' }} right now{{ $funnel->max_price ? ' up to $'.number_format($funnel->max_price) : '' }}.</p>
                    @endif
                @endif
            </div>

            @if (! $result)
                <div class="form-card lp-form" id="ask">
                    <h2 class="lp-form-title">{{ $value ? 'Tell us about your home' : 'Where should we send them?' }}</h2>
                    @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                    <form method="post" action="/_funnel/{{ $funnel->slug }}" data-lead>
                        @if ($value)
                            <label>Street address <input name="address" required autocomplete="street-address"></label>
                            <div class="form-row">
                                <label>ZIP code <input name="zip" required inputmode="numeric" pattern="[0-9]{5}" maxlength="5" autocomplete="postal-code"></label>
                                <label>Beds <input name="beds" type="number" min="0" max="20" inputmode="numeric"></label>
                                <label>Square feet <input name="sqft" type="number" min="300" max="50000" inputmode="numeric"></label>
                            </div>
                            @if ($funnel->kind === 'downsizing')
                                <label>When would you like to move? <select name="timeline"><option value="">Choose</option><option>Within 3 months</option><option>3 to 6 months</option><option>6 to 12 months</option><option>Just looking</option></select></label>
                            @endif
                        @else
                            <label>Budget (up to) <input name="budget" inputmode="numeric" placeholder="{{ $funnel->max_price ? '$'.number_format($funnel->max_price) : '$350,000' }}"></label>
                            @if ($funnel->kind === 'first_home')
                                <div class="form-row">
                                    <label>When would you like to move? <select name="timeline"><option value="">Choose</option><option>Within 3 months</option><option>3 to 6 months</option><option>6 to 12 months</option><option>Just looking</option></select></label>
                                    <label>Pre-approved? <select name="preapproved"><option value="">Choose</option><option>Yes</option><option>Not yet</option></select></label>
                                </div>
                            @elseif ($funnel->kind === 'moving')
                                <label>When are you moving? <select name="move_date"><option value="">Choose</option><option>Within 3 months</option><option>3 to 6 months</option><option>6 to 12 months</option><option>Not sure yet</option></select></label>
                            @elseif ($funnel->kind === 'weekend_tours')
                                <label>When would you like to tour? <select name="timeline"><option value="">Choose</option><option>This weekend</option><option>Next weekend</option><option>A weekday evening</option></select></label>
                            @elseif ($funnel->kind === 'investor')
                                <label>Paying with <select name="financing"><option value="">Choose</option><option>Cash</option><option>A loan</option><option>Not sure yet</option></select></label>
                            @endif
                        @endif
                        <label>Name <input name="name" required autocomplete="name"></label>
                        <label>Email <input type="email" name="email" required autocomplete="email"></label>
                        <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                        @if ($value)<p class="lead-muted">We'll email you an updated range once a month. Stop any time with one click.</p>@endif
                        <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
                        <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                        <input type="hidden" name="first_referrer"><input type="hidden" name="landing_url"><input type="hidden" name="vid" data-vid>
                        @foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'] as $k)
                            @if (is_string(request()->query($k)))<input type="hidden" name="{{ $k }}" value="{{ mb_substr(request()->query($k), 0, 150) }}">@endif
                        @endforeach
                        <input type="hidden" name="t" value="{{ $t }}">
                        @include('core::bot-check', ['bot' => $bot ?? null])
                        <button class="btn btn-primary lp-submit" type="submit">{{ $kind['button'] }}</button>
                        <p class="lp-small">Free, with no obligation. Stop any time.</p>
                    </form>
                </div>
            @endif
        </div>
    </section>

    @if (! empty($kind['steps']) && ! $result)
        <section class="section lp-steps-wrap">
            <div class="wrap">
                <h2 class="section-title">How it works</h2>
                <ol class="lp-steps">
                    @foreach ($kind['steps'] as $step)<li><span>{{ $step }}</span></li>@endforeach
                </ol>
            </div>
        </section>
    @endif

    @if ($funnel->kind === 'moving' && ($stats['market'] ?? null))
        <section class="section lp-market">
            <div class="wrap">
                <h2 class="section-title">{{ $funnel->area }} today</h2>
                <dl class="lp-facts">
                    @if ($stats['market']['median_price'])<div><dt>Typical asking price</dt><dd>${{ number_format($stats['market']['median_price']) }}</dd></div>@endif
                    <div><dt>Homes for sale</dt><dd>{{ number_format($stats['market']['active']) }}</dd></div>
                    <div><dt>New in the last 30 days</dt><dd>{{ number_format($stats['market']['new_30']) }}</dd></div>
                    @if ($stats['market']['median_dom'])<div><dt>Typical days on the market</dt><dd>{{ $stats['market']['median_dom'] }}</dd></div>@endif
                </dl>
            </div>
        </section>
    @endif

    @if ($homes !== [])
        <section class="section section-alt">
            <div class="wrap">
                <h2 class="section-title">{{ $result ? 'Homes that match' : ($funnel->kind === 'price_drops' ? 'Prices that just came down' : 'Homes for sale now') }}</h2>
                <ul class="cards">@include('sites::idx.cards', ['listings' => $homes])</ul>
                <p><a class="btn" href="/homes?q={{ urlencode((string) $funnel->area) }}">See every home in {{ $funnel->area }}</a></p>
            </div>
        </section>
    @endif

    @if ($agent !== null && $tel !== '')
        <section class="lp-close">
            <div class="wrap lp-close-inner">
                <p class="lp-close-text"><strong>Questions about {{ $funnel->area }}?</strong> {{ $first }} will answer personally.</p>
                <p class="lp-close-actions">
                    <a class="btn btn-primary" href="tel:{{ $tel }}">Call {{ $first }}</a>
                    <a class="btn btn-ghost" href="sms:{{ $tel }}">Text {{ $first }}</a>
                </p>
            </div>
        </section>
    @endif
</main>
@endsection

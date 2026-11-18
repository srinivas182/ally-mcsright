@extends('sites::layout')

@section('content')
<main id="main" class="section funnel funnel-{{ $funnel->kind }}">
    <div class="wrap contact-grid" id="ask">
        <div>
            <h1 class="section-title">{{ $funnel->headline }}</h1>
            @if ($funnel->kind === 'home_value' && $result)
                @if ($result['mid'])
                    <p class="answer">Based on {{ $result['comps'] }} similar homes for sale near {{ $result['address'] }}, your home is likely worth</p>
                    <p class="value-range"><strong>${{ number_format($result['low']) }} – ${{ number_format($result['high']) }}</strong></p>
                    <p class="lead-muted">A local agent will confirm this with recent sales and your home's condition, usually within one business day. Asking prices can differ from what homes sell for.</p>
                @else
                    <p class="answer">Thank you. There aren't enough similar homes for sale near {{ $result['address'] }} right now to estimate online, so a local agent will prepare your price range from recent sales, usually within one business day.</p>
                @endif
            @elseif ($result)
                <p class="answer">You're all set. New homes that match will come to your email the day they're listed, and a local agent can answer any question. You can stop the emails any time.</p>
            @else
                <p class="answer">{{ $funnel->intro }}</p>
                @if ($funnel->kind !== 'home_value' && $stats !== null && ($stats['total'] ?? 0) > 0)
                    <p class="lead-muted">{{ number_format($stats['total']) }} {{ $stats['total'] === 1 ? 'home matches' : 'homes match' }} right now{{ $funnel->max_price ? ' up to $'.number_format($funnel->max_price) : '' }}.</p>
                @endif
                @if ($funnel->kind === 'first_home')
                    <ol class="contact-facts">
                        <li>Get pre-approved, so you know your budget and sellers take your offer seriously.</li>
                        <li>Tour homes at that budget with a local agent.</li>
                        <li>Make an offer, get an inspection and an appraisal.</li>
                        <li>Close and get your keys.</li>
                    </ol>
                @endif
                @if ($funnel->kind === 'moving' && ($stats['market'] ?? null))
                    <ul class="contact-facts">
                        @if ($stats['market']['median_price'])<li>Typical asking price: ${{ number_format($stats['market']['median_price']) }}</li>@endif
                        <li>{{ number_format($stats['market']['active']) }} homes for sale, {{ number_format($stats['market']['new_30']) }} new in the last 30 days</li>
                        @if ($stats['market']['median_dom'])<li>Homes typically stay on the market {{ $stats['market']['median_dom'] }} days</li>@endif
                    </ul>
                @endif
            @endif
        </div>
        @if (! $result)
            <div class="form-card">
                @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                <form method="post" action="/_funnel/{{ $funnel->slug }}" data-lead>
                    @if ($funnel->kind === 'home_value')
                        <label>Street address <input name="address" required autocomplete="street-address"></label>
                        <div class="form-row">
                            <label>ZIP code <input name="zip" required inputmode="numeric" pattern="[0-9]{5}" maxlength="5" autocomplete="postal-code"></label>
                            <label>Beds <input name="beds" type="number" min="0" max="20" inputmode="numeric"></label>
                            <label>Square feet <input name="sqft" type="number" min="300" max="50000" inputmode="numeric"></label>
                        </div>
                    @else
                        <label>Budget (up to) <input name="budget" inputmode="numeric" placeholder="{{ $funnel->max_price ? '$'.number_format($funnel->max_price) : '$350,000' }}"></label>
                        @if ($funnel->kind === 'first_home')
                            <div class="form-row">
                                <label>When would you like to move? <select name="timeline"><option value="">Choose</option><option>Within 3 months</option><option>3 to 6 months</option><option>6 to 12 months</option><option>Just looking</option></select></label>
                                <label>Pre-approved? <select name="preapproved"><option value="">Choose</option><option>Yes</option><option>Not yet</option></select></label>
                            </div>
                        @elseif ($funnel->kind === 'moving')
                            <label>When are you moving? <select name="move_date"><option value="">Choose</option><option>Within 3 months</option><option>3 to 6 months</option><option>6 to 12 months</option><option>Not sure yet</option></select></label>
                        @endif
                    @endif
                    <label>Name <input name="name" required autocomplete="name"></label>
                    <label>Email <input type="email" name="email" required autocomplete="email"></label>
                    <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                    @if ($funnel->kind === 'home_value')<p class="lead-muted">We'll email you an updated range once a month. Stop any time with one click.</p>@endif
                    <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
                    <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                    <input type="hidden" name="first_referrer"><input type="hidden" name="landing_url"><input type="hidden" name="vid" data-vid>
                    @foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid'] as $k)
                        @if (is_string(request()->query($k)))<input type="hidden" name="{{ $k }}" value="{{ mb_substr(request()->query($k), 0, 150) }}">@endif
                    @endforeach
                    <input type="hidden" name="t" value="{{ $t }}">
                    @include('core::bot-check', ['bot' => $bot ?? null])
                    <button class="btn btn-primary" type="submit">{{ $funnel->kind === 'home_value' ? 'See my home\'s value' : 'Send me these homes' }}</button>
                </form>
            </div>
        @endif
    </div>
    @if ($homes !== [])
        <section class="section section-alt">
            <div class="wrap">
                <h2 class="section-title">{{ $result ? 'Homes that match' : 'Homes for sale now' }}</h2>
                <ul class="cards">@include('sites::idx.cards', ['listings' => $homes])</ul>
                <p><a class="btn" href="/homes?q={{ urlencode((string) $funnel->area) }}">See every home in {{ $funnel->area }}</a></p>
            </div>
        </section>
    @endif
</main>
@endsection

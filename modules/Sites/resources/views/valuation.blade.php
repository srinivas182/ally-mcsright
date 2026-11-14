@extends('sites::layout')

@section('content')
<main id="main" class="section valuation">
    <div class="wrap contact-grid" id="value">
        <div>
            <h1 class="section-title">What is my home worth?</h1>
            @if ($result)
                @if ($result['mid'])
                    <p class="answer">Based on {{ $result['comps'] }} similar homes for sale near {{ $result['address'] }} ({{ $result['zip'] }}), your home is likely worth</p>
                    <p class="value-range"><strong>${{ number_format($result['low']) }} – ${{ number_format($result['high']) }}</strong></p>
                    <p class="lead-muted">An agent will confirm this with recent sales and your home's condition, usually within one business day. Asking prices can differ from what homes sell for.</p>
                @else
                    <p class="answer">Thank you. There are not enough similar homes for sale near {{ $result['address'] }} right now to estimate online, so an agent will prepare your price range from recent sales, usually within one business day.</p>
                @endif
            @else
                <p class="answer">Get a free price range for your home from similar homes for sale nearby, then a local agent from {{ $site->displayName() }} confirms it with recent sales. No cost, no obligation.</p>
                <ul class="contact-facts">
                    <li>Compared by size and bedrooms in your ZIP code</li>
                    <li>Checked by an agent who knows your area</li>
                    <li>Your details go only to {{ $site->displayName() }}</li>
                </ul>
            @endif
        </div>
        @if (! $result)
            <div class="form-card">
                @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                <form method="post" action="/_valuation" data-lead>
                    <label>Street address <input name="address" required autocomplete="street-address"></label>
                    <div class="form-row">
                        <label>ZIP code <input name="zip" required inputmode="numeric" pattern="[0-9]{5}" maxlength="5" autocomplete="postal-code"></label>
                        <label>Beds <input name="beds" type="number" min="0" max="20" inputmode="numeric"></label>
                        <label>Baths <input name="baths" type="number" min="0" max="20" inputmode="numeric"></label>
                    </div>
                    <label>Square feet <input name="sqft" type="number" min="300" max="50000" inputmode="numeric"></label>
                    <label>Name <input name="name" required autocomplete="name"></label>
                    <label>Email <input type="email" name="email" autocomplete="email"></label>
                    <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                    <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
                    <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                    <input type="hidden" name="first_referrer"><input type="hidden" name="landing_url"><input type="hidden" name="vid" data-vid>
                    <input type="hidden" name="t" value="{{ $t }}">
                    <button class="btn btn-primary" type="submit">See my home's value</button>
                </form>
            </div>
        @endif
    </div>
    @if ($comps !== [])
        <section class="section section-alt">
            <div class="wrap">
                <h2 class="section-title">Similar homes for sale nearby</h2>
                <ul class="cards">@include('sites::idx.cards', ['listings' => $comps])</ul>
            </div>
        </section>
    @endif
</main>
@endsection

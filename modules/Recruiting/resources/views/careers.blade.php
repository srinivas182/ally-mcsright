@extends('sites::layout')

@section('content')
<main id="main">
    <section class="section">
        <div class="wrap contact-grid">
            <div>
                <h1 class="section-title">{{ $careers['headline'] }}</h1>
                <p class="answer">{{ $careers['intro'] }}</p>
                @if ($careers['reasons'] !== [])
                    <ul class="contact-facts">
                        @foreach ($careers['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach
                    </ul>
                @endif
                <p><a class="btn btn-primary" href="#apply">Talk to us</a></p>
            </div>
            @if ($careers['calculator'])
                <div class="form-card" id="earn">
                    <h2 class="form-title">What would you keep here?</h2>
                    @if ($result)
                        @if ($result['problem'])
                            <p class="form-error" role="alert">{{ $result['problem'] }}</p>
                        @else
                            <p class="value-range" role="status"><strong>${{ number_format(intdiv($result['here_cents'], 100)) }}</strong> a year here</p>
                            <p class="answer">
                                Against ${{ number_format(intdiv($result['there_cents'], 100)) }} on your plan today:
                                <strong>{{ $result['difference_cents'] >= 0 ? '$'.number_format(intdiv($result['difference_cents'], 100)).' more' : '$'.number_format(intdiv(-$result['difference_cents'], 100)).' less' }}</strong>.
                            </p>
                            <ul class="lead-muted">@foreach ($result['assumptions'] as $a)<li>{{ str_replace('Today:', 'Your plan today:', $a) }}</li>@endforeach</ul>
                            <p class="lead-muted">An estimate, not an offer: a broker will go through it with your own deals.</p>
                        @endif
                    @endif
                    <form method="get" action="/careers#earn">
                        <div class="form-row">
                            <label>Deals a year <input name="deals" type="number" min="1" max="100" required inputmode="numeric" value="{{ $result['input']['deals'] ?? '' }}"></label>
                            <label>Average price ($) <input name="price" required inputmode="numeric" value="{{ isset($result['input']['price']) ? number_format($result['input']['price']) : '' }}"></label>
                        </div>
                        <div class="form-row">
                            <label>You keep today (%) <input name="split" type="number" min="0" max="100" step="0.5" inputmode="decimal" value="{{ $result['input']['split'] ?? '' }}"></label>
                            <label>Your cap ($, if any) <input name="cap" inputmode="numeric" value="{{ isset($result['input']['cap']) ? number_format($result['input']['cap']) : '' }}"></label>
                        </div>
                        <button class="btn btn-primary" type="submit">Work it out</button>
                    </form>
                </div>
            @endif
        </div>
    </section>

    @if ($stories !== [])
        <section class="section section-alt" aria-labelledby="stories">
            <div class="wrap">
                <h2 class="section-title" id="stories">In our agents' words</h2>
                <ul class="quotes">
                    @foreach ($stories as $s)
                        <li><figure>
                            <blockquote>“{{ $s['quote'] }}”</blockquote>
                            <figcaption><strong>{{ $s['name'] }}</strong>@if ($s['deals'] !== null && $s['deals'] > 0) · {{ $s['deals'] }} {{ $s['deals'] === 1 ? 'closing' : 'closings' }} in the last 12 months @endif</figcaption>
                        </figure></li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    <section class="section" id="apply">
        <div class="wrap contact-grid">
            <div>
                <h2 class="section-title">Talk to us</h2>
                <p class="answer">Tell us a little about you. A broker will call you within one business day, with no obligation. Your details go only to {{ $site->displayName() }}.</p>
            </div>
            <div class="form-card">
                @if ($sent)
                    <p class="form-ok" role="status">Thank you. We will be in touch within one business day.</p>
                @else
                    @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                    <form method="post" action="/_careers">
                        <label>Name <input name="name" required autocomplete="name" maxlength="120"></label>
                        <label>Email <input type="email" name="email" required autocomplete="email" maxlength="190"></label>
                        <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                        <div class="form-row">
                            <label>Licence number <input name="licence" maxlength="40"></label>
                            <label>Deals a year <input name="deals" type="number" min="0" max="500" inputmode="numeric"></label>
                        </div>
                        <label>Brokerage today <input name="brokerage" maxlength="120" autocomplete="organization"></label>
                        <label>Anything you'd like us to know <textarea name="message" rows="3" maxlength="1000"></textarea></label>
                        <label class="consent"><input type="checkbox" name="text_consent" value="1"> <span>{{ $textConsent }}</span></label>
                        <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                        <input type="hidden" name="t" value="{{ $t }}">
                        <button class="btn btn-primary" type="submit">Send</button>
                    </form>
                @endif
            </div>
        </div>
    </section>

    <section class="section section-alt">
        <div class="wrap narrow">
            <h2 class="section-title">Questions agents ask</h2>
            <div class="faq">
                @foreach ($faqs as $f)<details @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach
            </div>
        </div>
    </section>
</main>
@endsection

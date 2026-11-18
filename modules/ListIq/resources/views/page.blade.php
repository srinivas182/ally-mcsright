@extends('sites::layout')

@section('content')
<main id="main">
    <section class="section">
        <div class="wrap">
            <h1 class="section-title">{{ $prep->street }}@if ($prep->city), {{ $prep->city }}@endif</h1>
            <p class="answer">
                @if (isset($facts['price']))<strong>${{ number_format((int) $facts['price']) }}</strong> · @endif
                @if (isset($facts['beds'])){{ $facts['beds'] }} bd · @endif
                @if (isset($facts['baths'])){{ $facts['baths'] }} ba · @endif
                @if (isset($facts['sqft'])){{ number_format((int) $facts['sqft']) }} sq ft @endif
                @if (isset($facts['year_built'])) · built {{ $facts['year_built'] }}@endif
            </p>
            @if ($photos !== [])
                <ul class="cards">
                    @foreach ($photos as $i => $p)
                        <li><figure style="margin:0">
                            <img src="{{ $p['url'] }}" alt="{{ $p['caption'] }}" @if ($i > 0) loading="lazy" @endif style="width:100%;aspect-ratio:3/2;object-fit:cover;border-radius:8px">
                            <figcaption class="lead-muted">{{ $p['caption'] }}@if ($p['staged']) <strong>(virtually staged)</strong>@endif</figcaption>
                        </figure></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
    <section class="section" id="showing">
        <div class="wrap narrow">
            <h2 class="section-title">Book a showing (buyers' agents)</h2>
            @if ($booked === '2')<p class="form-ok" role="status">Your showing is confirmed. Check your email for the details.</p>
            @elseif ($booked === '1')<p class="form-ok" role="status">Thank you. The listing agent will confirm by email.</p>
            @else
                @if ($showingError)<p class="form-error" role="alert">{{ $showingError }}</p>@endif
                <form method="post" action="/_showing/{{ $prep->ulid }}" class="form-card">
                    <div class="form-row">
                        <label>Your name <input name="name" required autocomplete="name"></label>
                        <label>Brokerage <input name="brokerage" autocomplete="organization"></label>
                    </div>
                    <div class="form-row">
                        <label>Email <input type="email" name="email" required autocomplete="email"></label>
                        <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                    </div>
                    <div class="form-row">
                        <label>Date <input type="date" name="date" required></label>
                        <label>Time <input type="time" name="time" required step="900"></label>
                    </div>
                    <p class="lead-muted">Showings are 30 minutes, starting between 8am and 8pm. Access details come by email once confirmed.</p>
                    <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                    <button class="btn btn-primary" type="submit">Request this time</button>
                </form>
            @endif
        </div>
    </section>
    <section class="section section-alt">
        <div class="wrap contact-grid" id="ask">
            <div>
                <h2 class="section-title">About this home</h2>
                @if ($prep->description)<p>{{ $prep->description }}</p>@endif
                @if ($floorPlan)<p><a class="btn" href="{{ $floorPlan }}" target="_blank" rel="noopener">See the floor plan</a></p>@endif
                @if ($tour)
                    <h3>Walk through it ({{ $tour['name'] }})</h3>
                    @if ($tour['embed'])
                        <div style="position:relative;padding-top:56.25%;margin:12px 0"><iframe src="{{ $tour['embed'] }}" title="{{ $tour['name'] }} tour of {{ $prep->street }}" style="position:absolute;inset:0;width:100%;height:100%;border:0" loading="lazy" allow="fullscreen; xr-spatial-tracking" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>
                    @else
                        <p><a class="btn" href="{{ $tour['link'] }}" target="_blank" rel="noopener">Open the tour</a></p>
                    @endif
                @endif
                @if ($video)
                    <video controls playsinline preload="none" poster="{{ $video['poster'] }}" style="width:100%;max-width:360px;border-radius:12px;margin:12px 0" aria-label="Video of {{ $prep->street }}"><source src="{{ $video['url'] }}" type="video/mp4"><track kind="captions" src="{{ $video['captions'] }}" srclang="en" label="English" default></video>
                @endif
                @if ($agent)
                    <p class="lead-muted">Listed by {{ $agent['details']['display_name'] ?? '' }}, {{ $agent['brokerage'] ?? '' }}@if (! empty($agent['details']['phone'])) · {{ $agent['details']['phone'] }}@endif</p>
                @endif
            </div>
            <div class="form-card">
                @if ($sent)
                    <p class="form-ok" role="status">Thank you. The listing agent will be in touch shortly.</p>
                @else
                    <h2 class="form-title">Ask about this home or book a showing</h2>
                    @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                    <form method="post" action="/_listing/{{ $prep->ulid }}">
                        <label>Name <input name="name" required autocomplete="name"></label>
                        <label>Email <input type="email" name="email" autocomplete="email"></label>
                        <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                        <label>Message <textarea name="message" rows="3">I'd like to know more about {{ $prep->street }}.</textarea></label>
                        <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
                        <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                        <input type="hidden" name="t" value="{{ $t }}"><input type="hidden" name="src" value="{{ $src }}">
                        <button class="btn btn-primary" type="submit">Send</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
</main>
@endsection

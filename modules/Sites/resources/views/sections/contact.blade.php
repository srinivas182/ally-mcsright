<section class="section" id="contact">
    <div class="wrap contact-grid">
        <div>
            <h1 class="section-title">{{ $p['title'] }}</h1>
            @if ($p['body'] !== '')<p class="lead-muted">{{ $p['body'] }}</p>@endif
            <ul class="contact-facts">
                @if (! empty($site->info['phone']))<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">{{ $site->info['phone'] }}</a></li>@endif
                @if (! empty($site->info['email']))<li><a href="mailto:{{ $site->info['email'] }}">{{ $site->info['email'] }}</a></li>@endif
                @if (! empty($site->info['street']))<li>{{ $site->info['street'] }}, {{ $site->info['city'] ?? '' }}, {{ $site->info['state'] ?? '' }} {{ $site->info['zip'] ?? '' }}</li>@endif
            </ul>
        </div>
        <div class="form-card">
            @if ($sent)
                <p class="form-ok" role="status">Thank you. An agent will be in touch shortly.</p>
            @else
                @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                <form method="post" action="/_lead" data-lead>
                    <label>Name <input name="name" required autocomplete="name"></label>
                    <label>Email <input type="email" name="email" autocomplete="email"></label>
                    <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                    <label>I'm looking to
                        <select name="intent"><option value="">Choose…</option><option value="buy">Buy</option><option value="sell">Sell</option><option value="rent">Rent</option></select>
                    </label>
                    <label>Message <textarea name="message" rows="4"></textarea></label>
                    <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
                    <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                    <input type="hidden" name="first_referrer"><input type="hidden" name="landing_url">
                    <input type="hidden" name="return" value="{{ $page->path }}"><input type="hidden" name="t" value="{{ $t }}">
                    <button class="btn btn-primary" type="submit">Send</button>
                </form>
            @endif
        </div>
    </div>
</section>

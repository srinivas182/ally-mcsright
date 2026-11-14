<div class="form-card" id="contact">
    @if ($sent)
        <p class="form-ok" role="status">Thank you. An agent will be in touch shortly.</p>
    @else
        @if (! empty($heading))<h2 class="form-title">{{ $heading }}</h2>@endif
        @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
        <form method="post" action="/_lead" data-lead>
            <label>Name <input name="name" required autocomplete="name"></label>
            <label>Email <input type="email" name="email" autocomplete="email"></label>
            <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
            @if (empty($listing))
                <label>I'm looking to
                    <select name="intent"><option value="">Choose…</option><option value="buy">Buy</option><option value="sell">Sell</option><option value="rent">Rent</option></select>
                </label>
            @else
                <input type="hidden" name="intent" value="buy"><input type="hidden" name="listing" value="{{ $listing }}">
            @endif
            <label>Message <textarea name="message" rows="4">{{ $message ?? '' }}</textarea></label>
            <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
            <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
            <input type="hidden" name="first_referrer"><input type="hidden" name="landing_url">
            <input type="hidden" name="return" value="{{ $return }}"><input type="hidden" name="t" value="{{ $t }}">
            <button class="btn btn-primary" type="submit">{{ $button ?? 'Send' }}</button>
        </form>
    @endif
</div>

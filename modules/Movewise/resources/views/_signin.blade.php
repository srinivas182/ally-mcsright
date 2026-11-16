{{-- Sign in with an emailed code (specs/movewise/02). Works inside the dialog (JavaScript) and on /account without it. --}}
<p class="muted">{{ $t('signin.lead') }}</p>
<form data-signin-start method="post" action="{{ $u('/_auth/start') }}">
    <label for="mw-email{{ $suffix ?? '' }}">{{ $t('signin.email') }}</label>
    <input id="mw-email{{ $suffix ?? '' }}" name="email" type="email" autocomplete="email" required maxlength="190">
    <button class="btn">{{ $t('signin.send') }}</button>
</form>
<form data-signin-verify method="post" action="{{ $u('/_auth/verify') }}" hidden>
    <p class="muted" data-sent></p>
    <label for="mw-code{{ $suffix ?? '' }}">{{ $t('signin.code') }}</label>
    <input id="mw-code{{ $suffix ?? '' }}" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
    <button class="btn">{{ $t('signin.verify') }}</button>
</form>
<p class="small muted" role="alert" data-signin-error></p>
<p class="small muted">{{ $t('signin.why') }} <a href="/privacy">Privacy notice</a>.</p>

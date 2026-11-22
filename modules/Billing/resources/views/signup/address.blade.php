{{--
    SU1: the RightAlly address field, checked while it is typed: free, taken (with a suggestion) or not allowed.
    Params: $value, $hint, $from (the name of the field it is suggested from, e.g. "brokerage"), $example.
--}}
<label>Your RightAlly address
    <span style="display:flex;align-items:center;gap:6px"><input type="text" name="slug" id="slug" required maxlength="40" value="{{ $value }}" pattern="[a-z0-9-]+" autocapitalize="none" autocomplete="off" spellcheck="false" aria-describedby="slug-help slug-status"> <span class="muted">.{{ config('core.platform_domain') }}</span></span>
    <span id="slug-status" class="small slug-status" role="status" aria-live="polite"></span>
    <span id="slug-help" class="muted small" style="font-weight:400">{{ $hint }}</span>
</label>
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    var input = document.getElementById('slug'), out = document.getElementById('slug-status'); if (!input) return;
    var from = document.querySelector('[name="{{ $from }}"]'), mail = document.querySelector('[name="owner_email"], [name="email"]'), touched = input.value !== '', timer = null, last = '';
    function suggest(name) { return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40); }
    function show(ok, text, suggestion) {
        out.className = 'small slug-status ' + (ok ? 'ok' : 'bad');
        out.textContent = (ok ? '✓ ' : '✗ ') + text;
        if (suggestion) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'linkish'; b.textContent = 'Use ' + suggestion;
            b.addEventListener('click', function () { input.value = suggestion; touched = true; check(); });
            out.appendChild(document.createTextNode(' ')); out.appendChild(b);
        }
    }
    function check() {
        var slug = input.value.trim().toLowerCase();
        if (slug === '') { out.textContent = ''; return; }
        if (slug === last) return; last = slug;
        clearTimeout(timer);
        timer = setTimeout(function () {
            fetch('/start/address?slug=' + encodeURIComponent(slug) + (mail && mail.value ? '&email=' + encodeURIComponent(mail.value) : ''), { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (input.value.trim().toLowerCase() !== slug) return; d.ok ? show(true, d.host + ' is free.') : show(false, d.message, d.suggestion); })
                .catch(function () { out.textContent = ''; });
        }, 350);
    }
    input.addEventListener('input', function () { touched = true; input.value = input.value.toLowerCase(); check(); });
    if (from) from.addEventListener('input', function () { if (!touched) { input.value = suggest(from.value); last = ''; check(); } });
    check();
})();
</script>

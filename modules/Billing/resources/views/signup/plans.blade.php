{{--
    SU1: the packages as cards to choose from, with a Monthly / Yearly switch above them and a "You pay today" line
    that adds the extra MLS boards. Works without JavaScript (radio buttons); the script only updates the prices shown.
    Params: $packages, $features, $chosen (ulid or null), $number, $label ("Package" or "Plan").
--}}
@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $pick = old('package', $chosen ?? $packages->first()->ulid);
    $interval = old('interval', $interval ?? 'month');
    $saving = $packages->map(fn ($p) => $p->monthly_cents * 12 - $p->yearly_cents)->max();
@endphp
<fieldset id="plans" data-extra="{{ (int) ($boardExtra['cents'] ?? 0) }}" data-extra-count="{{ (int) ($boardExtra['count'] ?? 0) }}"><legend>{{ $number }}. Choose your {{ mb_strtolower($label) }}</legend>
    <div class="billing-switch" role="radiogroup" aria-label="How often to pay">
        <label><input type="radio" name="interval" value="month" @checked($interval !== 'year')> Monthly</label>
        <label><input type="radio" name="interval" value="year" @checked($interval === 'year')> Yearly
            @if ($saving > 0)<span class="save">Save up to {{ $usd((int) $saving) }}</span>@endif</label>
    </div>
    <div class="plans" role="radiogroup" aria-label="{{ $label }}">
        @foreach ($packages as $p)
            <label class="plan" data-month="{{ (int) $p->monthly_cents }}" data-year="{{ (int) $p->yearly_cents }}">
                <input type="radio" name="package" value="{{ $p->ulid }}" @checked($pick === $p->ulid)>
                <span class="plan-name">{{ $p->name }}</span>
                <span class="plan-price"><strong data-price>{{ $usd((int) ($interval === 'year' ? $p->yearly_cents : $p->monthly_cents)) }}</strong> <span class="muted small" data-per>{{ $interval === 'year' ? 'a year' : 'a month' }}</span></span>
                <span class="muted small" data-other>{{ $interval === 'year' ? 'or '.$usd((int) $p->monthly_cents).' a month' : 'or '.$usd((int) $p->yearly_cents).' a year' }}</span>
                @if ((array) $p->features !== [])
                    <ul class="small">@foreach ((array) $p->features as $f)@isset($features[$f])<li>{{ $features[$f] }}</li>@endisset @endforeach</ul>
                @endif
            </label>
        @endforeach
    </div>
    <p class="card small pay-today" id="pay-today" role="status" aria-live="polite"></p>
</fieldset>
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    var box = document.getElementById('plans'); if (!box) return;
    var money = function (c) { return '$' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: c % 100 ? 2 : 0, maximumFractionDigits: 2 }); };
    function update() {
        var year = (box.querySelector('input[name="interval"]:checked') || {}).value === 'year';
        var chosen = null;
        box.querySelectorAll('.plan').forEach(function (card) {
            var m = +card.getAttribute('data-month'), y = +card.getAttribute('data-year');
            card.querySelector('[data-price]').textContent = money(year ? y : m);
            card.querySelector('[data-per]').textContent = year ? 'a year' : 'a month';
            card.querySelector('[data-other]').textContent = year ? 'or ' + money(m) + ' a month' : 'or ' + money(y) + ' a year';
            if (card.querySelector('input').checked) { chosen = { name: card.querySelector('.plan-name').textContent, cents: year ? y : m }; }
        });
        var fees = Array.prototype.map.call(document.querySelectorAll('input[name="boards[]"]:checked'), function (i) { return +i.getAttribute('data-fee'); }).sort(function (a, b) { return b - a; });
        var boards = fees.slice(1).reduce(function (a, b) { return a + b; }, 0);
        if (fees.length === 0 && +box.getAttribute('data-extra-count') > 0) { boards = +box.getAttribute('data-extra'); fees = new Array(+box.getAttribute('data-extra-count') + 1); }
        var out = document.getElementById('pay-today');
        if (!chosen) { out.textContent = ''; return; }
        var text = 'You pay today: ' + money(chosen.cents) + ' (' + chosen.name + ', ' + (year ? 'yearly' : 'monthly') + ')';
        if (boards > 0) { text += ' + ' + money(boards) + ' a month for ' + (fees.length - 1) + ' extra MLS board' + (fees.length > 2 ? 's' : ''); }
        out.textContent = text + '. Taxes may apply.';
    }
    document.addEventListener('change', function (e) { if (e.target.matches('input[name="interval"], input[name="package"], input[name="boards[]"]')) update(); });
    update();
})();
</script>

{{--
    IDX7: the MLS boards a brokerage or agent belongs to. Tick your states, then your boards. One board is included in
    the price; each extra board adds its own monthly fee. Works without JavaScript (every board is shown); the small
    script only hides boards outside the ticked states and adds up the total.
--}}
@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $picked = array_map('strval', (array) old('boards', $prefillBoards ?? []));
@endphp
@if (! empty($idxBoards))
<fieldset id="idx-boards"><legend>{{ ($number ?? '') !== '' ? $number.'. ' : '' }}Your MLS boards (for listings on your website)</legend>
    <p class="muted small" style="margin-top:0">Tick the states where you hold a licence, then the boards you belong to. <strong>One board is included in the price.</strong> Each extra board adds the fee shown, every month. You can skip this and add boards later in Settings › MLS.</p>
    <div class="choices" role="group" aria-label="States where you hold a licence" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        @foreach ($idxStates as $code => $name)
            <label><input type="checkbox" data-idx-state value="{{ $code }}" @checked(collect($idxBoards)->contains(fn ($b) => $b['state'] === $code && in_array($b['ulid'], $picked, true)))> {{ $name }}</label>
        @endforeach
    </div>
    <div class="choices" role="group" aria-label="MLS boards">
        @foreach ($idxBoards as $b)
            <label data-idx-board="{{ $b['state'] }}"><input type="checkbox" name="boards[]" value="{{ $b['ulid'] }}" data-fee="{{ (int) $b['fee_cents'] }}" @checked(in_array($b['ulid'], $picked, true))>
                <span>{{ $b['name'] }} <span class="muted small">({{ $b['region'] ?: $idxStates[$b['state']] ?? $b['state'] }})</span><br>
                <span class="muted small">{{ $usd((int) $b['fee_cents']) }} a month as an extra board. Approval usually takes {{ $b['approval'] }}.</span></span>
            </label>
        @endforeach
    </div>
    <p class="card small" id="idx-total" role="status" aria-live="polite">No boards ticked yet.</p>
    <p class="muted small">Your board is not listed? Sign up without it, then choose "My board isn’t listed" in Settings › MLS and we will add it. After you pay, we prepare each board's paperwork for you to sign. Until the board approves, your website shows sample listings, clearly marked.</p>
</fieldset>
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    var box = document.getElementById('idx-boards'); if (!box) return;
    var money = function (c) { return '$' + Math.round(c / 100).toLocaleString('en-US'); };
    function update() {
        var states = Array.prototype.map.call(box.querySelectorAll('[data-idx-state]:checked'), function (i) { return i.value; });
        box.querySelectorAll('[data-idx-board]').forEach(function (row) {
            var show = states.length === 0 || states.indexOf(row.getAttribute('data-idx-board')) !== -1 || row.querySelector('input').checked;
            row.style.display = show ? '' : 'none';
        });
        var fees = Array.prototype.map.call(box.querySelectorAll('input[name="boards[]"]:checked'), function (i) { return +i.getAttribute('data-fee'); }).sort(function (a, b) { return b - a; });
        var out = document.getElementById('idx-total');
        if (fees.length === 0) { out.textContent = 'No boards ticked yet.'; return; }
        var extra = fees.slice(1).reduce(function (a, b) { return a + b; }, 0);
        out.textContent = fees.length === 1 ? '1 board: included in your price.' : fees.length + ' boards: 1 included, plus ' + money(extra) + ' a month for the other ' + (fees.length - 1) + '.';
    }
    box.addEventListener('change', update); update();
})();
</script>
@endif

{{--
    IDX7 / SU3: the MLS boards a brokerage or agent belongs to, in two small steps: first the states where they hold a
    licence (only states that have a board on offer), then at least one board in each of those states, grouped by state.
    One board is included in the price; each extra board adds its own monthly fee. Works without JavaScript (every
    state's boards are shown); the small script shows a state's boards once it is ticked and adds up the total.
--}}
@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $picked = array_map('strval', (array) old('boards', $prefillBoards ?? []));
    $byState = [];
    foreach ($idxBoards ?? [] as $b) {
        foreach ((array) ($b['states'] ?? [$b['state']]) as $st) {
            if (isset($idxStates[$st])) { $byState[$st][] = $b; }
        }
    }
    $licensed = array_map('strval', (array) old('licence_states', array_keys(array_filter($byState, fn ($list) => collect($list)->contains(fn ($b) => in_array($b['ulid'], $picked, true))))));
@endphp
@if (! empty($byState))
<fieldset id="idx-boards"><legend>{{ ($number ?? '') !== '' ? $number.'. ' : '' }}Your MLS boards (for listings on your website)</legend>
    <p class="muted small">Your website shows homes for sale from the MLS boards you belong to. <strong>One board is included in the price.</strong> Each extra board adds the fee shown, every month. You can skip this and add boards later in Settings › MLS.</p>

    <h3 class="sub">a. Where do you hold a real estate licence?</h3>
    <div class="choices" role="group" aria-label="States where you hold a licence" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
        @foreach ($byState as $code => $list)
            <label><input type="checkbox" name="licence_states[]" data-idx-state value="{{ $code }}" @checked(in_array($code, $licensed, true))> {{ $idxStates[$code] }}</label>
        @endforeach
    </div>

    <h3 class="sub">b. Choose your board in each state</h3>
    <p class="muted small" data-idx-none>Tick a state above and its boards appear here.</p>
    @foreach ($byState as $code => $list)
        <div class="board-group" data-idx-group="{{ $code }}">
            <p class="board-group-title"><strong>{{ $idxStates[$code] }}</strong> <span class="muted small">choose at least one</span></p>
            <div class="choices" role="group" aria-label="MLS boards in {{ $idxStates[$code] }}">
                @foreach ($list as $b)
                    <label><input type="checkbox" name="boards[]" value="{{ $b['ulid'] }}" data-fee="{{ (int) $b['fee_cents'] }}" @checked(in_array($b['ulid'], $picked, true))>
                        <span>{{ $b['name'] }} @if ($b['region'])<span class="muted small">({{ $b['region'] }})</span>@endif<br>
                        <span class="muted small">{{ $usd((int) $b['fee_cents']) }} a month as an extra board. Approval usually takes {{ $b['approval'] }}.</span></span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
    @error('licence_states')<p class="error" role="alert">{{ $message }}</p>@enderror
    <p class="card small" id="idx-total" role="status" aria-live="polite">No boards ticked yet.</p>
    <p class="muted small">Your board is not listed? Sign up without it, then choose "My board isn’t listed" in Settings › MLS and we will add it. After you pay, we prepare each board's paperwork for you to sign. Until the board approves, your website shows sample listings, clearly marked.</p>
</fieldset>
<script nonce="{{ Vite::cspNonce() }}">
(function () {
    var box = document.getElementById('idx-boards'); if (!box) return;
    var money = function (c) { return '$' + Math.round(c / 100).toLocaleString('en-US'); };
    function update(e) {
        // A board that serves two states is listed under both: keep both copies in step.
        if (e && e.target && e.target.name === 'boards[]') {
            box.querySelectorAll('input[name="boards[]"][value="' + e.target.value + '"]').forEach(function (i) { i.checked = e.target.checked; });
        }
        var states = Array.prototype.map.call(box.querySelectorAll('[data-idx-state]:checked'), function (i) { return i.value; });
        box.querySelectorAll('[data-idx-group]').forEach(function (g) {
            var on = states.indexOf(g.getAttribute('data-idx-group')) !== -1;
            g.hidden = !on;
            if (!on) { g.querySelectorAll('input').forEach(function (i) {
                var elsewhere = Array.prototype.some.call(box.querySelectorAll('[data-idx-group]:not([hidden]) input[value="' + i.value + '"]'), function (x) { return x !== i; });
                if (!elsewhere) { i.checked = false; }
            }); }
        });
        box.querySelector('[data-idx-none]').hidden = states.length > 0;
        var seen = {}, fees = [];
        box.querySelectorAll('input[name="boards[]"]:checked').forEach(function (i) { if (!seen[i.value]) { seen[i.value] = 1; fees.push(+i.getAttribute('data-fee')); } });
        fees.sort(function (a, b) { return b - a; });
        var out = document.getElementById('idx-total');
        if (fees.length === 0) { out.textContent = 'No boards ticked yet.'; return; }
        var extra = fees.slice(1).reduce(function (a, b) { return a + b; }, 0);
        out.textContent = fees.length === 1 ? '1 board: included in your price.' : fees.length + ' boards: 1 included, plus ' + money(extra) + ' a month for the other ' + (fees.length - 1) + '.';
    }
    box.addEventListener('change', update); update();
})();
</script>
@endif

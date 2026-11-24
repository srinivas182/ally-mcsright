{{--
    LP1 / LV1 (after IDX7, SU3): the licence and the MLS boards, in two blocks.
    1. Where they hold a real estate licence: every state RightAlly works in, and the licence number for each ticked
       state (required; checked against the state's records when they continue).
    2. Their MLS boards, under each ticked state that has boards. Boards are optional: without one, the website is a
       professional landing page (no listings) until a board is added. One board is included in the price; each extra
       board adds its own monthly fee.
    Works without JavaScript (every state's panel is shown); the small script shows a state's panel once it is ticked
    and adds up the total.
--}}
@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $picked = array_map('strval', (array) old('boards', $prefillBoards ?? []));
    $allStates = $signupStates ?? [];
    $byState = [];
    foreach ($idxBoards ?? [] as $b) {
        foreach ((array) ($b['states'] ?? [$b['state']]) as $st) {
            $byState[$st][] = $b;
        }
    }
    $savedLicences = collect((array) json_decode((string) ($draft->licences ?? '[]'), true))->keyBy('state');
    $numbers = (array) old('licence_numbers', $savedLicences->map(fn ($l) => (string) ($l['number'] ?? ''))->all());
    $licensed = array_map('strval', (array) old('licence_states', $savedLicences->keys()->all() ?: array_keys(array_filter($byState, fn ($list) => collect($list)->contains(fn ($b) => in_array($b['ulid'], $picked, true))))));
@endphp
@if (! empty($allStates))
<div id="idx-boards">
<fieldset id="idx-licence"><legend>Your real estate licence</legend>
    <p class="muted small">Tick every state you hold a licence in, and type the licence number. We check it in the state's public records before you pay. MLS boards can be added in states where your licence is active.</p>
    <div class="choices" role="group" aria-label="States where you hold a licence" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr))">
        @foreach ($allStates as $code => $stateName)
            <label><input type="checkbox" name="licence_states[]" data-idx-state value="{{ $code }}" @checked(in_array($code, $licensed, true))> {{ $stateName }}</label>
        @endforeach
    </div>
    @error('licence_states')<p class="error" role="alert">{{ $message }}</p>@enderror
    @foreach ($allStates as $code => $stateName)
        <div class="board-group" data-idx-group="{{ $code }}">
            <label>{{ $stateName }} licence number
                <input type="text" name="licence_numbers[{{ $code }}]" value="{{ $numbers[$code] ?? '' }}" maxlength="40" autocomplete="off" spellcheck="false" style="max-width:280px" @error('licence_numbers.'.$code) aria-invalid="true" @enderror>
            </label>
            @error('licence_numbers.'.$code)<p class="error" role="alert">{{ $message }}</p>@enderror
            <label class="small" style="display:flex;gap:8px;align-items:center;font-weight:400"><input type="checkbox" name="licence_new[{{ $code }}]" value="1" @checked(old('licence_new.'.$code))> My licence is new (issued in the last few days)</label>
        </div>
    @endforeach
</fieldset>

<fieldset id="idx-board-list"><legend>Your MLS boards (for listings on your website)</legend>
    <p class="muted small"><strong>One board is included in the price.</strong> Each extra board adds the fee shown, every month. No board yet? You can skip this: your website starts as a professional landing page, and home search and listings switch on when you add a board in Settings › MLS.</p>
    <p class="card small" data-idx-none>Tick a state above and its MLS boards appear here.</p>
    @foreach ($allStates as $code => $stateName)
        <div class="board-group" data-idx-group="{{ $code }}">
            <p class="board-group-title"><strong>{{ $stateName }}</strong></p>
            @if (! empty($byState[$code]))
                <div class="choices" role="group" aria-label="MLS boards in {{ $stateName }}">
                    @foreach ($byState[$code] as $b)
                        <label><input type="checkbox" name="boards[]" value="{{ $b['ulid'] }}" data-fee="{{ (int) $b['fee_cents'] }}" @checked(in_array($b['ulid'], $picked, true))>
                            <span>{{ $b['name'] }} @if ($b['region'])<span class="muted small">({{ $b['region'] }})</span>@endif<br>
                            <span class="muted small">{{ $usd((int) $b['fee_cents']) }} a month as an extra board. Approval usually takes {{ $b['approval'] }}.</span></span>
                        </label>
                    @endforeach
                </div>
            @else
                <p class="card small">We don't connect to an MLS board in {{ $stateName }} yet, so your website starts as a professional landing page about you, without listings. We add home search as soon as a board here is connected.</p>
            @endif
        </div>
    @endforeach
    <p class="card small" id="idx-total" role="status" aria-live="polite">No boards ticked yet.</p>
    <p class="muted small">Your board is not listed? Sign up without it, then choose "My board isn’t listed" in Settings › MLS and we will add it. After you pay, we prepare each board's paperwork for you to sign. Until the board approves, your website shows sample listings, clearly marked.</p>
</fieldset>
</div>
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
            if (!on) { g.querySelectorAll('input[name="boards[]"]').forEach(function (i) {
                var elsewhere = Array.prototype.some.call(box.querySelectorAll('[data-idx-group]:not([hidden]) input[value="' + i.value + '"]'), function (x) { return x !== i; });
                if (!elsewhere) { i.checked = false; }
            }); }
        });
        box.querySelector('[data-idx-none]').hidden = states.length > 0;
        box.querySelector('#idx-total').hidden = states.length === 0;
        var seen = {}, fees = [];
        box.querySelectorAll('input[name="boards[]"]:checked').forEach(function (i) { if (!seen[i.value]) { seen[i.value] = 1; fees.push(+i.getAttribute('data-fee')); } });
        fees.sort(function (a, b) { return b - a; });
        var out = document.getElementById('idx-total');
        if (fees.length === 0) { out.textContent = 'No board: your website starts as a landing page, without listings.'; return; }
        var extra = fees.slice(1).reduce(function (a, b) { return a + b; }, 0);
        out.textContent = fees.length === 1 ? '1 board: included in your price.' : fees.length + ' boards: 1 included, plus ' + money(extra) + ' a month for the other ' + (fees.length - 1) + '.';
    }
    box.addEventListener('change', update); update();
})();
</script>
@endif

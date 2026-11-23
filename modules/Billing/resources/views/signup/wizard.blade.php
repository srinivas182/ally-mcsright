{{--
    SU2: signup in four steps, each saved when Continue is pressed. Shared by brokerages (/start) and solo agents
    (/agents). Params: $type, $base, $step, $reached, $steps, plus what each step needs.
--}}
@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $solo = $type === 'solo';
    $v = fn ($k) => old($k, $prefill[$k] ?? '');
    $luxury = ['aurum', 'estate', 'ember', 'heritage', 'atrium'];
    $designs = collect($designs)->sortBy(fn ($d) => in_array($d['key'], $luxury, true) ? array_search($d['key'], $luxury, true) : 10)->values();
    $picked = old('template', $template ?? 'modern');
    if (! collect($designs ?? [])->contains('key', $picked)) { $picked = $designs[0]['key'] ?? 'modern'; } // LP1: a design of the right kind
    $previewName = $solo ? ($prefill['business'] ?? '') ?: ($prefill['name'] ?? '') : ($prefill['brokerage'] ?? '');
    $previewArea = ($marketArea ?? '') !== '' ? $marketArea : (string) ($signupStates[$prefill['state'] ?? ''] ?? '');
    // The designs' own heading fonts, so each card looks like the website it makes.
    $fontCss = collect($designs)->pluck('css')->flatMap(fn ($c) => explode('&', (string) $c))->filter(fn ($f) => str_starts_with($f, 'family='))->unique()->implode('&');
    $dark = fn (string $hex): bool => strlen(ltrim($hex, '#')) === 6 && (hexdec(substr(ltrim($hex, '#'), 0, 2)) * 299 + hexdec(substr(ltrim($hex, '#'), 2, 2)) * 587 + hexdec(substr(ltrim($hex, '#'), 4, 2)) * 114) / 1000 < 128;
@endphp
<h2 id="signup">{{ $solo ? 'Start now' : 'Sign up' }}</h2>
<ol class="stepper" aria-label="Signup steps">
    @foreach ($steps as $n => $label)
        <li class="{{ $n === $step ? 'now' : ($n < $step || $n <= $reached ? 'done' : '') }}" @if ($n === $step) aria-current="step" @endif>
            @if ($n !== $step && $n <= $reached)
                <a href="{{ $base }}?step={{ $n }}#signup"><span class="dot">{{ $n < $reached ? '✓' : $n }}</span> {{ $label }}</a>
            @else
                <span class="dot">{{ $n }}</span> {{ $label }}
            @endif
        </li>
    @endforeach
</ol>
<p class="muted small">Your answers are saved as you go. You can stop and carry on later from the same browser, or from the link we email you.</p>

@if ($errors->any())
    <div class="errors" role="alert"><strong>Please check these:</strong><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

@if ($step === 1)
    <form method="post" action="{{ $base }}/step/about" novalidate>
        @csrf
        <div class="hp" aria-hidden="true"><label>Fax<input type="text" name="fax" tabindex="-1" autocomplete="off"></label></div>
        @if (! empty($prefill['check']))<input type="hidden" name="check" value="{{ $prefill['check'] }}">@endif
        <fieldset><legend>1. {{ $solo ? 'About you' : 'You and your brokerage' }}</legend>
            <div class="two">
                @if ($solo)
                    <label>Your name<input type="text" name="name" required maxlength="120" value="{{ $v('name') }}" autocomplete="name"></label>
                    <label>Your email (your sign-in link goes here)<input type="email" name="email" required maxlength="190" value="{{ $v('email') }}" autocomplete="email"></label>
                    <label>Your phone (shown on your website)<input type="tel" name="phone" required maxlength="30" value="{{ $v('phone') }}" autocomplete="tel" inputmode="tel"></label>
                    @error('phone')<p class="error" role="alert">{{ $message }}</p>@enderror
                    <label>Team or business name (optional)<input type="text" name="business" maxlength="150" value="{{ $v('business') }}" autocomplete="organization"></label>
                    @include('billing::signup.state', ['value' => $v('state')])
                    @include('billing::signup.address', ['value' => $v('slug'), 'from' => 'name', 'hint' => 'Letters, numbers and dashes, for example jane-smith-homes.'])
                @else
                    <label>Brokerage name<input type="text" name="brokerage" required maxlength="150" value="{{ $v('brokerage') }}" autocomplete="organization"></label>
                    <label>Office phone (shown on your website)<input type="tel" name="phone" required maxlength="30" value="{{ $v('phone') }}" autocomplete="tel" inputmode="tel"></label>
                    @error('phone')<p class="error" role="alert">{{ $message }}</p>@enderror
                    @include('billing::signup.state', ['value' => $v('state')])
                    @include('billing::signup.address', ['value' => $v('slug'), 'from' => 'brokerage', 'hint' => 'Letters, numbers and dashes. You can add your own domain later.'])
                    <label>Number of agents (optional)<input type="number" name="agents" min="1" max="20000" value="{{ $v('agents') }}" inputmode="numeric"></label>
                    <label>Owner's name<input type="text" name="owner_name" required maxlength="120" value="{{ $v('owner_name') }}" autocomplete="name"></label>
                    <label>Owner's email (the sign-in link goes here)<input type="email" name="owner_email" required maxlength="190" value="{{ $v('owner_email') }}" autocomplete="email"></label>
                @endif
            </div>
        </fieldset>
        @include('billing::signup.boards', ['number' => ''])
        <div class="step-actions"><button class="btn" type="submit">Continue to {{ mb_strtolower($steps[2]) }}</button></div>
    </form>

@elseif ($step === 2)
    <form method="post" action="{{ $base }}/step/package" novalidate>
        @csrf
        @include('billing::signup.plans', ['number' => 2, 'label' => $solo ? 'Plan' : 'Package'])
        <div class="step-actions">
            <a class="btn ghost" href="{{ $base }}?step=1#signup">Back</a>
            <button class="btn" type="submit">Continue to {{ mb_strtolower($steps[3]) }}</button>
        </div>
    </form>

@elseif ($step === 3)
    <form method="post" action="{{ $base }}/step/design" novalidate>
        @csrf
        @if ($fontCss !== '')<link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $fontCss }}&amp;display=swap">@endif
        <fieldset><legend>3. Choose your website design</legend>
            @if ($landingOnly ?? false)
                <p class="muted small" style="margin-top:0">You chose no MLS board, so your website starts as a professional landing page: who you are, how you help, the areas you serve, a home value request and your contact details. It goes live in this design the moment you pay. When you add an MLS board, you can switch to a full website with home search and listings.</p>
            @else
                <p class="muted small" style="margin-top:0">Your website goes live in this design the moment you pay, at your RightAlly address, with your name and sample listings until your MLS board is approved. You can change the design any time later.</p>
            @endif
            <div class="designs" role="radiogroup" aria-label="Website design">
                @foreach ($designs as $d)
                    <label class="design">
                        <input type="radio" name="template" value="{{ $d['key'] }}" @checked($picked === $d['key'])>
                        @php($ink = $dark($d['theme']) ? '#f5f1e8' : '#111827')
                        @php($lay = explode(' ', $d['layout']))
                        <span class="mini {{ collect($lay)->map(fn ($c) => 'm-'.$c)->implode(' ') }}" aria-hidden="true" style="background:{{ $d['theme'] }};color:{{ $ink }};--photo:url('{{ $d['hero'] }}');--brand:{{ $d['brand']['primary'] ?? '#1d4ed8' }}">
                            <span class="mini-nav"><b style="font-family:'{{ $d['font'] }}',serif">{{ $previewName !== '' ? mb_strimwidth($previewName, 0, 24, '…') : 'Your Brokerage' }}</b><i></i><i></i><i></i></span>
                            <span class="mini-hero">
                                <span class="mini-copy">
                                    @if ($d['landing'])
                                        <span class="mini-title" style="font-family:'{{ $d['font'] }}',serif">{{ $previewName !== '' ? mb_strimwidth($previewName, 0, 30, '…') : 'Your Brokerage' }}</span>
                                        <span class="mini-buttons"><span></span><span></span></span>
                                    @else
                                        <span class="mini-title" style="font-family:'{{ $d['font'] }}',serif">Find your place in {{ $previewArea !== '' ? $previewArea : 'your city' }}</span>
                                        <span class="mini-search"><span></span><span></span></span>
                                    @endif
                                </span>
                            </span>
                            @if ($d['landing'])
                                <span class="mini-services"><i></i><i></i><i></i><i></i></span>
                            @else
                                <span class="mini-homes">@foreach ($d['homes'] as $h)<i style="background-image:url('{{ $h }}')"></i>@endforeach</span>
                            @endif
                        </span>
                        <span class="design-name">{{ $d['name'] }}@if (in_array($d['key'], $luxury, true)) <span class="tag">Luxury</span>@endif</span>
                        <span class="muted small">{{ $d['description'] }}</span>
                        <a class="small" href="{{ \Modules\Sites\Content\DemoSite::previewUrl($d['key'], $previewName, $previewArea, $marketState ?? null) ?? '/start/designs/'.$d['key'].'?name='.urlencode($previewName).'&area='.urlencode($previewArea) }}" target="_blank" rel="noopener">Preview {{ $d['name'] }} (opens a new tab)</a>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="step-actions">
            <a class="btn ghost" href="{{ $base }}?step=2#signup">Back</a>
            <button class="btn" type="submit">Continue to {{ mb_strtolower($steps[4]) }}</button>
        </div>
    </form>

@else
    <form method="post" action="{{ $base }}" novalidate data-pay>
        @csrf
        <div class="hp" aria-hidden="true"><label>Fax<input type="text" name="fax" tabindex="-1" autocomplete="off"></label></div>
        <fieldset><legend>4. Review and pay</legend>
            <dl class="review">
                <div><dt>{{ $solo ? 'You' : 'Brokerage' }}</dt><dd>{{ $draft->brokerage }}<br><span class="muted small">{{ $draft->owner_name }} · {{ $draft->owner_email }}{{ $draft->state ? ' · '.($signupStates[$draft->state] ?? $draft->state) : '' }}</span></dd><a href="{{ $base }}?step=1#signup">Change</a></div>
                <div><dt>Address</dt><dd>{{ \Modules\Billing\Signup\Signups::websiteHost((string) $draft->slug) }}</dd><a href="{{ $base }}?step=1#signup">Change</a></div>
                <div><dt>Licence</dt><dd>{{ collect((array) json_decode((string) ($draft->licences ?? '[]'), true))->map(fn ($l) => ($signupStates[$l['state']] ?? $l['state']).' '.$l['number'].(($l['status'] ?? '') === 'active' ? ' (confirmed)' : ''))->implode(', ') ?: 'Not given yet' }}</dd><a href="{{ $base }}?step=1#signup">Change</a></div>
                <div><dt>MLS boards</dt><dd>@if ($review['boards'] === []) None: your website starts as a landing page, without listings. Add a board any time in Settings › MLS. @else {{ implode(', ', array_column($review['boards'], 'name')) }} @endif</dd><a href="{{ $base }}?step=1#signup">Change</a></div>
                <div><dt>{{ $solo ? 'Plan' : 'Package' }}</dt><dd>{{ $review['package'] }}: {{ $usd($review['price_cents']) }} a {{ $review['interval'] }}</dd><a href="{{ $base }}?step=2#signup">Change</a></div>
                <div><dt>Website design</dt><dd>{{ $review['boards'] === [] ? 'Landing page: ' : '' }}{{ $review['design'] }}</dd><a href="{{ $base }}?step=3#signup">Change</a></div>
            </dl>
            <p class="card pay-today">You pay today: {{ $usd($review['price_cents']) }}@if ($review['extra_cents'] > 0) + {{ $usd($review['extra_cents']) }} a month for {{ count($review['boards']) - 1 }} extra MLS board{{ count($review['boards']) > 2 ? 's' : '' }}@endif. Taxes may apply.</p>
            <label class="check"><input type="checkbox" name="terms" value="1" @checked(old('terms'))> <span>{{ $solo ? 'I agree to pay the price shown each month or year until I cancel.' : 'I can sign up this brokerage, and I agree to pay the price shown each month or year until I cancel.' }} Read the <a href="https://{{ ((array) config('marketing.hosts'))[0] ?? 'rightally.app' }}/terms" target="_blank" rel="noopener">terms of use</a>.</span></label>
            @if (! $solo)@include('core::bot-check', ['bot' => app(\Modules\Core\Security\BotProtection::class)->widget()])@endif
            <div class="step-actions">
                <a class="btn ghost" href="{{ $base }}?step=3#signup">Back</a>
                <button class="btn" type="submit">Continue to payment</button>
            </div>
            <p class="muted small">You pay on our payment provider's secure page. Your RightAlly and your website are created the moment the payment goes through.</p>
        </fieldset>
    </form>
    {{-- QW1: while the secure payment page loads, say so, so nobody presses twice or thinks it stopped. --}}
    <div class="busy" data-pay-busy hidden role="status" aria-live="polite">
        <div class="busy-card"><span class="spinner" aria-hidden="true"></span><p><strong>Taking you to secure payment…</strong><br>This takes a few seconds. After you pay, you come straight back here while we set up {{ $draft->brokerage }}.</p></div>
    </div>
    <script nonce="{{ Vite::cspNonce() }}">
    (function(){var f=document.querySelector('[data-pay]'),b=document.querySelector('[data-pay-busy]');if(!f||!b)return;
    f.addEventListener('submit',function(){if(f.terms&&!f.terms.checked)return;b.hidden=false;var s=f.querySelector('[type=submit]');if(s){s.disabled=true;}});
    window.addEventListener('pageshow',function(e){if(e.persisted){b.hidden=true;var s=f.querySelector('[type=submit]');if(s)s.disabled=false;}});})();
    </script>
@endif

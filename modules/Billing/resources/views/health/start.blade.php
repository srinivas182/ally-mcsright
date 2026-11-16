@php
    $faq = [
        ['What is the RightAlly Brokerage Health Check?', 'A free three-minute check for real estate brokerages. You answer a few questions and list the software you pay for. You get a report with a score for each area, what to fix first, a check of your website for Google and AI answers, a savings comparison and a preview of RightAlly in your colours.'],
        ['Is it free? Do I have to talk to sales?', 'It is free, with no card and no sales call. Your report opens as soon as you finish. We only call you if you tick the box asking us to.'],
        ['What does the website check look at?', 'Only your public home page and three small files (robots.txt, sitemap.xml and llms.txt). It checks security, phones, search descriptions, real estate business markup, an FAQ that AI assistants can quote, home search, Spanish pages, the Equal Housing Opportunity notice, a privacy policy and contact details. It does not download pictures or other pages.'],
        ['How is the savings figure worked out?', 'From the prices you enter. Tools RightAlly fully replaces come off; tools it only partly covers, and things like MLS dues and paid leads, stay in the total, so the figure is never more than RightAlly can deliver. If RightAlly would cost you more, the report says so.'],
        ['Who sees my answers?', 'You, through a private link we email you, and the RightAlly team. We do not sell or share them. You can delete your report at any time, and it is deleted automatically after 180 days.'],
    ];
    $jsonld = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'Organization', '@id' => 'https://'.config('core.hq_host').'/#org', 'name' => 'RightAlly', 'url' => 'https://'.config('core.hq_host').'/'],
        ['@type' => 'WebPage', '@id' => $canonical, 'url' => $canonical, 'name' => 'Free Brokerage Health Check', 'publisher' => ['@id' => 'https://'.config('core.hq_host').'/#org'],
            'description' => 'A free three-minute check of a real estate brokerage: tools and cost, speed to leads, file compliance, agent payouts, past clients and website readiness for Google and AI answers.'],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
    ]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $old = fn ($k, $d = null) => old($k, $d);
@endphp
@extends('billing::health.layout', ['title' => 'Free Brokerage Health Check | RightAlly', 'indexable' => true, 'canonical' => $canonical, 'jsonld' => $jsonld,
    'description' => 'Free three-minute health check for real estate brokerages: tool costs, speed to leads, file compliance, agent payouts and a website check for Google and AI answers. No sales call.'])

@section('main')
<section class="hero"><div class="wrap">
    <h1>Free Brokerage Health Check</h1>
    <p class="lead">Three minutes. No card, no sales call. See what your tools really cost per agent, what to fix first, how your website does on Google and AI answers, and what RightAlly would look like in your colours.</p>
    @if (session('deleted'))<p class="card" role="status">Your report has been deleted.</p>@endif
    <ul class="promises">
        <li><strong>Plain-English scores</strong>Six areas, each with one thing to fix.</li>
        <li><strong>Honest savings</strong>Only what RightAlly really replaces comes off.</li>
        <li><strong>Website check</strong>Ready for Google and AI answers?</li>
        <li><strong>Your brand</strong>See RightAlly in your colours, at once.</li>
    </ul>
</div></section>

<div class="wrap">
    @if ($errors->any())
        <div class="errors" role="alert"><strong>Please check these:</strong><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="post" action="/health-check" enctype="multipart/form-data" novalidate>
        @csrf
        <div class="hp" aria-hidden="true"><label>Fax<input type="text" name="fax" tabindex="-1" autocomplete="off"></label></div>

        <fieldset><legend>1. About your brokerage</legend>
            <div class="two">
                <label>Brokerage name<input type="text" name="brokerage" required maxlength="160" value="{{ $old('brokerage') }}" autocomplete="organization"></label>
                <label>Number of agents<input type="number" name="agents" required min="1" max="20000" inputmode="numeric" value="{{ $old('agents') }}"></label>
                <label>Your name<input type="text" name="contact_name" required maxlength="120" value="{{ $old('contact_name') }}" autocomplete="name"></label>
                <label>Your email (we send the report here)<input type="email" name="email" required maxlength="190" value="{{ $old('email') }}" autocomplete="email"></label>
                <label>State (two letters, optional)<input type="text" name="state" maxlength="2" value="{{ $old('state') }}" autocomplete="address-level1"></label>
            </div>
        </fieldset>

        <fieldset><legend>2. How you work today</legend>
            @foreach ($questions as $key => $q)
                <p id="q-{{ $key }}"><strong>{{ $q['label'] }}</strong></p>
                <div class="choices" role="radiogroup" aria-labelledby="q-{{ $key }}">
                    @foreach ($q['answers'] as $value => [$label, $score])
                        <label><input type="radio" name="answers[{{ $key }}]" value="{{ $value }}" @checked($old('answers.'.$key) === $value) required> {{ $label }}</label>
                    @endforeach
                </div>
            @endforeach
            <p id="q-who"><strong>Who pays for agents' software?</strong></p>
            <div class="choices" role="radiogroup" aria-labelledby="q-who">
                @foreach ($whoPays as $value => $label)
                    <label><input type="radio" name="who_pays" value="{{ $value }}" @checked($old('who_pays', 'brokerage') === $value)> {{ $label }}</label>
                @endforeach
            </div>
        </fieldset>

        <fieldset><legend>3. The software you pay for</legend>
            <p class="muted small">Add what you pay each month. Tick "per agent" if the price is for each agent. Leave rows empty if you don't need them.</p>
            @for ($i = 0; $i < 8; $i++)
                <div class="toolrow">
                    <label>Tool {{ $i + 1 }}
                        <select name="tools[{{ $i }}][key]">
                            <option value="">Choose…</option>
                            @foreach ($tools as $key => $t)<option value="{{ $key }}" @selected($old('tools.'.$i.'.key') === $key)>{{ $t['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label>Monthly cost ($)<input type="number" name="tools[{{ $i }}][cost]" min="0" step="0.01" inputmode="decimal" value="{{ $old('tools.'.$i.'.cost') }}"></label>
                    <label class="check"><input type="checkbox" name="tools[{{ $i }}][per_agent]" value="1" @checked($old('tools.'.$i.'.per_agent'))> per agent</label>
                </div>
            @endfor
            <label>If you chose "Something else", what is it? (optional)<input type="text" name="other_name" maxlength="60" value="{{ $old('other_name') }}"></label>
        </fieldset>

        <fieldset><legend>4. Your website and brand (optional)</legend>
            <label>Website address<input type="text" name="website_url" maxlength="255" placeholder="yourbrokerage.com" value="{{ $old('website_url') }}" inputmode="url"></label>
            <label class="check"><input type="checkbox" name="website_ok" value="1" @checked($old('website_ok'))> I manage this website, and RightAlly may check its public home page.</label>
            <div class="two">
                <label>Brand colour<input type="color" name="brand_color" value="{{ $old('brand_color', '#4338ca') }}"></label>
                <label>Logo (PNG, JPG or WebP, up to 1 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
            </div>
        </fieldset>

        <fieldset><legend>5. Your report</legend>
            <label class="check"><input type="checkbox" name="contact_ok" value="1" @checked($old('contact_ok'))> I'd like someone from RightAlly to call or email me about my report.</label>
            <p class="muted small">Without this tick we only email you the link to your report.</p>
            <button class="btn" type="submit">See my report</button>
        </fieldset>
    </form>

    <section class="faq" aria-labelledby="faq">
        <h2 id="faq">Questions</h2>
        @foreach ($faq as [$q, $a])<details><summary>{{ $q }}</summary><p>{{ $a }}</p></details>@endforeach
    </section>
</div>
@endsection

{{--
    AG7: the top of an agent's page, built to be shared from a phone (Instagram, Facebook, a text): their photo, name
    and brokerage, one line on what they do, the areas they know, and Call, Text and Email in reach of a thumb. On a
    phone the three stay docked at the bottom of the screen. Numbers that are not there yet are left out, never
    shown as zero; the brokerage's name and the licence are always shown.
--}}
@php
    $a = $block['agent'];
    $first = $a['first_name'] !== '' ? $a['first_name'] : $a['name'];
    $tel = preg_replace('/[^0-9+]/', '', (string) $a['phone']);
    $initials = mb_strtoupper(collect(explode(' ', $a['name']))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode(''));
    $tagline = $a['tagline'] !== '' ? $a['tagline']
        : ($a['areas'] !== [] ? 'Helping people buy and sell homes in '.collect($a['areas'])->take(3)->join(', ', ' and ').'.' : 'Helping people buy and sell homes, from the first look to the keys.');
    $facts = array_values(array_filter([
        $a['active'] > 0 ? ['value' => (string) $a['active'], 'label' => $a['active'] === 1 ? 'home for sale now' : 'homes for sale now'] : null,
        $a['rating'] !== null && $a['reviews'] > 0 ? ['value' => number_format($a['rating'], 1).'★', 'label' => 'from '.$a['reviews'].' client '.($a['reviews'] === 1 ? 'review' : 'reviews')] : null,
        $a['languages'] !== [] && $a['languages'] !== ['English'] ? ['value' => implode(', ', array_slice($a['languages'], 0, 3)), 'label' => 'spoken'] : null,
    ]));
    $phoneIcon = '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.6a1 1 0 0 1-.25 1z"/></svg>';
    $textIcon = '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2zm3 6v2h2v-2H7zm4 0v2h2v-2h-2zm4 0v2h2v-2h-2z"/></svg>';
    // No photo yet: a photo of their area with their initials, rather than an empty frame.
    $place = $a['photo'] ? null : \Modules\Sites\Content\DemoPhotos::url(\Modules\Sites\Content\StarterSite::cityPhoto((array) $site->info), 'card');
    $mailIcon = '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm8 7 8-5H4l8 5zm0 2L4 8v10h16V8l-8 5z"/></svg>';
@endphp
<section class="agent-hero" id="{{ $block['id'] }}" aria-labelledby="{{ $block['id'] }}-name">
    <div class="wrap agent-hero-grid">
        <figure @class(['agent-portrait', 'agent-portrait-place' => ! $a['photo']])>
            @if ($a['photo'])
                <img src="{{ $a['photo'] }}" alt="{{ $a['name'] }}" width="640" height="800" fetchpriority="high">
            @else
                @if ($place)<img class="agent-place" src="{{ $place }}" alt="" width="640" height="800" fetchpriority="high">@endif
                <span class="agent-monogram" aria-hidden="true">{{ $initials }}</span>
            @endif
        </figure>
        <div class="agent-intro">
            <p class="agent-role">Real estate agent with <strong>{{ $a['brokerage'] }}</strong></p>
            <h1 class="agent-name" id="{{ $block['id'] }}-name">{{ $a['name'] }}</h1>
            <p class="agent-tagline">{{ $tagline }}</p>
            @if ($a['areas'] !== [])
                <ul class="agent-areas" aria-label="Areas {{ $first }} knows">
                    @foreach (array_slice($a['areas'], 0, 6) as $area)<li>{{ $area }}</li>@endforeach
                </ul>
            @endif
            <div class="agent-actions">
                @if ($tel !== '')
                    <a class="btn btn-primary agent-act" href="tel:{{ $tel }}">{!! $phoneIcon !!}<span>Call {{ $first }}</span></a>
                    <a class="btn btn-ghost agent-act" href="sms:{{ $tel }}">{!! $textIcon !!}<span>Text</span></a>
                @endif
                @if ($a['email'] !== '')
                    <a class="btn {{ $tel === '' ? 'btn-primary' : 'btn-ghost' }} agent-act" href="mailto:{{ $a['email'] }}">{!! $mailIcon !!}<span>Email</span></a>
                @endif
            </div>
            @if ($p['home_value'] || $p['search'])
                <p class="agent-more">
                    @if ($p['home_value'])<a href="/home-value">What is my home worth?</a>@endif
                    @if ($p['search'])<a href="/homes">Search homes for sale</a>@endif
                </p>
            @endif
            @if ($facts !== [])
                <dl class="agent-facts">
                    @foreach ($facts as $f)<div><dt>{{ $f['label'] }}</dt><dd>{{ $f['value'] }}</dd></div>@endforeach
                </dl>
            @endif
            @if ($a['licence'] !== null)
                <p class="agent-licence">{{ trim(($a['licence']['state'] ?? '').' real estate licence') }} {{ $a['licence']['number'] }}</p>
            @endif
        </div>
    </div>
    @if ($tel !== '' || $a['email'] !== '')
        <nav class="agent-dock" aria-label="Contact {{ $first }}">
            @if ($tel !== '')
                <a href="tel:{{ $tel }}" class="agent-dock-main">{!! $phoneIcon !!}<span>Call</span></a>
                <a href="sms:{{ $tel }}">{!! $textIcon !!}<span>Text</span></a>
            @endif
            @if ($a['email'] !== '')<a href="mailto:{{ $a['email'] }}" @class(['agent-dock-main' => $tel === ''])>{!! $mailIcon !!}<span>Email</span></a>@endif
        </nav>
    @endif
</section>

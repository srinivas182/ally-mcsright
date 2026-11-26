{{--
    AG8: the opening of the brokerage's "Join us" page, for agents thinking about moving: a photo, the promise, a few
    reasons, the button to join (with no agent's link they join with the brokerage itself), and the plans new agents
    can pick, read live from Commissions › Plans so the page never shows an old split.
--}}
@php
    $img = app(\Modules\Sites\Content\MediaLibrary::class)->url($p['image'] ?? null, 'hero');
    $points = array_values(array_filter($p['points'], fn (array $it): bool => $it['text'] !== ''));
    $check = '<svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M9.5 16.2 5.3 12l-1.4 1.4 5.6 5.6 11-11-1.4-1.4z"/></svg>';
    $plans = $block['plans'] ?? [];
@endphp
<section class="lp-hero recruit" id="{{ $block['id'] }}" aria-labelledby="{{ $block['id'] }}-title">
    @if ($img)<img class="lp-hero-bg" src="{{ $img }}" alt="" width="1600" height="900" fetchpriority="high">@endif
    <div @class(['wrap', 'lp-hero-grid' => $plans !== [], 'recruit-solo' => $plans === []])>
        <div class="lp-copy">
            <p class="lp-agent recruit-kicker">Join {{ $site->displayName() }}</p>
            <h1 class="lp-title" id="{{ $block['id'] }}-title">{{ $p['headline'] }}</h1>
            @if ($p['body'] !== '')<p class="lp-intro">{{ $p['body'] }}</p>@endif
            @if ($points !== [])
                <ul class="lp-points">
                    @foreach ($points as $pt)<li>{!! $check !!}<span>{{ $pt['text'] }}</span></li>@endforeach
                </ul>
            @endif
            <p class="recruit-actions">
                @if ($p['button'] !== '')<a class="btn btn-primary recruit-join" href="{{ $p['link'] }}">{{ $p['button'] }}</a>@endif
                <a class="btn recruit-talk" href="/contact">Talk to our broker</a>
            </p>
            <p class="lp-small recruit-small">Joining is online: check your licence, pick how you are paid, and start.</p>
        </div>
        @if ($plans !== [])
            <div class="recruit-plans" aria-label="How you can be paid">
                <p class="recruit-plans-title">How you can be paid</p>
                @foreach ($plans as $plan)
                    <article class="recruit-plan">
                        <h2>{{ $plan['name'] }}</h2>
                        @if (! empty($plan['description']))<p class="recruit-plan-note">{{ $plan['description'] }}</p>@endif
                        <ul>
                            @foreach (array_slice($plan['lines'], 0, 4) as $line)<li>{!! $check !!}<span>{{ $line }}</span></li>@endforeach
                        </ul>
                    </article>
                @endforeach
                <a class="btn btn-primary recruit-plans-join" href="{{ $p['link'] }}">{{ $p['button'] !== '' ? $p['button'] : 'Join us' }}</a>
            </div>
        @endif
    </div>
</section>

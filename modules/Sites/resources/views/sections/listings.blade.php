@if (! empty($block['listings']))
<section class="section" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <ul class="cards">
            @foreach ($block['listings'] as $l)
                <li class="card">
                    <article>
                        @if ($l['url'])<a href="{{ $l['url'] }}" class="card-link" aria-label="{{ $l['address'] ?? 'Home' }}"></a>@endif
                        <div class="card-media">
                            @if ($l['photo'])<img src="{{ $l['photo'] }}" alt="{{ $l['address'] ?? 'Home for sale' }}" loading="lazy" decoding="async" width="800" height="600">@else<div class="card-placeholder" aria-hidden="true"></div>@endif
                            @if ($l['reduced'])<span class="tag">Price reduced</span>@elseif ($l['status'] !== 'Active')<span class="tag">{{ $l['status'] }}</span>@endif
                        </div>
                        <div class="card-body">
                            <p class="price">{{ $l['price'] !== null ? '$'.number_format($l['price']) : '' }}</p>
                            <p class="facts">{{ $l['beds'] ?? '—' }} bd · {{ ($l['baths_full'] ?? 0) + (($l['baths_half'] ?? 0) > 0 ? 0.5 : 0) ?: '—' }} ba @if ($l['sqft'])· {{ number_format($l['sqft']) }} sq ft @endif</p>
                            <p class="addr">{{ collect([$l['address'], $l['city'], $l['state']])->filter()->implode(', ') }}</p>
                            <p class="attrib">{{ $l['attribution'] }}</p>
                        </div>
                    </article>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@endif

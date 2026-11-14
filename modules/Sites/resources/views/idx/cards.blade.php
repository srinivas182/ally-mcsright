@foreach ($listings as $l)
    <li class="card" data-ulid="{{ $l['ulid'] }}">
        <article>
            <a href="{{ \Modules\Sites\Rendering\Urls::listing($l['ulid'], \Modules\Listings\Search\ListingSearch::label($l)) }}" class="card-link" aria-label="{{ ($l['address'] ?? 'Home').', '.($l['city'] ?? '') }}{{ $l['price'] !== null ? ', $'.number_format($l['price']) : '' }}"></a>
            <div class="card-media">
                @if ($l['photo'])<img src="{{ $l['photo'] }}" alt="{{ $l['address'] ?? 'Home for sale' }}{{ $l['city'] ? ', '.$l['city'] : '' }}" loading="lazy" decoding="async" width="800" height="600">@else<div class="card-placeholder" aria-hidden="true"></div>@endif
                @if ($l['reduced'])<span class="tag">Price reduced</span>@elseif ($l['status'] !== 'Active')<span class="tag">{{ $l['status'] }}</span>@endif
            </div>
            <div class="card-body">
                <p class="price">{{ $l['price'] !== null ? '$'.number_format($l['price']) : 'Price on request' }}</p>
                <p class="facts">{{ $l['beds'] ?? '—' }} bd · {{ ($l['baths_full'] ?? 0) + (($l['baths_half'] ?? 0) > 0 ? 0.5 : 0) ?: '—' }} ba @if ($l['sqft'])· {{ number_format($l['sqft']) }} sq ft @endif</p>
                <p class="addr">{{ collect([$l['address'], $l['city'], $l['state']])->filter()->implode(', ') }}</p>
                <p class="attrib">{{ $l['attribution'] }}</p>
            </div>
        </article>
    </li>
@endforeach

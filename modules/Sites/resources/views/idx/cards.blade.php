@foreach ($listings as $l)
    <li class="card" data-ulid="{{ $l['ulid'] }}">
        <article>
            <a href="{{ \Modules\Sites\Rendering\Urls::listing($l['ulid'], \Modules\Listings\Search\ListingSearch::label($l)) }}" class="card-link" aria-label="{{ ($l['address'] ?? 'Home').', '.($l['city'] ?? '') }}{{ $l['price'] !== null ? ', $'.number_format($l['price']) : '' }}"></a>
            <div class="card-media">
                @if ($l['photo'])<img src="{{ $l['photo'] }}" alt="{{ $l['address'] ?? 'Home for sale' }}{{ $l['city'] ? ', '.$l['city'] : '' }}" loading="lazy" decoding="async" width="800" height="600">@else<div class="card-placeholder" aria-hidden="true"></div>@endif
                <button type="button" class="fav" data-fav="{{ $l['ulid'] }}" aria-pressed="false" aria-label="Save {{ $l['address'] ?? 'this home' }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.5-9.2C1 8.3 3.2 5 6.6 5c2 0 3.4 1.1 4.4 2.5C12 6.1 13.4 5 15.4 5 18.8 5 21 8.3 19.5 11.8 17.5 16.4 12 21 12 21z"/></svg></button>
                <button type="button" class="cmp" data-cmp="{{ $l['ulid'] }}" aria-pressed="false">Compare</button>
                @if ($l['sample'] ?? false)<span class="tag sample">Sample listing</span>@elseif ($l['reduced'])<span class="tag">Price reduced</span>@elseif ($l['status'] !== 'Active')<span class="tag">{{ $l['status'] }}</span>@endif
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

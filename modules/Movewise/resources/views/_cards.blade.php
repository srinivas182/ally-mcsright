<ul class="grid">
@foreach ($homes as $h)
    <li class="card">
        <a href="{{ $h['href'] }}"><img src="{{ $h['photo'] ?? '' }}" alt="{{ $h['label'] }}" loading="lazy" width="400" height="300"></a>
        <div class="body">
            <span class="price">{{ isset($h['price']) ? '$'.number_format((int) $h['price']) : $t('listing.price_on_request') }}</span>
            <span>{{ implode(' · ', array_filter([isset($h['beds']) ? $t('card.bd', ['n' => $h['beds']]) : null, isset($h['baths_full']) ? ($h['baths_full'] + 0.5 * (int) ($h['baths_half'] ?? 0)).' '.$t('card.ba') : null, isset($h['sqft']) ? $t('listing.sqft', ['n' => number_format((int) $h['sqft'])]) : null])) }}</span>
            <a href="{{ $h['href'] }}">{{ $h['label'] }}</a>
            <span class="attr">{{ $h['attribution'] ?? '' }}</span>
            <button class="save" type="button" data-save="{{ $h['ulid'] }}" aria-pressed="false" hidden>{{ $t('save') }}</button>
        </div>
    </li>
@endforeach
</ul>

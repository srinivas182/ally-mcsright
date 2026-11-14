@if (($p['items'] ?? []) !== [])
<section class="section section-alt" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <ul class="quotes">
            @foreach ($p['items'] as $q)
                <li>
                    <figure>
                        @if ($q['rating'] > 0)<p class="stars" aria-label="{{ $q['rating'] }} out of 5 stars">{{ str_repeat('★', $q['rating']) }}<span aria-hidden="true">{{ str_repeat('☆', 5 - $q['rating']) }}</span></p>@endif
                        <blockquote>“{{ $q['quote'] }}”</blockquote>
                        <figcaption><strong>{{ $q['name'] }}</strong>@if ($q['detail'] !== '') · {{ $q['detail'] }}@endif</figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@endif

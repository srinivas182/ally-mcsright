<section class="section" id="{{ $block['id'] }}">
    <div class="wrap narrow">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <div class="faq">
            @foreach ($p['items'] as $item)
                <details @if ($loop->first) open @endif><summary>{{ $item['q'] }}</summary><p>{{ $item['a'] }}</p></details>
            @endforeach
        </div>
    </div>
</section>

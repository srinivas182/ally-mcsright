<section class="section section-alt" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <div class="features">
            @foreach ($p['items'] as $item)
                <div class="feature"><h3>{{ $item['title'] }}</h3><p>{{ $item['body'] }}</p></div>
            @endforeach
        </div>
    </div>
</section>

<section class="section cta" id="{{ $block['id'] }}">
    <div class="wrap cta-inner">
        <div>
            <h2>{{ $p['title'] }}</h2>
            @if ($p['body'] !== '')<p>{{ $p['body'] }}</p>@endif
        </div>
        @if ($p['button'] !== '')<a class="btn btn-primary" href="{{ $p['link'] }}">{{ $p['button'] }}</a>@endif
    </div>
</section>

@php($media = app(\Modules\Sites\Content\MediaLibrary::class))
@php($img = $media->url($p['image'] ?? null, 'hero'))
{{-- TP2: a photo beside a heading, text and a button; the photo can sit on either side. --}}
<section class="section split split-{{ $p['side'] ?? 'left' }}" id="{{ $block['id'] }}">
    <div class="wrap split-inner">
        @if ($img)
            <figure class="split-photo"><img src="{{ $img }}" alt="{{ $media->alt($p['image'] ?? null, $p['title']) }}" loading="lazy" decoding="async" width="1600" height="1100"></figure>
        @endif
        <div class="split-text">
            <h2 class="section-title">{{ $p['title'] }}</h2>
            @foreach (preg_split('/\n{2,}/', (string) $p['body']) ?: [] as $para)
                @if (trim($para) !== '')<p>{{ $para }}</p>@endif
            @endforeach
            @if ($p['button'] !== '')<p><a class="btn btn-primary" href="{{ $p['link'] }}">{{ $p['button'] }}</a></p>@endif
        </div>
    </div>
</section>

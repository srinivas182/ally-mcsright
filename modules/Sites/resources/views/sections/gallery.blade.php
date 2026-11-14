@php($media = app(\Modules\Sites\Content\MediaLibrary::class))
@if (($p['images'] ?? []) !== [])
<section class="section" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <ul class="gallery-grid">
            @foreach ($p['images'] as $img)
                @if ($src = $media->url($img, 'card'))
                    <li><img src="{{ $src }}" alt="{{ $media->alt($img, $p['title']) }}" loading="lazy" decoding="async" width="800" height="600"></li>
                @endif
            @endforeach
        </ul>
    </div>
</section>
@endif

@php($img = app(\Modules\Sites\Content\MediaLibrary::class)->url($p['image'] ?? null, 'hero'))
{{-- TP2: a full-width photo with a line over it and one button. --}}
@php($small = app(\Modules\Sites\Content\MediaLibrary::class)->url($p['image'] ?? null, 'card'))
<section class="banner" id="{{ $block['id'] }}" @if ($img) style="--banner: url('{{ $img }}'); --banner-sm: url('{{ $small }}')" @endif>
    <div class="wrap banner-inner">
        <h2>{{ $p['title'] }}</h2>
        @if ($p['body'] !== '')<p>{{ $p['body'] }}</p>@endif
        @if ($p['button'] !== '')<a class="btn btn-primary" href="{{ $p['link'] }}">{{ $p['button'] }}</a>@endif
    </div>
</section>

@if (($p['items'] ?? []) !== [])
<section class="section" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <ul class="stats stats-big">
            @foreach ($p['items'] as $s)<li><strong>{{ $s['value'] }}</strong><span>{{ $s['label'] }}</span></li>@endforeach
        </ul>
    </div>
</section>
@endif

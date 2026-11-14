@php($media = app(\Modules\Sites\Content\MediaLibrary::class))
<section class="section" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <ul class="team">
            @foreach ($p['items'] as $m)
                <li>
                    @if ($m['photo'] && ($src = $media->url($m['photo'], 'card')))<img src="{{ $src }}" alt="{{ $m['name'] }}" loading="lazy" decoding="async" width="400" height="500">@else<div class="team-ph" aria-hidden="true">{{ mb_substr($m['name'], 0, 1) }}</div>@endif
                    <p class="team-name">{{ $m['name'] }}</p>
                    @if ($m['role'] !== '')<p class="team-role">{{ $m['role'] }}</p>@endif
                    @if ($m['phone'] !== '')<p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $m['phone']) }}">{{ $m['phone'] }}</a></p>@endif
                    @if ($m['email'] !== '')<p><a href="mailto:{{ $m['email'] }}">{{ $m['email'] }}</a></p>@endif
                </li>
            @endforeach
        </ul>
    </div>
</section>

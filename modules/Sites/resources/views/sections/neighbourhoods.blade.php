@if (! empty($block['markets']))
<section class="section section-alt" id="{{ $block['id'] }}">
    <div class="wrap">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        <ul class="hoods">
            @foreach ($block['markets'] as $m)
                <li>
                    <a href="/market/{{ $m['slug'] }}">
                        <p class="hood-name">{{ $m['name'] }}</p>
                        <p>{{ number_format($m['active']) }} homes for sale @if ($m['median_price'])· median ${{ number_format($m['median_price']) }}@endif</p>
                        @if ($m['median_dom'])<p class="fine">Typically {{ $m['median_dom'] }} days on market</p>@endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@endif

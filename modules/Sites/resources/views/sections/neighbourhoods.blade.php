{{-- UX1: the areas the brokerage serves, as photo tiles (city name, homes for sale, median price when known). --}}
@if (! empty($block['areas']))
<section class="section section-alt" id="{{ $block['id'] }}">
    <div class="wrap">
        {{-- Areas in more than one state (more than one MLS board): a title that fits them all. --}}
        @php($states = collect($block['areas'])->map(fn ($a) => \Illuminate\Support\Str::afterLast($a['name'], ', '))->unique()->count())
        @php($title = $states > 1 && str_starts_with($p['title'], 'Explore ') ? 'Areas we serve' : $p['title'])
        @if ($title !== '')<h2 class="section-title">{{ $title }}</h2>@endif
        <ul class="area-tiles">
            @foreach ($block['areas'] as $i => $a)
                <li @class(['area-tile', 'area-tile-wide' => $i === 0 && in_array(count($block['areas']), [3, 7], true)])>
                    <a href="{{ $a['href'] }}">
                        @if ($a['photo'])<img src="{{ $a['photo'] }}" alt="" loading="lazy" width="800" height="600">@endif
                        <span class="area-text">
                            <span class="area-name">{{ $a['name'] }}</span>
                            <span class="area-line">{{ $a['line'] }}@if ($a['fine']) · {{ $a['fine'] }}@endif</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@endif

@extends('sites::layout')

@section('content')
<main id="main" class="section">
    <div class="wrap">
        <h1 class="section-title">Compare homes</h1>
        @if (count($homes) < 2)
            <p class="lead-muted">Choose "Compare" on two to four homes, then come back here to see them side by side.</p>
            <p><a class="btn btn-primary" href="/homes">Browse homes</a></p>
        @else
            <div class="compare-scroll">
                <table class="compare">
                    <thead>
                        <tr><th scope="col"><span class="sr-only">Home</span></th>
                            @foreach ($homes as $h)
                                <th scope="col">
                                    <a href="{{ $h['path'] }}">
                                        @if ($h['photo'])<img src="{{ $h['photo'] }}" alt="" width="400" height="300" loading="lazy">@endif
                                        <span>{{ $h['address'] ?? 'Home' }}</span>
                                    </a>
                                    <button type="button" class="btn btn-ghost" data-fav="{{ $h['ulid'] }}" aria-pressed="false">Save</button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php($best = fn (string $k, bool $high) => collect($homes)->pluck($k)->filter(fn ($v) => $v !== null)->{$high ? 'max' : 'min'}())
                        @foreach ([['Price', 'price', false, fn ($v) => '$'.number_format($v)], ['Beds', 'beds', true, fn ($v) => $v], ['Baths', 'baths', true, fn ($v) => $v],
                            ['Square feet', 'sqft', true, fn ($v) => number_format($v)], ['Price per sq ft', 'ppsf', false, fn ($v) => '$'.number_format($v)],
                            ['Lot (sq ft)', 'lot_sqft', true, fn ($v) => number_format($v)], ['Year built', 'year_built', true, fn ($v) => $v],
                            ['Days on market', 'days_on_market', false, fn ($v) => $v]] as [$label, $key, $high, $fmt])
                            @php($top = $best($key, $high))
                            <tr><th scope="row">{{ $label }}</th>
                                @foreach ($homes as $h)
                                    <td @class(['best' => $h[$key] !== null && $h[$key] === $top && count($homes) > 1])>{{ $h[$key] !== null ? $fmt($h[$key]) : '—' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr><th scope="row">Location</th>@foreach ($homes as $h)<td>{{ collect([$h['city'], $h['postal_code']])->filter()->implode(' ') }}</td>@endforeach</tr>
                        <tr><th scope="row">Listed by</th>@foreach ($homes as $h)<td class="fine">{{ $h['attribution'] }}</td>@endforeach</tr>
                    </tbody>
                </table>
            </div>
            <p class="fine">Highlighted: the best value in each row. <a href="/contact">Ask an agent</a> to tour any of these homes.</p>
        @endif
    </div>
</main>
@endsection

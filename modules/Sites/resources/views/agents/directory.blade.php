@extends('sites::layout')

@section('content')
<main id="main" class="section">
    <div class="wrap">
        <h1 class="section-title">Our agents</h1>
        <form class="filters filters-inline" action="/agents" method="get" role="search">
            <label class="sr-only" for="a-q">Name or area</label>
            <input id="a-q" name="q" value="{{ $q }}" placeholder="Search by name or area">
            @if ($languages !== [])
                <label class="sr-only" for="a-lang">Language</label>
                <select id="a-lang" name="language">
                    <option value="">Any language</option>
                    @foreach ($languages as $l)<option value="{{ $l }}" @selected($language === $l)>{{ $l }}</option>@endforeach
                </select>
            @endif
            <button class="btn btn-primary" type="submit">Find an agent</button>
        </form>
        @if ($agents === [])
            <p class="lead-muted">No agents match. <a href="/contact">Ask us</a> and we will match you with the right one.</p>
        @endif
        <ul class="team agents">
            @foreach ($agents as $a)
                <li>
                    <a href="/agents/{{ $a['slug'] }}" class="agent-card">
                        @if ($a['photo'])<img src="{{ $a['photo'] }}" alt="{{ $a['name'] }}" loading="lazy" decoding="async" width="400" height="500">@else<div class="team-ph" aria-hidden="true">{{ mb_substr($a['name'], 0, 1) }}</div>@endif
                        <p class="team-name">{{ $a['name'] }}</p>
                        @if ($a['tagline'] !== '')<p class="team-role">{{ $a['tagline'] }}</p>@endif
                        @if ($a['areas'] !== [])<p class="fine">{{ implode(' · ', $a['areas']) }}</p>@endif
                        @if ($a['languages'] !== [] && $a['languages'] !== ['English'])<p class="fine">Speaks {{ implode(', ', $a['languages']) }}</p>@endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</main>
@endsection

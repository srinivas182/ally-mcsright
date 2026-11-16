@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:32px;max-width:860px">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        @if (! empty($agent->profile['photo']))<img src="{{ $agent->profile['photo'] }}" alt="{{ $name }}" width="112" height="112" style="width:112px;height:112px;border-radius:50%;object-fit:cover">@endif
        <div>
            <h1 style="margin:0">{{ $name }}</h1>
            <p class="muted" style="margin:4px 0">{{ $agent->brokerage_name }} · {{ $t('connect.licence', ['n' => $agent->licence_number]) }}
                @if ($lookup) · <a href="{{ $lookup }}" rel="noopener" target="_blank">{{ $t('connect.check_licence') }}</a>@endif</p>
            @if ($summary['count'] > 0)<p style="margin:0"><strong>{{ str_repeat('★', (int) round((float) $summary['average'])) }}</strong> {{ $t('agent.rating', ['avg' => $summary['average'], 'n' => $summary['count']]) }}</p>@endif
        </div>
    </div>
    <p class="lead">{{ $agent->profile['bio'] ?? '' }}</p>
    <p class="small muted">{{ $t('connect.years', ['n' => $agent->profile['years'] ?? 0]) }} · {{ collect($agent->profile['languages'] ?? ['en'])->map(fn ($l) => $l === 'es' ? 'Español' : 'English')->join(', ') }}</p>
    <p class="note">{{ $t('agent.talk', ['name' => $name]) }}</p>

    <h2>{{ $t('agent.reviews') }}</h2>
    @forelse ($reviews as $r)
        <article class="note" style="margin-bottom:10px">
            <strong aria-label="{{ $r['rating'] }} / 5">{{ str_repeat('★', $r['rating']) }}{{ str_repeat('☆', 5 - $r['rating']) }}</strong>
            <span class="small muted">{{ $r['author'] }} · {{ $t('agent.verified') }} · {{ $r['date'] }}</span>
            @if ($r['text'])<p style="margin:6px 0 0">{{ $r['text'] }}</p>@endif
            @if ($r['reply'])<p class="small" style="margin:6px 0 0;padding-left:12px;border-left:2px solid var(--line)"><strong>{{ $t('agent.reply', ['name' => $name]) }}:</strong> {{ $r['reply'] }}</p>@endif
        </article>
    @empty
        <p class="muted">{{ $t('agent.none') }}</p>
    @endforelse

    @if ($imported !== [])
        <h2>{{ $t('agent.imported') }}</h2>
        <p class="small muted">{{ $t('agent.imported_note') }}</p>
        @foreach ($imported as $r)
            <article class="note" style="margin-bottom:10px">
                @if ($r['rating'])<strong>{{ str_repeat('★', (int) $r['rating']) }}</strong>@endif
                <span class="small muted">{{ $r['source'] }} · {{ $r['author'] ?? '' }} · {{ $r['date'] }}</span>
                @if ($r['text'])<p style="margin:6px 0 0">{{ $r['text'] }}</p>@endif
            </article>
        @endforeach
    @endif
</div>
@endsection

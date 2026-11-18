@extends('marketing::layout')
@section('content')
@php($es = $L === 'es')
@foreach ($blocks as $i => $b)
    @switch($b['type'])
        @case('hero')
            <section class="hero"><div class="wrap">
                @if ($i === 0)<h1>{{ $b['heading'] }}</h1>@else<h2>{{ $b['heading'] }}</h2>@endif
                @if ($b['text'] !== '')<p class="lead">{{ $b['text'] }}</p>@endif
                @if ($b['cta_label'] !== '' && $b['cta_href'] !== '')<a class="btn" href="{{ $b['cta_href'] === '/start' ? $start : $b['cta_href'] }}">{{ $b['cta_label'] }}</a>@endif
                @if ($b['second_label'] !== '' && $b['second_href'] !== '')<a class="btn ghost" href="{{ $b['second_href'] === '/health-check' ? $healthCheck : $b['second_href'] }}">{{ $b['second_label'] }}</a>@endif
            </div></section>
            @break
        @case('answer')
            <section><div class="wrap"><div class="answer"><h2>{{ $b['question'] }}</h2><p>{{ $b['answer'] }}</p></div></div></section>
            @break
        @case('features')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<h2>{{ $b['heading'] }}</h2>@endif
                <div class="grid">@foreach ($b['items'] as $item)<div class="card"><h3>{{ $item['title'] }}</h3>@if ($item['text'] !== '')<p class="muted">{{ $item['text'] }}</p>@endif</div>@endforeach</div>
            </div></section>
            @break
        @case('steps')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<h2>{{ $b['heading'] }}</h2>@endif
                <ol class="steps">@foreach ($b['items'] as $item)<li><h3>{{ $item['title'] }}</h3>@if ($item['text'] !== '')<p class="muted">{{ $item['text'] }}</p>@endif</li>@endforeach</ol>
            </div></section>
            @break
        @case('faq')
            <section><div class="wrap">
                <h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? 'Preguntas frecuentes' : 'Questions and answers') }}</h2>
                @foreach ($b['items'] as $item)<details><summary>{{ $item['q'] }}</summary><p>{{ $item['a'] }}</p></details>@endforeach
            </div></section>
            @break
        @case('compare')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<h2>{{ $b['heading'] }}</h2>@endif
                <div class="tablewrap"><table>
                    <thead><tr><th scope="col"><span class="small muted">{{ $es ? 'Comparado el' : 'Checked on' }} {{ $b['checked_on'] }}</span></th>@foreach ($b['columns'] as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($b['rows'] as $row)<tr><th scope="row">{{ $row['label'] }}</th>@foreach ($row['values'] as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach</tbody>
                </table></div>
                @if ($b['note'] !== '')<p class="small muted">{{ $b['note'] }}</p>@endif
            </div></section>
            @break
        @case('pricing')
            <section><div class="wrap">
                <h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? 'Precios' : 'Prices') }}</h2>
                <div class="grid">
                    @forelse ($packages as $p)
                        <div class="card">
                            <h3>{{ $p->name }}</h3>
                            <p class="price">${{ number_format(((int) $p->monthly_cents) / 100, 0) }}<span class="small muted"> / {{ $es ? 'mes' : 'month' }}</span></p>
                            @if ((int) $p->yearly_cents > 0)<p class="small muted">{{ $es ? 'o' : 'or' }} ${{ number_format(((int) $p->yearly_cents) / 100, 0) }} / {{ $es ? 'año' : 'year' }}</p>@endif
                            <a class="btn" href="{{ $start }}?package={{ $p->ulid ?? '' }}">{{ $es ? 'Elegir' : 'Choose' }} {{ $p->name }}</a>
                        </div>
                    @empty
                        <p class="muted">{{ $es ? 'Pronto publicaremos los precios.' : 'Prices are coming soon.' }}</p>
                    @endforelse
                </div>
                @if ($b['note'] !== '')<p class="small muted">{{ $b['note'] }}</p>@endif
            </div></section>
            @break
        @case('text')
            <section><div class="wrap prose">
                @if ($b['heading'] !== '')<h2>{{ $b['heading'] }}</h2>@endif
                @foreach (preg_split('/\n{2,}/', $b['body']) as $para)<p>{{ $para }}</p>@endforeach
            </div></section>
            @break
        @case('testimonials')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<h2>{{ $b['heading'] }}</h2>@endif
                <div class="grid">@foreach ($b['items'] as $item)<figure class="card"><blockquote>“{{ $item['quote'] }}”</blockquote><figcaption class="muted small">— {{ $item['name'] }}@if ($item['role'] !== ''), {{ $item['role'] }}@endif</figcaption></figure>@endforeach</div>
            </div></section>
            @break
        @case('cta')
            <section><div class="wrap"><div class="card">
                <h2>{{ $b['heading'] }}</h2>
                @if ($b['text'] !== '')<p class="lead">{{ $b['text'] }}</p>@endif
                <a class="btn" href="{{ $b['href'] === '/start' ? $start : ($b['href'] === '/health-check' ? $healthCheck : $b['href']) }}">{{ $b['label'] }}</a>
            </div></div></section>
            @break
    @endswitch
@endforeach
@endsection

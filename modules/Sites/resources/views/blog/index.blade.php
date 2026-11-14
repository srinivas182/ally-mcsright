@extends('sites::layout')

@push('head')<link rel="alternate" type="application/rss+xml" title="{{ $site->displayName() }} blog" href="/blog/feed.xml">@endpush

@section('content')
<main id="main" class="section blog">
    <div class="wrap">
        <h1 class="section-title">{{ $label !== null ? $label : 'Blog' }}</h1>
        @if ($categories !== [])
            <nav class="chips" aria-label="Categories">
                <a href="/blog" @if ($label === null) aria-current="page" @endif>All</a>
                @foreach ($categories as $c)<a href="/blog/category/{{ $c['slug'] }}" @if ($label === $c['name']) aria-current="page" @endif>{{ $c['name'] }}</a>@endforeach
            </nav>
        @endif
        @if ($posts === [])
            <p class="lead-muted">New articles are on the way.</p>
        @endif
        <ul class="posts">
            @foreach ($posts as $p)
                <li>
                    <article>
                        <a class="post-link" href="/blog/{{ $p['slug'] }}">
                            @if ($p['cover'])<img src="{{ $p['cover'] }}" alt="{{ $p['alt'] }}" loading="lazy" decoding="async" width="800" height="450">@else<div class="card-placeholder post-ph" aria-hidden="true"></div>@endif
                            <p class="post-meta">{{ $p['category'] ?? 'Article' }} · {{ $p['date'] }}</p>
                            <h2>{{ $p['title'] }}</h2>
                            <p class="lead-muted">{{ $p['excerpt'] }}</p>
                        </a>
                    </article>
                </li>
            @endforeach
        </ul>
        @if ($prev || $next)
            <nav class="pager" aria-label="Pages">
                @if ($prev)<a class="btn btn-ghost" rel="prev" href="{{ $prev }}">Newer</a>@endif
                @if ($next)<a class="btn btn-ghost" rel="next" href="{{ $next }}">Older</a>@endif
            </nav>
        @endif
    </div>
</main>
@endsection

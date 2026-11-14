@extends('sites::layout')

@push('head')<link rel="alternate" type="application/rss+xml" title="{{ $site->displayName() }} blog" href="/blog/feed.xml">@endpush

@section('content')
<main id="main" class="post">
    <article>
        <header class="wrap narrow post-head">
            <nav class="crumbs" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/blog">Blog</a></li>@if ($post->category)<li><a href="/blog/category/{{ \Illuminate\Support\Str::slug($post->category) }}">{{ $post->category }}</a></li>@endif</ol></nav>
            <h1>{{ $post->title }}</h1>
            <p class="post-meta">@if ($author)By {{ $author }} · @endif<time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->format('F j, Y') }}</time> · {{ $minutes }} min read</p>
        </header>
        @if ($cover)<figure class="wrap post-cover"><img src="{{ $cover }}" alt="{{ $coverAlt }}" width="1600" height="900" fetchpriority="high" decoding="async"></figure>@endif
        <div class="wrap post-grid">
            @if (count($toc) >= 2)
                <nav class="toc" aria-label="On this page">
                    <p>On this page</p>
                    <ol>@foreach ($toc as $h)<li class="toc-{{ $h['level'] }}"><a href="#{{ $h['id'] }}">{{ $h['text'] }}</a></li>@endforeach</ol>
                </nav>
            @endif
            <div class="prose post-body">{!! $html !!}</div>
        </div>
    </article>
    <section class="section">
        <div class="wrap contact-grid">
            <div>
                <h2 class="section-title">Questions about buying or selling?</h2>
                <p class="lead-muted">Ask a local agent from {{ $site->displayName() }}. No pressure, just answers.</p>
            </div>
            @include('sites::partials.lead-form', ['return' => '/blog/'.$post->slug, 'heading' => null])
        </div>
    </section>
    @if ($related !== [])
        <section class="section section-alt">
            <div class="wrap">
                <h2 class="section-title">Keep reading</h2>
                <ul class="posts">
                    @foreach ($related as $p)
                        <li><article><a class="post-link" href="/blog/{{ $p['slug'] }}">@if ($p['cover'])<img src="{{ $p['cover'] }}" alt="{{ $p['alt'] }}" loading="lazy" width="800" height="450">@endif<p class="post-meta">{{ $p['date'] }}</p><h3>{{ $p['title'] }}</h3></a></article></li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
</main>
@endsection

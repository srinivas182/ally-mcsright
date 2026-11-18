@extends('marketing::layout')
@section('content')
<section class="hero"><div class="wrap"><h1>{{ $title }}</h1><p class="lead">{{ $description }}</p></div></section>
<section><div class="wrap">
    @forelse ($posts as $post)
        <article class="card" style="margin-bottom:14px">
            <h2><a href="{{ rtrim($home, '/') }}/blog/{{ $post->slug }}">{{ $post->title }}</a></h2>
            <p class="muted">{{ $post->description }}</p>
            <p class="small muted"><time datetime="{{ \Carbon\CarbonImmutable::parse($post->published_at)->toDateString() }}">{{ \Carbon\CarbonImmutable::parse($post->published_at)->locale($L)->isoFormat('LL') }}</time> · {{ $post->author }}</p>
        </article>
    @empty
        <p class="muted">{{ $L === 'es' ? 'Pronto habrá artículos aquí.' : 'Articles are coming soon.' }}</p>
    @endforelse
</div></section>
@endsection

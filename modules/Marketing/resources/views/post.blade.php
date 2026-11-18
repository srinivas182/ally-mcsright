@extends('marketing::layout')
@section('content')
<article>
    <section class="hero"><div class="wrap prose">
        <p class="small"><a href="{{ rtrim($home, '/') }}/blog">Blog</a></p>
        <h1>{{ $post->title }}</h1>
        <p class="lead">{{ $post->description }}</p>
        <p class="small muted">{{ $post->author }} · <time datetime="{{ \Carbon\CarbonImmutable::parse($post->published_at)->toDateString() }}">{{ \Carbon\CarbonImmutable::parse($post->published_at)->locale($L)->isoFormat('LL') }}</time>
            · {{ $L === 'es' ? 'Actualizado' : 'Updated' }} <time datetime="{{ \Carbon\CarbonImmutable::parse($post->updated_at)->toDateString() }}">{{ \Carbon\CarbonImmutable::parse($post->updated_at)->locale($L)->isoFormat('LL') }}</time>
            · {{ $rendered['minutes'] }} min</p>
    </div></section>
    <section><div class="wrap prose">
        @if (count($rendered['toc']) > 2)
            <nav class="card" aria-label="{{ $L === 'es' ? 'Contenido' : 'Contents' }}"><ul>@foreach ($rendered['toc'] as $t)<li><a href="#{{ $t['id'] }}">{{ $t['text'] }}</a></li>@endforeach</ul></nav>
        @endif
        {!! $rendered['html'] !!}
    </div></section>
</article>
@endsection

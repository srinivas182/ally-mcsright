@extends('sites::layout')

@section('content')
<main id="main">
    <section class="section">
        <div class="wrap narrow">
            <h1 class="section-title">{{ $video->title }}</h1>
            <p class="lead-muted">A video message from {{ $agent }}</p>
            <video class="video-message" controls playsinline preload="metadata" src="/v/{{ $video->token }}/file">
                Your browser can't play this video. <a href="/v/{{ $video->token }}/file">Download it</a>.
            </video>
            @if ($video->message)<p class="answer">{{ $video->message }}</p>@endif
            <p>
                @if ($phone)<a class="btn btn-primary" href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">Call {{ $agent }}</a>@endif
                @if ($email)<a class="btn" href="mailto:{{ $email }}?subject={{ rawurlencode('Re: '.$video->title) }}">Reply by email</a>@endif
            </p>
        </div>
    </section>
</main>
@endsection

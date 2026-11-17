@extends('sites::layout')

@section('content')
<main id="main">
    <section class="section">
        <div class="wrap narrow">
            <p class="lead-muted">{{ $deal->type === 'buyer' ? 'Just closed' : 'Just sold' }} · {{ \Illuminate\Support\Carbon::parse((string) ($deal->closed_on ?? $card->created_at))->format('F Y') }}</p>
            <h1 class="section-title">{{ $card->headline }}</h1>
            <p class="answer">{{ $card->message }}</p>
            @if ($card->show_price && $deal->price_cents > 0)
                <p><strong>{{ $deal->type === 'buyer' ? 'Bought for' : 'Sold for' }} ${{ number_format(intdiv((int) $deal->price_cents, 100)) }}</strong></p>
            @endif
            <p>{{ $deal->type === 'buyer' ? 'Represented the buyers' : 'Represented the sellers' }}@if ($agent): {{ $agent->name }}@endif, {{ $site->displayName() }}.</p>
            <p>
                <a class="btn btn-primary" href="/#contact">Talk to us about your move</a>
            </p>
            <h2 class="section-title" style="font-size:1.1em">Share this</h2>
            <p>
                <a class="btn" href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($url) }}" rel="noopener" target="_blank">Facebook</a>
                <a class="btn" href="https://www.linkedin.com/sharing/share-offsite/?url={{ urlencode($url) }}" rel="noopener" target="_blank">LinkedIn</a>
                <a class="btn" href="https://twitter.com/intent/tweet?url={{ urlencode($url) }}&text={{ urlencode($card->headline) }}" rel="noopener" target="_blank">X</a>
                <a class="btn" href="mailto:?subject={{ rawurlencode($card->headline) }}&body={{ rawurlencode($url) }}">Email</a>
            </p>
        </div>
    </section>
</main>
@endsection

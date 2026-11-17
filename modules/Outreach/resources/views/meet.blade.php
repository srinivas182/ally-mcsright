@extends('sites::layout')

@section('content')
<main id="main">
    <section class="section">
        <div class="wrap contact-grid">
            <div>
                <h1 class="section-title">{{ $link['title'] }}</h1>
                <p class="answer">{{ $link['intro'] !== '' ? $link['intro'] : 'Choose a time that suits you for a '.strtolower($kind).' with '.$agent.'. You will get a confirmation, and '.$agent.' will be ready for you.' }}</p>
                <p class="lead-muted">{{ $kind }} with {{ $agent }} · {{ $site->displayName() }}</p>
            </div>
            <div class="form-card" id="book">
                @if ($booked)
                    <p class="form-ok" role="status">You're booked for <strong>{{ $booked }}</strong>. {{ $agent }} will be in touch to confirm.</p>
                @elseif ($days === [])
                    <p class="answer">There are no open times in the next two weeks. <a href="/contact">Send a message</a> and {{ $agent }} will find a time with you.</p>
                @else
                    @if ($formError)<p class="form-error" role="alert">{{ $formError }}</p>@endif
                    <form method="post" action="/_meet/{{ $link['slug'] }}">
                        <fieldset class="meet-days">
                            <legend class="form-title">Choose a time</legend>
                            @foreach ($days as $d)
                                <div class="meet-day">
                                    <p><strong>{{ $d['label'] }}</strong></p>
                                    <div class="meet-times">
                                        @foreach ($d['times'] as $time)
                                            <label class="meet-time"><input type="radio" name="slot" value="{{ $time['value'] }}" required @checked($loop->parent->first && $loop->first)> {{ $time['label'] }}</label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </fieldset>
                        <label>Name <input name="name" required autocomplete="name"></label>
                        <label>Email <input type="email" name="email" required autocomplete="email"></label>
                        <label>Mobile phone <input type="tel" name="phone" autocomplete="tel"></label>
                        <label>Anything we should know? <textarea name="notes" rows="3"></textarea></label>
                        <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $consent }}</span></label>
                        <div class="hp" aria-hidden="true"><label>Leave empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
                        <input type="hidden" name="t" value="{{ $t }}">
                        <button class="btn btn-primary" type="submit">Book this time</button>
                    </form>
                @endif
            </div>
        </div>
    </section>
</main>
@endsection

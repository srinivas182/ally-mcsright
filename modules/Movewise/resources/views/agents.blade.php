@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:32px">
    <h1>Real estate agents: introductions to people who chose you</h1>
    <p class="lead">On Movewise, people pick one of three local agents and tap Connect. Only that agent is introduced, after accepting. No shared leads, no resold leads, no cold calling.</p>
    <ul class="promises">
        <li><strong>One agent per person.</strong>Nobody else gets the introduction. The person sees your card, your reviews and how quickly you reply.</li>
        <li><strong>You set the limits.</strong>Areas, price range, buyers or sellers, languages, working hours and a monthly cap.</li>
        <li><strong>Fair and open.</strong>Every agent is ranked the same way, from speed, follow-through and client ratings, and sees exactly why.</li>
        <li><strong>Pay as agreed.</strong>Per introduction, per held appointment, or a referral share at closing, depending on the state.</li>
    </ul>
    <h2>Already on RightAlly?</h2>
    <p>Open <strong>Movewise</strong> in your RightAlly back office to apply, build your profile card and set up a package.</p>
    <h2 id="seat">Not on RightAlly? Ask for a free Movewise Agent seat</h2>
    @if ($sent)
        <p class="note" role="status">Thanks. We check your licence with the state and reply by email within two working days.</p>
    @else
        <p>A small RightAlly account of your own (contacts, tasks, inbox and calendar), free, so introductions and updates work. Your brokerage stays your brokerage.</p>
        <form class="tool" method="post" action="{{ $u('/agents/join') }}" novalidate>
            @foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email', 'licence_number' => 'Licence number', 'brokerage_name' => 'Brokerage you\'re licensed under'] as $field => $label)
                <label>{{ $label }}
                    <input name="{{ $field }}" value="{{ $old[$field] ?? '' }}" @if ($field === 'email') type="email" autocomplete="email" @endif required maxlength="160" @isset($errors_[$field]) aria-invalid="true" aria-describedby="e-{{ $field }}" @endisset>
                    @isset($errors_[$field])<span class="small" id="e-{{ $field }}" style="color:var(--accent)">{{ $errors_[$field] }}</span>@endisset
                </label>
            @endforeach
            <label>State
                <select name="state">@foreach ($states as $code => $name)<option value="{{ $code }}" @selected(($old['state'] ?? '') === $code)>{{ $name }}</option>@endforeach</select>
                @isset($errors_['state'])<span class="small" style="color:var(--accent)">{{ $errors_['state'] }}</span>@endisset
            </label>
            <label style="font-weight:400"><input type="checkbox" name="agree" value="1" required> Movewise may email me about this request and check my licence with the state.</label>
            @isset($errors_['agree'])<span class="small" style="color:var(--accent)">{{ $errors_['agree'] }}</span>@endisset
            <button class="btn">Ask for a seat</button>
        </form>
    @endif
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection

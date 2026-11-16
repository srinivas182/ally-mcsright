@extends('sites::layout')

@section('content')
<main id="main" class="section legal-page">
    <div class="wrap prose">
        <h1 class="section-title">Privacy policy</h1>
        @if ($custom)
            @foreach ($custom as $para)<p>{!! nl2br(e($para)) !!}</p>@endforeach
        @else
            <p class="lead-muted">This policy explains what {{ $name }} collects on this website, why, and the choices you have.@if ($updated) Last updated {{ $updated }}.@endif</p>

            <h2>What we collect</h2>
            <ul>
                <li><strong>What you tell us:</strong> your name, email, phone number and message when you contact us, ask about a home, request a showing or a home value (with the address you give), or create an account to save homes and searches.</li>
                <li><strong>Homes you look at:</strong> the searches you run and the homes you view, save and compare on this website. Your browser keeps a random identifier for this; we don't use advertising cookies or third-party trackers. If your browser sends Global Privacy Control or Do Not Track, we don't record this at all.</li>
                <li><strong>Chats:</strong> what you write to our website assistant, so an agent can follow up.</li>
                <li><strong>Ads and forms on social media:</strong> if you fill in one of our forms on Facebook or Instagram, Meta passes us the details you submit there.</li>
            </ul>

            <h2>How we use it</h2>
            <ul>
                <li>To answer you, arrange showings, send the home alerts and price changes you ask for, and help you buy or sell.</li>
                <li>To let your agent see which homes interest you, so their help fits what you're looking for.</li>
                <li>To keep this website working, secure and free of abuse.</li>
            </ul>

            <h2>Text messages and calls</h2>
            <p>We text you only if you agree to it, between 8 am and 9 pm your time. Reply STOP to any text to stop, or HELP for help. Message and data rates may apply. Agreeing to texts is never a condition of buying or selling.</p>

            <h2>Who we share it with</h2>
            <ul>
                <li>Our agents and staff, to help you.</li>
                <li>Service providers who run this website and our communications for us (hosting, email and text delivery), only to provide those services.</li>
                <li>When the law requires it, or to protect rights and safety.</li>
            </ul>
            <p><strong>We never sell your information</strong>, and we don't pass the inquiries you send us to other brokerages.</p>

            <h2>Your choices</h2>
            <ul>
                <li>Unsubscribe from emails with the link in any email, and from texts by replying STOP.</li>
                <li>Ask us what we hold about you, or to correct or delete it, using the contact details below. Some records may be kept where the law requires.</li>
                <li>Turn on Global Privacy Control in your browser to stop us recording the homes you view.</li>
            </ul>

            <h2>Keeping it safe</h2>
            <p>Information is sent over encrypted connections and stored securely, and only people who need it to help you can see it.</p>

            <h2>Children</h2>
            <p>This website is for adults looking to buy, sell or rent a home. It isn't meant for children, and we don't knowingly collect information from anyone under 16.</p>

            <h2>Listing information</h2>
            <p>Homes for sale come from the multiple listing service (MLS). Listing information is deemed reliable but not guaranteed.</p>
        @endif

        <h2>Contact us</h2>
        <p>{{ $name }}@if (! empty($info['street']))<br>{{ $info['street'] }}, {{ trim(implode(', ', array_filter([$info['city'] ?? '', trim(($info['state'] ?? '').' '.($info['zip'] ?? ''))]))) }}@endif
            @if (! empty($info['email']))<br><a href="mailto:{{ $info['email'] }}">{{ $info['email'] }}</a>@endif
            @if (! empty($info['phone']))<br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $info['phone']) }}">{{ $info['phone'] }}</a>@endif
        </p>
    </div>
</main>
@endsection

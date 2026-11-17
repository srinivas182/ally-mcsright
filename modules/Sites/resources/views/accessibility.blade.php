@extends('sites::layout')

@section('content')
<main id="main" class="section legal-page">
    <div class="wrap prose">
        <h1 class="section-title">Accessibility</h1>
        <p class="lead-muted">{{ $name }} wants everyone to be able to search for homes, ask questions and get help on this website, including people who use a screen reader, a keyboard, magnification or captions.@if ($updated) Last reviewed {{ $updated }}.@endif</p>

        <h2>Our goal</h2>
        <p>We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA. We check every page automatically for common problems and fix what we find.</p>

        <h2>What this website does</h2>
        <ul>
            <li>Every page works with a keyboard alone, and shows clearly where you are.</li>
            <li>A "Skip to content" link at the top of each page takes you straight past the menu.</li>
            <li>Headings, labels and buttons are named so screen readers can read them out.</li>
            <li>Text can be made larger with your browser's zoom (up to 200%) without losing anything.</li>
            <li>Colours have enough contrast to read, and nothing depends on colour alone.</li>
            <li>Nothing flashes, and nothing moves on its own if your device asks for less motion.</li>
        </ul>

        <h2>What may fall short</h2>
        <ul>
            <li>Photos of homes come from the multiple listing service (MLS). Some may not have a full description.</li>
            <li>Maps are hard to use without sight. Every home on a map is also in the list beside it, with its address.</li>
            <li>Documents supplied by others (for example, disclosures) may not be fully accessible. Ask us and we'll help.</li>
        </ul>

        <h2>Get help, or the same information another way</h2>
        <p>If anything on this website is hard to use, tell us what you were trying to do. We'll help you straight away by phone or email, and send the information in a format that works for you. We aim to reply within two business days.</p>
        <p>{{ $name }}@if (! empty($info['street']))<br>{{ $info['street'] }}, {{ trim(implode(', ', array_filter([$info['city'] ?? '', trim(($info['state'] ?? '').' '.($info['zip'] ?? ''))]))) }}@endif
            @if (! empty($info['email']))<br><a href="mailto:{{ $info['email'] }}?subject=Accessibility">{{ $info['email'] }}</a>@endif
            @if (! empty($info['phone']))<br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $info['phone']) }}">{{ $info['phone'] }}</a>@endif
        </p>
    </div>
</main>
@endsection

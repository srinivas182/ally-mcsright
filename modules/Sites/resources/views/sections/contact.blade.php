{{--
    UX1: a full contact page in every design: how to reach the brokerage (call, email, visit, hours), each as a clear
    card with an icon, a photo of the area with the response promise, and the message form beside it. Anything the
    brokerage has not filled in is left out, never shown empty.
--}}
@php
    $phone = ($chrome['agent']['phone'] ?? '') !== '' ? $chrome['agent']['phone'] : (string) ($site->info['phone'] ?? '');
    $email = ($chrome['agent']['email'] ?? '') !== '' ? $chrome['agent']['email'] : (string) ($site->info['email'] ?? '');
    $street = trim((string) ($site->info['street'] ?? ''));
    $town = trim(implode(', ', array_filter([(string) ($site->info['city'] ?? ''), trim(($site->info['state'] ?? '').' '.($site->info['zip'] ?? ''))])));
    $address = trim($street.($street !== '' && $town !== '' ? ', ' : '').$town);
    $hours = trim((string) ($site->info['hours'] ?? '')) ?: "Monday to Friday, 9am to 6pm\nSaturday, 10am to 4pm";
    $photo = \Modules\Sites\Content\DemoPhotos::url(\Modules\Sites\Content\StarterSite::cityPhoto((array) $site->info), 'card');
    $area = trim((string) ($site->info['area'] ?? ''));
@endphp
<section class="section contact-pro" id="{{ $block['id'] ?? 'contact-section' }}">
    <div class="wrap contact-grid">
        <div class="contact-side">
            @if ($first ?? false)<h1 class="section-title">{{ $p['title'] }}</h1>@else<h2 class="section-title">{{ $p['title'] }}</h2>@endif
            @if ($p['body'] !== '')<p class="lead-muted">{{ $p['body'] }}</p>@endif
            <ul class="contact-cards">
                @if ($phone !== '')
                    <li class="contact-card">
                        <span class="contact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z"/></svg></span>
                        <span><span class="contact-label">Call or text</span><a class="contact-value" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></span>
                    </li>
                @endif
                @if ($email !== '')
                    <li class="contact-card">
                        <span class="contact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4a2 2 0 0 1-2-2V6c0-1.1.9-2 2-2zm8 7 8-5H4l8 5zm0 2L4 8v10h16V8l-8 5z"/></svg></span>
                        <span><span class="contact-label">Email</span><a class="contact-value" href="mailto:{{ $email }}">{{ $email }}</a></span>
                    </li>
                @endif
                @if ($address !== '')
                    <li class="contact-card">
                        <span class="contact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2a7 7 0 0 1 7 7c0 5.3-7 13-7 13S5 14.3 5 9a7 7 0 0 1 7-7zm0 9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/></svg></span>
                        <span><span class="contact-label">Visit the office</span><span class="contact-value">{{ $address }}</span>
                            <a class="contact-link" href="https://www.google.com/maps/search/?api=1&amp;query={{ rawurlencode($site->displayName().' '.$address) }}" target="_blank" rel="noopener">Get directions</a></span>
                    </li>
                @endif
                <li class="contact-card">
                    <span class="contact-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2a10 10 0 1 1 0 20 10 10 0 0 1 0-20zm0 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm1 3v4.6l3.2 1.9-.8 1.3L11 12.4V7h2z"/></svg></span>
                    <span><span class="contact-label">Office hours</span><span class="contact-value contact-hours">{!! nl2br(e($hours)) !!}</span></span>
                </li>
            </ul>
            @if ($photo)
                <figure class="contact-photo">
                    <img src="{{ $photo }}" alt="{{ $area !== '' ? $area : 'Our area' }}" loading="lazy" width="800" height="500">
                    <figcaption><strong>Most messages are answered within the hour.</strong>@if ($area !== '') <span>Local agents for {{ $area }}.</span>@endif</figcaption>
                </figure>
            @endif
        </div>
        <div class="contact-form-col">
            <h2 class="contact-form-title">Send us a message</h2>
            <p class="muted">Tell us what you are looking for. An agent will reply by phone, text or email, whichever you prefer.</p>
            @include('sites::partials.lead-form', ['return' => $current])
        </div>
    </div>
</section>

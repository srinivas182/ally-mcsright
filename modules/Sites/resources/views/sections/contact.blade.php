<section class="section" id="{{ $block['id'] ?? 'contact-section' }}">
    <div class="wrap contact-grid">
        <div>
            @if ($first ?? false)<h1 class="section-title">{{ $p['title'] }}</h1>@else<h2 class="section-title">{{ $p['title'] }}</h2>@endif
            @if ($p['body'] !== '')<p class="lead-muted">{{ $p['body'] }}</p>@endif
            @php($phone = ($chrome['agent']['phone'] ?? '') !== '' ? $chrome['agent']['phone'] : (string) ($site->info['phone'] ?? ''))
            @php($email = ($chrome['agent']['email'] ?? '') !== '' ? $chrome['agent']['email'] : (string) ($site->info['email'] ?? ''))
            <ul class="contact-facts">
                @if ($phone !== '')<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></li>@endif
                @if ($email !== '')<li><a href="mailto:{{ $email }}">{{ $email }}</a></li>@endif
                @if (! empty($site->info['street']))<li>{{ $site->info['street'] }}, {{ $site->info['city'] ?? '' }}, {{ $site->info['state'] ?? '' }} {{ $site->info['zip'] ?? '' }}</li>@endif
            </ul>
        </div>
        @include('sites::partials.lead-form', ['return' => $current])
    </div>
</section>

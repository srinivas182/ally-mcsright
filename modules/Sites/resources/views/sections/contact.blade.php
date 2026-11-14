<section class="section" id="{{ $block['id'] ?? 'contact-section' }}">
    <div class="wrap contact-grid">
        <div>
            @if ($first ?? false)<h1 class="section-title">{{ $p['title'] }}</h1>@else<h2 class="section-title">{{ $p['title'] }}</h2>@endif
            @if ($p['body'] !== '')<p class="lead-muted">{{ $p['body'] }}</p>@endif
            <ul class="contact-facts">
                @if (! empty($site->info['phone']))<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">{{ $site->info['phone'] }}</a></li>@endif
                @if (! empty($site->info['email']))<li><a href="mailto:{{ $site->info['email'] }}">{{ $site->info['email'] }}</a></li>@endif
                @if (! empty($site->info['street']))<li>{{ $site->info['street'] }}, {{ $site->info['city'] ?? '' }}, {{ $site->info['state'] ?? '' }} {{ $site->info['zip'] ?? '' }}</li>@endif
            </ul>
        </div>
        @include('sites::partials.lead-form', ['return' => $page->path])
    </div>
</section>

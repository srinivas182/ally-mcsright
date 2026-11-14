<section class="hero" @if (! empty($p['image'] ?? $site->brand['hero_image'] ?? null)) style="--hero: url('{{ $p['image'] ?? $site->brand['hero_image'] }}')" @endif>
    <div class="wrap hero-inner">
        <h1>{{ $p['headline'] }}</h1>
        @if ($p['subhead'] !== '')<p class="lead">{{ $p['subhead'] }}</p>@endif
        @if ($p['search'] ?? true)
            <form class="hero-search" action="/homes" method="get" role="search">
                <label class="sr-only" for="hero-q">City, ZIP, neighborhood or address</label>
                <input id="hero-q" name="q" placeholder="City, ZIP, neighborhood or address" autocomplete="off" enterkeyhint="search">
                <button class="btn btn-primary" type="submit">Search homes</button>
            </form>
        @endif
        <div class="hero-actions">
            <a class="btn btn-ghost" href="/contact">Talk to an agent</a>
            @if (! empty($site->info['phone']))<a class="btn btn-ghost" href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">Call {{ $site->info['phone'] }}</a>@endif
        </div>
    </div>
</section>

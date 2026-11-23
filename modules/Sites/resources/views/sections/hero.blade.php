@php($heroImage = app(\Modules\Sites\Content\MediaLibrary::class)->url($p['image'] ?? $site->brand['hero_image'] ?? null, 'hero'))
{{-- WP1: phones load the smaller photo. --}}
@php($heroSmall = app(\Modules\Sites\Content\MediaLibrary::class)->url($p['image'] ?? $site->brand['hero_image'] ?? null, 'card'))
<section class="hero" @if ($heroImage) style="--hero: url('{{ $heroImage }}'); --hero-sm: url('{{ $heroSmall }}')" @endif>
    <div class="wrap hero-inner">
        <h1>{{ $p['headline'] }}</h1>
        @if ($p['subhead'] !== '')<p class="lead">{{ $p['subhead'] }}</p>@endif
        @if ($p['search'] ?? true)
            <form class="hero-search" action="/homes" method="get" role="search">
                <label class="sr-only" for="hero-q">City, ZIP, neighborhood or address</label>
                <input id="hero-q" name="q" placeholder="City, ZIP or address" autocomplete="off" enterkeyhint="search">
                <button class="btn btn-primary search-go" type="submit" aria-label="Search homes"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg></button>
            </form>
        @endif
        <div class="hero-actions">
            <a class="btn btn-ghost" href="/contact">Talk to an agent</a>
            @if (! empty($site->info['phone']))<a class="btn btn-ghost" href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">Call {{ $site->info['phone'] }}</a>@endif
        </div>
    </div>
</section>

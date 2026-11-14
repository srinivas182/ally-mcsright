@if (! empty($block['listings']))
<section class="section" id="{{ $block['id'] }}">
    <div class="wrap">
        <div class="section-head">
            @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
            <a class="link-more" href="/homes{{ ($p['q'] ?? '') !== '' ? '?q='.urlencode($p['q']) : '' }}">See all homes</a>
        </div>
        <ul class="cards">@include('sites::idx.cards', ['listings' => $block['listings']])</ul>
    </div>
</section>
@endif

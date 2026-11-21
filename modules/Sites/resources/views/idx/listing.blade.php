@extends('sites::layout')

@section('content')
<main id="main" class="listing">
    <nav class="wrap crumbs" aria-label="Breadcrumb">
        <ol>
            <li><a href="/">Home</a></li>
            <li><a href="/homes">Homes for sale</a></li>
            @if ($home['city'] && $home['state'])<li><a href="{{ \Modules\Sites\Rendering\Urls::market(\Modules\Listings\Search\MarketStats::citySlug($home['city'], $home['state'])) }}">{{ $home['city'] }}</a></li>@endif
            <li aria-current="page">{{ $home['address'] ?? 'This home' }}</li>
        </ol>
    </nav>

    @php($photos = $home['photos'])
    <section class="gallery wrap" aria-label="Photos" data-gallery>
        @if ($photos === [])
            <div class="gallery-main card-placeholder" aria-hidden="true"></div>
        @else
            @foreach (array_slice($photos, 0, 5) as $i => $ph)
                <button type="button" class="{{ $i === 0 ? 'gallery-main' : 'gallery-thumb' }}" data-open="{{ $i }}" aria-label="Open photo {{ $i + 1 }} of {{ count($photos) }}">
                    <img src="{{ $i === 0 ? $ph['hero'] : $ph['url'] }}" alt="{{ ($ph['alt'] ?? null) ?: $name.' photo '.($i + 1) }}" @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async" width="{{ $i === 0 ? 1600 : 800 }}" height="{{ $i === 0 ? 1200 : 600 }}">
                </button>
            @endforeach
            @if (count($photos) > 5)<button type="button" class="btn btn-ghost gallery-all" data-open="0">All {{ count($photos) }} photos</button>@endif
            <dialog class="lightbox" data-lightbox aria-label="Photos">
                <button type="button" class="lb-close" data-close aria-label="Close">×</button>
                <button type="button" class="lb-prev" data-prev aria-label="Previous photo">‹</button>
                <img data-lb-img alt="">
                <button type="button" class="lb-next" data-next aria-label="Next photo">›</button>
                <p class="lb-count" data-lb-count></p>
                <script type="application/json" data-lb-photos>{!! json_encode(array_map(fn ($p) => ['src' => $p['hero'], 'alt' => ($p['alt'] ?? null) ?: $name], $photos), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) !!}</script>
            </dialog>
        @endif
    </section>

    <div class="wrap listing-grid">
        <article class="listing-main">
            <div hidden data-track="listing" data-listing="{{ $home['ulid'] }}"></div>
            <header class="listing-head">
                <div class="listing-actions">
                    <button type="button" class="btn btn-ghost" data-fav="{{ $home['ulid'] }}" aria-pressed="false">Save</button>
                    <button type="button" class="btn btn-ghost" data-cmp="{{ $home['ulid'] }}" aria-pressed="false">Compare</button>
                </div>
                <p class="status-line">
                    <span class="tag tag-inline">{{ $home['status'] === 'Active' ? 'For sale' : $home['status'] }}</span>
                    @if ($home['days_on_market'] !== null)<span>{{ $home['days_on_market'] }} days on market</span>@endif
                    @if ($home['mls_number'])<span>MLS# {{ $home['mls_number'] }}</span>@endif
                </p>
                <p class="listing-price">{{ $home['price'] !== null ? '$'.number_format($home['price']) : 'Price on request' }}</p>
                @if ($home['sample'] ?? false)<p class="sample-note" role="note">Sample listing: shown as an example while this website's MLS listings are being set up. It is not for sale through this website.</p>@endif
                <h1 class="listing-title">{{ $home['address'] ?? 'Home for sale' }}<span>{{ $place }}</span></h1>
                <ul class="key-facts">
                    <li><strong>{{ $home['beds'] ?? '—' }}</strong> beds</li>
                    <li><strong>{{ $baths ?? '—' }}</strong> baths</li>
                    @if ($home['sqft'])<li><strong>{{ number_format($home['sqft']) }}</strong> sq ft</li>@endif
                    @if ($home['price'] && $home['sqft'])<li><strong>${{ number_format((int) round($home['price'] / $home['sqft'])) }}</strong> per sq ft</li>@endif
                </ul>
            </header>

            @if ($home['remarks'])
                <section class="block">
                    <h2>About this home</h2>
                    <p class="remarks">{{ $home['remarks'] }}</p>
                    @if ($home['virtual_tour'])<p><a class="btn btn-ghost" href="{{ $home['virtual_tour'] }}" target="_blank" rel="noopener nofollow">Virtual tour</a></p>@endif
                </section>
            @endif

            @if (($home['features'] ?? []) !== [])
                <section class="block">
                    <h2>Seen in the photos</h2>
                    <ul class="feature-list">@foreach ($home['features'] as $f)<li>{{ $f }}</li>@endforeach</ul>
                    <p class="fine">Spotted in the listing photos; confirm features with us.</p>
                </section>
            @endif

            <section class="block">
                <h2>Details</h2>
                <dl class="details">
                    @foreach (array_filter(['Type' => $home['property_sub_type'] ?? $home['property_type'], 'Year built' => $home['year_built'], 'Lot size' => $home['lot_sqft'] ? number_format($home['lot_sqft']).' sq ft' : null,
                        'Neighborhood' => $home['subdivision'], 'ZIP code' => $home['postal_code'], 'Listed' => $home['listed'] ? \Illuminate\Support\Carbon::parse($home['listed'])->format('M j, Y') : null]) as $k => $v)
                        <div><dt>{{ $k }}</dt><dd>{{ $v }}</dd></div>
                    @endforeach
                </dl>
            </section>

            @if (count($home['price_history']) > 0)
                <section class="block">
                    <h2>Price history</h2>
                    <table class="history">
                        <thead><tr><th scope="col">Date</th><th scope="col">Event</th><th scope="col">Price</th></tr></thead>
                        <tbody>
                            @if ($home['listed'])<tr><td>{{ \Illuminate\Support\Carbon::parse($home['listed'])->format('M j, Y') }}</td><td>Listed</td><td>{{ $home['original_price'] ? '$'.number_format($home['original_price']) : '' }}</td></tr>@endif
                            @foreach ($home['price_history'] as $h)
                                <tr><td>{{ \Illuminate\Support\Carbon::parse($h['date'])->format('M j, Y') }}</td><td>{{ $h['from'] !== null && $h['to'] < $h['from'] ? 'Price cut' : 'Price change' }}</td>
                                    <td>${{ number_format($h['to']) }}@if ($h['from'] !== null && $h['from'] > 0) <span class="delta">({{ $h['to'] < $h['from'] ? '−' : '+' }}{{ number_format(abs($h['to'] - $h['from']) * 100 / $h['from'], 1) }}%)</span>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            @if ($home['price'])
                <section class="block" data-mortgage data-price="{{ $mortgage['price'] }}" data-listing="{{ $home['ulid'] }}">
                    <h2>Monthly cost estimate</h2>
                    <p class="monthly"><strong data-monthly>{{ $mortgage['monthly'] !== null ? '$'.number_format($mortgage['monthly']) : '' }}</strong> per month</p>
                    <div class="calc">
                        <label>Down payment (%) <input type="number" inputmode="decimal" min="0" max="100" step="1" value="{{ $mortgage['down_pct'] }}" data-down></label>
                        <label>Interest rate (%) <input type="number" inputmode="decimal" min="0" max="20" step="0.125" value="{{ $mortgage['rate'] }}" data-rate></label>
                        <label>Loan term (years) <select data-years>@foreach ([30, 20, 15] as $y)<option @selected($mortgage['years'] === $y)>{{ $y }}</option>@endforeach</select></label>
                        <label>Property tax (%/yr) <input type="number" inputmode="decimal" min="0" max="5" step="0.05" value="{{ $mortgage['tax_rate'] }}" data-tax></label>
                        <label>Insurance ($/mo) <input type="number" inputmode="numeric" min="0" step="10" value="{{ $mortgage['insurance'] }}" data-ins></label>
                    </div>
                    <p class="fine">Estimate for principal, interest, property tax and insurance; not a loan offer. HOA dues and mortgage insurance are not included.</p>
                </section>
            @endif

            @if ($map)
                <section class="block">
                    <h2>Location</h2>
                    <img class="static-map" src="{{ $map }}" alt="Map showing {{ $name }}" loading="lazy" width="720" height="360">
                    <p><a href="/market/{{ \Modules\Listings\Search\MarketStats::citySlug((string) $home['city'], (string) $home['state']) }}">{{ $home['city'] }} market report</a>@if ($home['postal_code']) · <a href="/market/{{ $home['postal_code'] }}">{{ $home['postal_code'] }} market report</a>@endif</p>
                </section>
            @endif

            @if ($home['nearby'] ?? null)
                <section class="block nearby">
                    <h2>What's nearby</h2>
                    <p>{{ $home['nearby']['summary'] }}</p>
                    @foreach (['Schools' => $home['nearby']['schools'], 'Transit' => $home['nearby']['transit'], 'Groceries and coffee' => $home['nearby']['shops'] ?? []] as $heading => $places)
                        @if ($places !== [])
                            <h3>{{ $heading }}</h3>
                            <table class="history">
                                <thead><tr><th scope="col">Name</th><th scope="col">Type</th><th scope="col">Distance</th></tr></thead>
                                <tbody>@foreach ($places as $pl)<tr><td>{{ $pl['name'] }}</td><td>{{ $pl['type'] }}</td><td>{{ $pl['miles'] }}</td></tr>@endforeach</tbody>
                            </table>
                        @endif
                    @endforeach
                    <p class="fine">Straight-line distances. School attendance depends on the school district; check with the district before you decide. {{ $home['nearby']['credit'] }}</p>
                </section>
            @endif

            <footer class="block mls">
                @if ($home['logo'])<img src="{{ $home['logo'] }}" alt="MLS" height="28" loading="lazy">@endif
                <p>{{ $home['attribution'] }}</p>
                @if ($home['stale'])<p class="fine">Listing updates are delayed; check with us for the latest status.</p>@endif
            </footer>
        </article>

        <aside class="listing-side">
            @include('sites::partials.lead-form', ['return' => $path, 'listing' => $home['ulid'], 'heading' => 'Ask about this home',
                'message' => 'I would like more information about '.($home['address'] ?? 'this home').'.', 'button' => 'Request info'])
            @if (! empty($site->info['phone']))<p class="side-call">Prefer to talk? <a href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">{{ $site->info['phone'] }}</a></p>@endif
        </aside>
    </div>

    @if ($similar !== [])
        <section class="section section-alt">
            <div class="wrap">
                <h2 class="section-title">Similar homes nearby</h2>
                <ul class="cards">@include('sites::idx.cards', ['listings' => $similar])</ul>
            </div>
        </section>
    @endif
</main>
@endsection

@push('scripts')
<script nonce="{{ Vite::cspNonce() }}">
(function(){
var g=document.querySelector('[data-gallery]'),d=g&&g.querySelector('[data-lightbox]');
if(d){var ph=JSON.parse(d.querySelector('[data-lb-photos]').textContent),img=d.querySelector('[data-lb-img]'),c=d.querySelector('[data-lb-count]'),i=0;
function show(n){i=(n+ph.length)%ph.length;img.src=ph[i].src;img.alt=ph[i].alt;c.textContent=(i+1)+' / '+ph.length;}
g.addEventListener('click',function(e){var b=e.target.closest('[data-open]');if(b){show(+b.getAttribute('data-open'));d.showModal();}});
d.querySelector('[data-close]').onclick=function(){d.close();};d.querySelector('[data-prev]').onclick=function(){show(i-1);};d.querySelector('[data-next]').onclick=function(){show(i+1);};
d.addEventListener('keydown',function(e){if(e.key==='ArrowLeft')show(i-1);if(e.key==='ArrowRight')show(i+1);});
var x0=null;d.addEventListener('touchstart',function(e){x0=e.touches[0].clientX;},{passive:true});d.addEventListener('touchend',function(e){if(x0===null)return;var dx=e.changedTouches[0].clientX-x0;if(Math.abs(dx)>40)show(i+(dx<0?1:-1));x0=null;});}
var m=document.querySelector('[data-mortgage]');
if(m){var price=+m.getAttribute('data-price'),q=function(s){return m.querySelector(s);},out=q('[data-monthly]');
function calc(){var down=+q('[data-down]').value||0,r=(+q('[data-rate]').value||0)/1200,n=(+q('[data-years]').value||30)*12,loan=price*(1-down/100);
var pi=r>0?loan*r/(1-Math.pow(1+r,-n)):loan/n,tot=pi+price*(+q('[data-tax]').value||0)/1200+(+q('[data-ins]').value||0);out.textContent='$'+Math.round(tot).toLocaleString('en-US');}
m.addEventListener('input',calc);}
})();
</script>
@endpush

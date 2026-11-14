<!DOCTYPE html>
<html lang="en" class="theme-{{ $site->template }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:type" content="{{ $seo['og_type'] ?? 'website' }}">
    <meta property="og:site_name" content="{{ $site->displayName() }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    @if ($seo['image'])<meta property="og:image" content="{{ $seo['image'] }}"><meta name="twitter:image" content="{{ $seo['image'] }}">@endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="{{ $site->template === 'aurum' ? '#0b0b0d' : '#ffffff' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @if ($site->template === 'aurum')
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600&display=swap">
    @else
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    @endif
    <link rel="stylesheet" href="/site-assets/site.css?v=3">
    @stack('head')
    <style>:root{--brand: {{ $site->brand['primary'] ?? '#1d4ed8' }};--accent: {{ $site->brand['accent'] ?? '#0f172a' }};}</style>
    <script type="application/ld+json">{!! $seo['jsonld'] !!}</script>
</head>
<body data-signup-after="{{ $chrome['signup_after'] }}">
<a class="skip" href="#main">Skip to content</a>
<header class="site-header" data-header>
    <div class="wrap header-row">
        <a class="brand" href="/" aria-label="{{ $site->displayName() }} home">
            @if (! empty($site->brand['logo_url']))<img src="{{ $site->brand['logo_url'] }}" alt="{{ $site->displayName() }}" height="40">@else<span>{{ $site->displayName() }}</span>@endif
        </a>
        <nav aria-label="Main">
            <ul class="nav">
                @foreach ($chrome['menu'] as $item)
                    <li><a href="{{ $item['path'] }}" @if ($item['path'] === $current || ($item['path'] !== '/' && str_starts_with($current, $item['path'].'/'))) aria-current="page" @endif>{{ $item['title'] }}</a></li>
                @endforeach
            </ul>
        </nav>
        <button type="button" class="btn btn-ghost header-account" data-account>Sign in</button>
        @if (! empty($site->info['phone']))<a class="btn btn-ghost header-call" href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">{{ $site->info['phone'] }}</a>@endif
    </div>
</header>
@yield('content')
<footer class="site-footer">
    <div class="wrap footer-grid">
        <div>
            <p class="footer-name">{{ $site->displayName() }}</p>
            @if (! empty($site->info['street']))<p>{{ $site->info['street'] }}, {{ $site->info['city'] ?? '' }}, {{ $site->info['state'] ?? '' }} {{ $site->info['zip'] ?? '' }}</p>@endif
            @if (! empty($site->info['phone']))<p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $site->info['phone']) }}">{{ $site->info['phone'] }}</a></p>@endif
            @if (! empty($site->info['email']))<p><a href="mailto:{{ $site->info['email'] }}">{{ $site->info['email'] }}</a></p>@endif
            @if (! empty($site->info['licence']))<p>Licence {{ $site->info['licence'] }}</p>@endif
        </div>
        <div>
            <ul class="footer-links">
                @foreach ($chrome['footer'] as $item)<li><a href="{{ $item['path'] }}">{{ $item['title'] }}</a></li>@endforeach
            </ul>
            @if (! empty($site->info['social']))
                <ul class="social">
                    @foreach ($site->info['social'] as $network => $url)<li><a href="{{ $url }}" rel="me noopener" target="_blank">{{ ucfirst($network) }}</a></li>@endforeach
                </ul>
            @endif
        </div>
        <div class="footer-legal">
            <p class="eho"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 1 11h3v11h16V11h3L12 2zm-5 12h10v2H7v-2zm0 4h10v2H7v-2z"/></svg> Equal Housing Opportunity</p>
            @foreach ($chrome['disclaimers'] as $d)<p class="disclaimer">{{ $d }}</p>@endforeach
            <p class="privacy">We keep a note of the homes you view here so our agents can help you better. No third-party trackers; your browser's Global Privacy Control is honored.</p>
            <p>&copy; {{ $chrome['year'] }} {{ $site->displayName() }}</p>
            @if ($chrome['powered'])<p class="powered"><a href="{{ $chrome['powered']['url'] }}" rel="noopener" target="_blank">{{ $chrome['powered']['text'] }}</a></p>@endif
        </div>
    </div>
</footer>
<dialog class="auth" data-auth aria-labelledby="auth-title">
    <form method="dialog" class="auth-close"><button aria-label="Close">×</button></form>
    <h2 id="auth-title" data-auth-title>Save homes and get price-drop alerts</h2>
    <p class="lead-muted" data-auth-lead>Create a free account to save homes, compare them, and hear first when prices change.</p>
    <form data-auth-start>
        <label>Name <input name="name" autocomplete="name" required></label>
        <label>Email <input type="email" name="email" autocomplete="email" required></label>
        <label>Mobile phone (optional) <input type="tel" name="phone" autocomplete="tel"></label>
        <label class="consent"><input type="checkbox" name="sms_consent" value="1"> <span>{{ $chrome['consent'] }}</span></label>
        <button class="btn btn-primary" type="submit">Email me a code</button>
    </form>
    <form data-auth-verify hidden>
        <label>6-digit code <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required></label>
        <button class="btn btn-primary" type="submit">Sign in</button>
    </form>
    <div data-auth-me hidden>
        <p data-auth-hello></p>
        <p><a class="btn btn-primary" data-auth-saved href="/homes/compare">See saved homes</a></p>
        <p><button type="button" class="btn btn-ghost" data-auth-out>Sign out on this device</button></p>
    </div>
    <p class="form-error" role="alert" data-auth-error hidden></p>
</dialog>
<div class="compare-tray" data-compare-tray hidden><span data-compare-count></span> <a class="btn btn-primary" data-compare-go href="/homes/compare">Compare</a> <button type="button" class="btn btn-ghost" data-compare-clear>Clear</button></div>
<script>
document.addEventListener('click',function(e){var b=e.target.closest('.video-play');if(!b)return;var v=b.parentNode,f=document.createElement('iframe');
f.src=v.getAttribute('data-video');f.title=b.getAttribute('aria-label');f.allow='autoplay; encrypted-media; picture-in-picture; fullscreen';f.loading='lazy';v.replaceChildren(f);});
(function(){var h=document.querySelector('[data-header]');function s(){h&&h.classList.toggle('scrolled',window.scrollY>24)}s();window.addEventListener('scroll',s,{passive:true});
try{var k='ra_first_touch',st=window.localStorage,v=JSON.parse(st.getItem(k)||'null');if(!v){v={r:document.referrer,l:location.href};st.setItem(k,JSON.stringify(v));}
document.querySelectorAll('form[data-lead]').forEach(function(f){f.first_referrer.value=v.r;f.landing_url.value=v.l;});}catch(e){}})();
</script>
<script src="/site-assets/visitor.js?v=1" defer></script>
@stack('scripts')
</body>
</html>

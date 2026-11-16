<!DOCTYPE html>
<html lang="en" class="theme-{{ $chrome['template'] }}">
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
    @php($design = (array) config('sites.templates.'.$chrome['template'], config('sites.templates.modern')))
    <meta name="theme-color" content="{{ $design['theme'] ?? '#ffffff' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?{{ $design['css'] ?? 'family=Inter:wght@400;500;600;700' }}&display=swap">
    <link rel="stylesheet" href="/site-assets/site.css?v=5">
    @stack('head')
    <style>:root{--brand: {{ $chrome['brand']['primary'] ?? '#1d4ed8' }};--accent: {{ $chrome['brand']['accent'] ?? '#0f172a' }};}</style>
    <script type="application/ld+json">{!! $seo['jsonld'] !!}</script>
</head>
<body data-signup-after="{{ $chrome['signup_after'] }}">
<a class="skip" href="#main">Skip to content</a>
<header class="site-header" data-header>
    <div class="wrap header-row">
        @if ($chrome['agent'])
            <a class="brand brand-agent" href="{{ $chrome['agent']['home'] }}" aria-label="{{ $chrome['agent']['name'] }} home">
                @if ($chrome['agent']['photo'])<img class="agent-avatar" src="{{ $chrome['agent']['photo'] }}" alt="" width="40" height="40">@endif
                <span>{{ $chrome['agent']['name'] }}<small>{{ $site->displayName() }}</small></span>
            </a>
        @else
            <a class="brand" href="/" aria-label="{{ $site->displayName() }} home">
                @if (! empty($site->brand['logo_url']))<img src="{{ $site->brand['logo_url'] }}" alt="{{ $site->displayName() }}" height="40">@else<span>{{ $site->displayName() }}</span>@endif
            </a>
        @endif
        @if (! ($landing ?? false))
        <nav aria-label="Main">
            <ul class="nav">
                @foreach ($chrome['menu'] as $item)
                    <li><a href="{{ $item['path'] }}" @if ($item['path'] === $current || ($item['path'] !== '/' && str_starts_with($current, $item['path'].'/'))) aria-current="page" @endif>{{ $item['title'] }}</a></li>
                @endforeach
            </ul>
        </nav>
        <button type="button" class="btn btn-ghost header-account" data-account>Sign in</button>
        @endif
        @php($callPhone = ($chrome['agent']['phone'] ?? '') !== '' ? $chrome['agent']['phone'] : (string) ($site->info['phone'] ?? ''))
        @if (! empty($callPhone))<a class="btn btn-ghost header-call" href="tel:{{ preg_replace('/[^0-9+]/', '', $callPhone) }}">{{ $callPhone }}</a>@endif
    </div>
</header>
@yield('content')
<footer class="site-footer">
    <div class="wrap footer-grid">
        <div>
            @if ($chrome['agent'])
                <p class="footer-name">{{ $chrome['agent']['name'] }}</p>
                @if ($chrome['agent']['licence'])<p>{{ $chrome['agent']['licence']['state'] ? $chrome['agent']['licence']['state'].' ' : '' }}licence {{ $chrome['agent']['licence']['number'] }}</p>@endif
                @if ($chrome['agent']['phone'])<p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $chrome['agent']['phone']) }}">{{ $chrome['agent']['phone'] }}</a></p>@endif
                @if ($chrome['agent']['email'])<p><a href="mailto:{{ $chrome['agent']['email'] }}">{{ $chrome['agent']['email'] }}</a></p>@endif
                <p class="brokered">Brokered by</p>
            @endif
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
            <p class="privacy">We keep a note of the homes you view here so our agents can help you better. No third-party trackers; your browser's Global Privacy Control is honored. <a href="/privacy">Privacy policy</a></p>
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
@if (! empty($chrome['ailly']))
@php($ally = $chrome['ailly'])
<div class="ailly" data-ailly="{{ $ally['name'] }}" data-consent="{{ $chrome['consent'] }}"@if ($ally['nudge']) data-nudge @endif>
    <template data-ailly-avatar>@if ($ally['photo'])<img class="ailly-avatar" src="{{ $ally['photo'] }}" alt="" width="32" height="32">@else<svg class="ailly-avatar" viewBox="0 0 64 64" aria-hidden="true" focusable="false"><circle cx="32" cy="32" r="32" fill="var(--brand)"/><path d="M16 31 32 18l16 13" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 29v15h22V29" fill="none" stroke="#fff" stroke-width="4" stroke-linejoin="round"/><rect x="26" y="33" width="12" height="7" rx="3.5" fill="#fff"/><circle cx="29.5" cy="36.5" r="1.3" fill="var(--brand)"/><circle cx="34.5" cy="36.5" r="1.3" fill="var(--brand)"/></svg>@endif</template>
    <section class="ailly-panel" data-ailly-panel role="dialog" aria-label="Chat with {{ $ally['name'] }}" hidden>
        <header class="ailly-head"><span class="ailly-face" data-ailly-face></span><span class="ailly-who"><strong>{{ $ally['name'] }}</strong> <span>AI assistant · an agent can take over any time</span></span>
            <button type="button" class="ailly-x" data-ailly-close aria-label="Close chat">&times;</button></header>
        <div class="ailly-log" data-ailly-log aria-live="polite"></div>
        <form class="ailly-form" data-ailly-form>
            <input type="text" name="website" class="ailly-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="sr-only" for="ailly-input">Message</label>
            <input id="ailly-input" name="message" maxlength="800" autocomplete="off" placeholder="Ask about homes, the area or selling…">
            <button type="submit" class="btn btn-primary">Send</button>
        </form>
        <p class="ailly-note">Answers are AI-generated and may be wrong. Don't share financial details here.</p>
    </section>
    <button type="button" class="ailly-open" data-ailly-open aria-expanded="false"><span class="ailly-face" data-ailly-face></span><span>{{ $ally['name'] }}</span></button>
</div>
<script src="/site-assets/ailly.js?v=4" defer></script>
@endif
@stack('scripts')
</body>
</html>

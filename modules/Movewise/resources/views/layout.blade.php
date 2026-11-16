{{--
    Movewise Homes layout (specs/movewise/01): server-rendered, no cookies, no third-party trackers. Every page has
    one canonical address and schema.org markup. The footer names the broker of record in each state that is on and,
    when Texas is on, carries TREC's two required notices.
--}}
<!DOCTYPE html>
<html lang="{{ \Modules\Movewise\I18n::locale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['indexable'] ? 'index, follow' : 'noindex, follow' }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    @foreach ($seo['alternates'] ?? [] as $hl => $href)<link rel="alternate" hreflang="{{ $hl }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Movewise Homes">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta name="theme-color" content="#0f3d3e">
    <script type="application/ld+json">{!! $seo['jsonld'] !!}</script>
    <style>
        :root{--ink:#14211f;--muted:#566360;--line:#dfe6e4;--bg:#fbfcfb;--card:#fff;--brand:#0f3d3e;--brand-2:#e2f0ec;--accent:#d9822b;--ok:#1d7a4c;--radius:14px}
        @media (prefers-color-scheme:dark){:root{--ink:#e9efed;--muted:#a3b0ad;--line:#2a3533;--bg:#0d1413;--card:#141d1c;--brand:#7fd1c2;--brand-2:#173230;--accent:#f0a65a}}
        *{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 17px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        a{color:var(--brand)}a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:2px}
        .wrap{max-width:1180px;margin:0 auto;padding:0 16px}
        .skip{position:absolute;left:-999px}.skip:focus{left:16px;top:8px;background:var(--card);padding:8px 12px;z-index:9}
        header.top{border-bottom:1px solid var(--line);background:var(--card)}
        header.top .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:64px}
        .brand{font-weight:800;font-size:20px;letter-spacing:-.02em;color:var(--ink);text-decoration:none}.brand span{color:var(--brand)}
        nav.main a{margin-left:18px;color:var(--ink);text-decoration:none;font-weight:600}nav.main a:hover{color:var(--brand)}
        h1{font-size:clamp(28px,4.5vw,46px);line-height:1.1;letter-spacing:-.02em;margin:0 0 12px}
        h2{font-size:clamp(21px,2.6vw,28px);line-height:1.2;margin:32px 0 12px}
        .lead{color:var(--muted);font-size:19px;max-width:62ch}
        .hero{padding:48px 0 32px;background:linear-gradient(180deg,var(--brand-2),transparent)}
        form.find{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px;max-width:760px}
        form.find input,form.find select{flex:1 1 180px;min-height:52px;border:1px solid var(--line);border-radius:12px;padding:0 14px;font:inherit;background:var(--card);color:var(--ink)}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 22px;border-radius:12px;border:0;background:var(--brand);color:#fff;font:600 17px/1 inherit;cursor:pointer;text-decoration:none}
        @media (prefers-color-scheme:dark){.btn{color:#08201d}}
        .promises{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:28px 0;padding:0;list-style:none}
        .promises li{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px}.promises strong{display:block;margin-bottom:4px}
        .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;padding:0;list-style:none}
        .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column}
        .card img{width:100%;aspect-ratio:4/3;object-fit:cover;background:var(--brand-2)}
        .card .body{padding:12px 14px 14px;display:grid;gap:2px}.price{font-size:22px;font-weight:800}
        .muted{color:var(--muted)}.small{font-size:14px}
        .attr{font-size:13px;color:var(--muted)}
        .pager{display:flex;gap:8px;margin:24px 0;flex-wrap:wrap}.pager a,.pager span{padding:8px 14px;border:1px solid var(--line);border-radius:10px;text-decoration:none}
        table.cost{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
        table.cost td,table.cost th{padding:10px 12px;border-top:1px solid var(--line);text-align:left;vertical-align:top}table.cost tr:first-child td{border-top:0}
        table.cost td.amt{text-align:right;font-weight:700;white-space:nowrap}table.cost tfoot td{font-size:19px;font-weight:800;background:var(--brand-2)}
        .gallery{display:grid;grid-template-columns:2fr 1fr;gap:8px;margin:16px 0}.gallery img{width:100%;height:100%;object-fit:cover;border-radius:10px;aspect-ratio:4/3}
        .gallery .side{display:grid;gap:8px}@media (max-width:700px){.gallery{grid-template-columns:1fr}.gallery .side{display:none}}
        .facts{display:flex;flex-wrap:wrap;gap:8px 18px;font-size:18px;margin:4px 0 16px}
        .note{border-left:4px solid var(--accent);background:var(--card);padding:10px 14px;border-radius:8px}
        .prose{max-width:72ch}.prose h2{font-size:22px}
        footer{margin-top:56px;border-top:1px solid var(--line);background:var(--card);padding:28px 0 40px;font-size:14px;color:var(--muted)}
        footer a{color:var(--muted)}footer .cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px}
        footer .notices a{font-size:14px}
        .eho{display:inline-flex;gap:6px;align-items:center}
        .btn.ghost{background:transparent;color:var(--brand);border:1px solid var(--line)}
        .save{min-height:40px;padding:0 14px;border-radius:10px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:600 15px/1 inherit;cursor:pointer}
        .save[aria-pressed=true]{background:var(--brand-2);border-color:var(--brand)}
        dialog{border:1px solid var(--line);border-radius:var(--radius);background:var(--card);color:var(--ink);padding:20px;width:min(520px,calc(100vw - 32px))}
        dialog::backdrop{background:rgb(0 0 0 / .45)}
        dialog form{display:grid;gap:10px}dialog input,form.tool input,form.tool select,form.plan input{min-height:48px;border:1px solid var(--line);border-radius:10px;padding:0 12px;font:inherit;background:var(--bg);color:var(--ink)}
        .chat{max-height:50vh;overflow:auto;display:grid;gap:8px;margin:8px 0}.chat p{margin:0;padding:10px 12px;border-radius:12px;background:var(--bg);white-space:pre-wrap}.chat p.me{background:var(--brand-2);justify-self:end}
        #mw-ailly-open{position:fixed;right:16px;bottom:16px;z-index:5;box-shadow:0 6px 24px rgb(0 0 0 / .2)}
        form.tool{display:grid;gap:12px;max-width:560px}form.tool label{display:grid;gap:4px;font-weight:600}
        .tasks{list-style:none;padding:0}.tasks li{padding:6px 0}
        [hidden]{display:none!important}
    </style>
</head>
<body>
<a class="skip" href="#main">{{ $t('layout.skip') }}</a>
<header class="top">
    <div class="wrap">
        <a class="brand" href="{{ $u('/') }}" aria-label="Movewise Homes home">Move<span>wise</span> Homes</a>
        <nav class="main" aria-label="Main">
            @if ($site['states'] !== [])<a href="{{ $u('/homes') }}">{{ $t('nav.homes') }}</a>@endif
            <a href="{{ $u('/afford') }}">{{ $t('nav.tools') }}</a>
            <a href="{{ $u('/how-movewise-is-paid') }}">{{ $t('nav.paid') }}</a>
            <a href="{{ $u('/account') }}" data-account>{{ $t('nav.account') }}</a>
            @isset($seo['alternates'])<a href="{{ \Modules\Movewise\I18n::locale() === 'es' ? $seo['alternates']['en'] : $seo['alternates']['es'] }}" hreflang="{{ \Modules\Movewise\I18n::locale() === 'es' ? 'en' : 'es' }}" lang="{{ \Modules\Movewise\I18n::locale() === 'es' ? 'en' : 'es' }}">{{ $t('nav.lang') }}</a>@endisset
        </nav>
    </div>
</header>
<main id="main">
    @yield('main')
</main>
<footer>
    <div class="wrap cols">
        <div>
            <p>{{ $t('footer.about') }}</p>
            @if ($t('footer.english_only') !== '')<p>{{ $t('footer.english_only') }}</p>@endif
            <p class="eho"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 1 11h3v11h16V11h3L12 2zm-5 12h10v2H7v-2zm0 4h10v2H7v-2z"/></svg> {{ $t('layout.eho') }}</p>
            <p>&copy; {{ $site['year'] }} RightAlly. {{ $t('layout.not_lender') }}</p>
        </div>
        <div>
            <p><strong>{{ $t('footer.brokers') }}</strong></p>
            @forelse ($site['states'] as $s)
                @if ($s['partner'])<p>{{ $t('layout.broker_line', ['state' => $s['name'], 'name' => $s['partner']['name'], 'licence' => $s['partner']['licence'], 'broker' => $s['partner']['broker']]) }}</p>@endif
            @empty
                <p>{{ $t('layout.brokers_page') }}</p>
            @endforelse
            @php $tx = collect($site['states'])->firstWhere('code', 'TX'); @endphp
            @if ($tx)
                <p class="notices"><a href="{{ $tx['partner']['iabs'] ?? 'https://www.trec.texas.gov/forms/information-about-brokerage-services' }}" rel="noopener">Texas Real Estate Commission Information About Brokerage Services</a><br>
                <a href="https://www.trec.texas.gov/forms/consumer-protection-notice" rel="noopener">Texas Real Estate Commission Consumer Protection Notice</a></p>
            @endif
        </div>
        <div>
            <p><a href="{{ $u('/how-movewise-is-paid') }}">{{ $t('nav.paid') }}</a><br><a href="{{ $u('/afford') }}">{{ $t('tools.afford') }}</a><br><a href="{{ $u('/net-sheet') }}">{{ $t('tools.net') }}</a><br><a href="{{ $u('/move-planner') }}">{{ $t('tools.planner') }}</a><br><a href="{{ $u('/home-value') }}">{{ $t('value.title') }}</a><br><a href="/agents">{{ $t('layout.for_agents') }}</a><br><a href="{{ $u('/brokers') }}">{{ $t('layout.brokers') }}</a><br><a href="{{ $u('/fair-housing') }}">{{ $t('layout.fair_housing') }}</a><br>
            <a href="{{ $u('/privacy') }}">{{ $t('layout.privacy') }}</a><br><a href="{{ $u('/terms') }}">{{ $t('layout.terms') }}</a><br><a href="{{ $u('/accessibility') }}">{{ $t('layout.accessibility') }}</a></p>
        </div>
    </div>
</footer>
@unless (isset($plain))
<button class="btn" id="mw-ailly-open" type="button" hidden>{{ $t('ailly.open') }}</button>
<dialog id="mw-ailly" aria-labelledby="mw-ailly-h">
    <h2 id="mw-ailly-h" style="margin-top:0">{{ $t('ailly.title') }}</h2>
    <div class="chat" aria-live="polite"><p>{{ $t('ailly.intro') }}</p></div>
    <form data-ailly>
        <label class="skip" for="mw-ailly-q">{{ $t('ailly.placeholder') }}</label>
        <input id="mw-ailly-q" name="message" maxlength="1000" required placeholder="{{ $t('ailly.placeholder') }}" autocomplete="off">
        <div style="display:flex;gap:8px"><button class="btn">{{ $t('ailly.send') }}</button><button class="btn ghost" type="button" data-close>×</button></div>
    </form>
</dialog>
<dialog id="mw-signin" aria-labelledby="mw-signin-h">
    <h2 id="mw-signin-h" style="margin-top:0">{{ $t('signin.title') }}</h2>
    @include('movewise::_signin')
</dialog>
<script type="application/json" id="mw-i18n">{!! json_encode(['saved' => $t('saved'), 'save' => $t('save'), 'error' => $t('ailly.error'), 'search_saved' => $t('search.saved'), 'prefix' => $u('')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) !!}</script>
<script src="/assets/movewise.js?v=2" defer></script>
@endunless
</body>
</html>

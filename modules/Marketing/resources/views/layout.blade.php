{{--
    RightAlly's public website (SG1, specs/marketing/01): server-rendered, readable without JavaScript, large text and
    big targets for every reader, built to the SEO and AEO standard (specs/websites/04).
--}}
<!DOCTYPE html>
<html lang="{{ $L }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $preview ? 'noindex, nofollow' : 'index, follow, max-snippet:-1, max-image-preview:large' }}">
    <link rel="canonical" href="{{ $canonical }}">
    @foreach ($alternates as $hl => $href)<link rel="alternate" hreflang="{{ $hl }}" href="{{ $href }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="RightAlly">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ $L === 'es' ? 'es_US' : 'en_US' }}">
    <meta name="twitter:card" content="summary">
    <meta name="theme-color" content="#4338ca">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <script type="application/ld+json">{!! $jsonld !!}</script>
    <style>
        :root{--ink:#111827;--muted:#4b5563;--line:#e5e7eb;--bg:#f8fafc;--card:#fff;--brand:#4338ca;--brand-2:#eef2ff;--radius:14px}
        @media (prefers-color-scheme:dark){:root{--ink:#f3f4f6;--muted:#a1a1aa;--line:#2e3340;--bg:#0b0f19;--card:#111827;--brand:#a5b4fc;--brand-2:#1e1b4b}}
        *{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 19px/1.65 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        a{color:var(--brand)}:focus-visible{outline:3px solid var(--brand);outline-offset:2px}
        .wrap{max-width:1040px;margin:0 auto;padding:0 16px}
        .skip{position:absolute;left:-999px}.skip:focus{left:16px;top:8px;background:var(--card);padding:8px 12px;z-index:9}
        header.top{border-bottom:1px solid var(--line);background:var(--card)}
        header.top .wrap{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;min-height:68px;gap:8px 16px}
        .brand{font-weight:800;font-size:24px;color:var(--ink);text-decoration:none;letter-spacing:-.02em}.brand span{color:var(--brand)}
        nav.main{display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center}nav.main a{text-decoration:none;color:var(--ink);padding:10px 4px;font-weight:600}
        h1{font-size:clamp(32px,5vw,50px);line-height:1.1;letter-spacing:-.02em;margin:0 0 14px}
        h2{font-size:clamp(24px,3vw,32px);line-height:1.2;margin:0 0 14px}h3{margin:0 0 6px;font-size:21px}
        section{padding:36px 0}.lead{color:var(--muted);font-size:21px;max-width:62ch}.muted{color:var(--muted)}.small{font-size:16px}
        .hero{padding:56px 0 40px;background:linear-gradient(180deg,var(--brand-2),transparent)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
        .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:56px;padding:0 26px;border-radius:14px;border:0;background:var(--brand);color:#fff;font:700 19px/1 inherit;text-decoration:none;margin:6px 8px 0 0}
        @media (prefers-color-scheme:dark){.btn{color:#111827}}
        .btn.ghost{background:transparent;color:var(--brand);border:1px solid var(--line)}
        .answer{border-left:6px solid var(--brand);background:var(--card);border-radius:var(--radius);padding:18px 20px}
        details{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:14px 18px;margin:0 0 10px}summary{font-weight:700;cursor:pointer;min-height:32px}
        table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line)}td,th{padding:12px;border-top:1px solid var(--line);text-align:left;vertical-align:top}th{background:var(--bg)}
        .tablewrap{overflow-x:auto}.price{font-size:36px;font-weight:800}
        .doors .door{display:grid;gap:10px;text-decoration:none;color:var(--ink);border-width:2px}.doors .door:hover,.doors .door:focus-visible{border-color:var(--brand)}.doors .door h3{font-size:26px}
        ol.steps{counter-reset:s;list-style:none;padding:0;display:grid;gap:12px}ol.steps li{counter-increment:s;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px 16px 64px;position:relative}
        ol.steps li::before{content:counter(s);position:absolute;left:18px;top:14px;width:32px;height:32px;border-radius:50%;background:var(--brand);color:#fff;display:grid;place-items:center;font-weight:800}
        blockquote{margin:0}.prose{max-width:72ch}.prose img{max-width:100%;height:auto}
        footer{border-top:1px solid var(--line);padding:28px 0 40px;margin-top:24px;font-size:16px;color:var(--muted)}footer a{color:var(--muted)}
        .preview{background:#fef3c7;color:#78350f;text-align:center;padding:8px;font-weight:700}
    </style>
</head>
<body>
<a class="skip" href="#main">{{ $L === 'es' ? 'Ir al contenido' : 'Skip to content' }}</a>
@if ($preview)<div class="preview" role="status">{{ $L === 'es' ? 'Vista previa privada: aún no se publica' : 'Private preview: not published yet' }}</div>@endif
<header class="top"><div class="wrap">
    <a class="brand" href="{{ $home }}">Right<span>Ally</span></a>
    <nav class="main" aria-label="{{ $L === 'es' ? 'Principal' : 'Main' }}">
        @foreach ($nav as $item)<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@endforeach
        <a href="{{ $L === 'es' ? rtrim($home, '/').'/blog' : rtrim($home, '/').'/blog' }}">Blog</a>
        <a href="{{ $other }}" hreflang="{{ $L === 'es' ? 'en' : 'es' }}" lang="{{ $L === 'es' ? 'en' : 'es' }}">{{ $L === 'es' ? 'English' : 'Español' }}</a>
        <a class="btn" href="{{ $start }}">{{ $L === 'es' ? 'Empezar' : 'Start now' }}</a>
    </nav>
</div></header>
<main id="main">
    @yield('content')
</main>
<footer><div class="wrap">
    <p><strong>RightAlly</strong> · {{ $L === 'es' ? 'Todo lo que su correduría necesita, en un solo lugar.' : 'Everything your brokerage runs on, in one place.' }}</p>
    <p><a href="{{ $start }}">{{ $L === 'es' ? 'Registrarse' : 'Sign up' }}</a> · <a href="{{ $healthCheck }}">{{ $L === 'es' ? 'Chequeo gratuito' : 'Free Health Check' }}</a>
        · <a href="/login">{{ $L === 'es' ? 'Iniciar sesión' : 'Sign in' }}</a> · <a href="/sitemap.xml">Sitemap</a></p>
</div></footer>
</body>
</html>

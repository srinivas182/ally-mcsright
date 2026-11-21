{{--
    Brokerage Health Check pages on the RightAlly host (specs/billing/06): server-rendered, readable without
    JavaScript, large text and big targets. The questionnaire is indexable with FAQ markup; reports and previews
    are private (noindex, no-store).
--}}
<!DOCTYPE html>
@php($L = $lang ?? 'en')
<html lang="{{ $L }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title }}</title>
    <link rel="icon" href="/favicon.ico?v=br1" sizes="any"><link rel="icon" href="/favicon.svg?v=br1" type="image/svg+xml">
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ ($indexable ?? false) ? 'index, follow' : 'noindex, nofollow' }}">
    @isset($canonical)<link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="RightAlly">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    @endisset
    @foreach ($alternates ?? [] as $hl => $href)<link rel="alternate" hreflang="{{ $hl }}" href="{{ $href }}">
    @endforeach
    <meta name="theme-color" content="#4338ca">
    @isset($jsonld)<script type="application/ld+json">{!! $jsonld !!}</script>@endisset
    <style>
        :root{--ink:#111827;--muted:#4b5563;--line:#e5e7eb;--bg:#f8fafc;--card:#fff;--brand:#4338ca;--brand-2:#eef2ff;--ok:#047857;--ok-2:#ecfdf5;--warn:#b45309;--warn-2:#fffbeb;--bad:#b91c1c;--bad-2:#fef2f2;--radius:14px}
        @media (prefers-color-scheme:dark){:root{--ink:#f3f4f6;--muted:#a1a1aa;--line:#2e3340;--bg:#0b0f19;--card:#111827;--brand:#a5b4fc;--brand-2:#1e1b4b;--ok:#6ee7b7;--ok-2:#052e22;--warn:#fcd34d;--warn-2:#3a2705;--bad:#fca5a5;--bad-2:#3b0d0d}}
        *{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 18px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        a{color:var(--brand)}:focus-visible{outline:3px solid var(--brand);outline-offset:2px}
        .wrap{max-width:980px;margin:0 auto;padding:0 16px}
        .skip{position:absolute;left:-999px}.skip:focus{left:16px;top:8px;background:var(--card);padding:8px 12px;z-index:9}
        header.top{border-bottom:1px solid var(--line);background:var(--card)}header.top .wrap{display:flex;align-items:center;justify-content:space-between;min-height:64px;gap:12px}
        .brand{font-weight:800;font-size:22px;color:var(--ink);text-decoration:none;letter-spacing:-.02em}.brand span{color:var(--brand)}
        h1{font-size:clamp(30px,5vw,46px);line-height:1.1;letter-spacing:-.02em;margin:0 0 12px}h2{font-size:clamp(22px,3vw,28px);line-height:1.2;margin:36px 0 12px}h3{margin:0 0 4px;font-size:20px}
        .lead{color:var(--muted);font-size:20px;max-width:62ch}.muted{color:var(--muted)}.small{font-size:15px}
        .hero{padding:44px 0 28px;background:linear-gradient(180deg,var(--brand-2),transparent)}
        .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px}
        fieldset{border:1px solid var(--line);border-radius:var(--radius);background:var(--card);padding:18px 20px 20px;margin:0 0 18px}legend{font-weight:800;font-size:20px;padding:0 6px}
        label{display:grid;gap:4px;font-weight:600;margin:10px 0}
        input[type=text],input[type=email],input[type=number],input[type=url],select{min-height:52px;border:1px solid var(--line);border-radius:12px;padding:0 14px;font:inherit;background:var(--bg);color:var(--ink);width:100%}
        input[type=color]{width:72px;height:52px;border:1px solid var(--line);border-radius:12px;background:var(--bg)}
        .check{display:flex;gap:10px;align-items:flex-start;font-weight:400}.check input{width:24px;height:24px;margin-top:3px;flex:none}
        .choices{display:grid;gap:8px;margin:6px 0 14px}.choices label{display:flex;gap:10px;align-items:center;margin:0;font-weight:400;border:1px solid var(--line);border-radius:12px;padding:10px 14px;background:var(--bg);min-height:52px}
        .choices input{width:22px;height:22px}
        .toolrow{display:grid;grid-template-columns:2fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px}@media (max-width:640px){.toolrow{grid-template-columns:1fr 1fr}.toolrow .check{grid-column:1/-1}}
        .two{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media (max-width:640px){.two{grid-template-columns:1fr}}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:56px;padding:0 26px;border-radius:14px;border:0;background:var(--brand);color:#fff;font:700 19px/1 inherit;cursor:pointer;text-decoration:none}
        @media (prefers-color-scheme:dark){.btn{color:#111827}}
        .btn.ghost{background:transparent;color:var(--brand);border:1px solid var(--line)}
        .errors{border:2px solid var(--bad);background:var(--bad-2);border-radius:12px;padding:12px 16px}
        .promises{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:24px 0;padding:0;list-style:none}.promises li{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px}.promises strong{display:block}
        .score{display:inline-grid;place-items:center;width:120px;height:120px;border-radius:50%;border:10px solid var(--brand);font-size:36px;font-weight:800}
        .areas{display:grid;gap:12px;padding:0;list-style:none}.areas li{border-radius:var(--radius);border:1px solid var(--line);background:var(--card);padding:16px 18px;border-left-width:8px}
        .good{border-left-color:var(--ok)!important}.fix{border-left-color:var(--warn)!important}.urgent{border-left-color:var(--bad)!important}.not_checked{border-left-color:var(--line)!important}
        .pill{display:inline-block;border-radius:999px;padding:2px 12px;font-size:15px;font-weight:700}.pill.good{background:var(--ok-2);color:var(--ok)}.pill.fix{background:var(--warn-2);color:var(--warn)}.pill.urgent{background:var(--bad-2);color:var(--bad)}.pill.not_checked{background:var(--bg);color:var(--muted)}
        table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
        td,th{padding:10px 12px;border-top:1px solid var(--line);text-align:left;vertical-align:top}th{background:var(--bg);font-size:15px}td.amt,th.amt{text-align:right;white-space:nowrap;font-weight:700}
        .tablewrap{overflow-x:auto}
        .ok{color:var(--ok);font-weight:700}.no{color:var(--bad);font-weight:700}
        .faq details{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 16px;margin-bottom:8px}.faq summary{font-weight:700;cursor:pointer}
        footer{margin-top:56px;border-top:1px solid var(--line);background:var(--card);padding:24px 0 36px;font-size:15px;color:var(--muted)}
        .hp{position:absolute;left:-5000px;width:1px;height:1px;overflow:hidden}
        .actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
    </style>
    @stack('head')
</head>
<body>
<a class="skip" href="#main">{{ \Modules\Billing\HealthCheck\HealthCopy::t($L, 'skip') }}</a>
<header class="top"><div class="wrap"><a class="brand" href="{{ $home ?? '/health-check' }}">Right<span>Ally</span></a><span class="muted small">{{ $section ?? \Modules\Billing\HealthCheck\HealthCopy::t($L, 'section') }}</span></div></header>
<main id="main">
    @yield('main')
</main>
<footer><div class="wrap">
    <p>{{ \Modules\Billing\HealthCheck\HealthCopy::t($L, 'footer1') }}</p>
    @unless ($signup ?? false)<p>{{ \Modules\Billing\HealthCheck\HealthCopy::t($L, 'footer2') }}</p>@endunless
</div></footer>
</body>
</html>

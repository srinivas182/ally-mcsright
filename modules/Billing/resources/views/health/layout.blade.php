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
    <meta name="theme-color" content="#04163F">
    @isset($jsonld)<script type="application/ld+json">{!! $jsonld !!}</script>@endisset
    <style>
        :root{--ink:#0A1330;--muted:#4E5B76;--line:#E3E8F2;--bg:#F7F9FD;--card:#fff;--tint:#F4F6FB;--brand:#155DFC;--brand-deep:#06369E;--brand-2:#EEF3FF;--navy:#04163F;--ok:#047857;--ok-2:#ecfdf5;--warn:#b45309;--warn-2:#fffbeb;--bad:#b91c1c;--bad-2:#fef2f2;--radius:14px;--shadow:0 1px 2px rgb(6 22 63/.04),0 14px 36px -18px rgb(6 22 63/.22)}
        *{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 17px/1.65 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
        a{color:var(--brand)}:focus-visible{outline:3px solid var(--brand);outline-offset:2px}
        .wrap{max-width:980px;margin:0 auto;padding:0 16px}
        .skip{position:absolute;left:-999px}.skip:focus{left:16px;top:8px;background:var(--card);padding:8px 12px;z-index:9}
        h1{font-size:clamp(30px,4.2vw,44px);line-height:1.1;letter-spacing:-.025em;margin:0 0 12px}h2{font-size:clamp(22px,3vw,28px);line-height:1.2;margin:36px 0 12px}h3{margin:0 0 4px;font-size:20px}
        .lead{color:var(--muted);font-size:20px;max-width:62ch}.muted{color:var(--muted)}.small{font-size:15px}
        .hero{padding:44px 0 28px;background:linear-gradient(180deg,var(--brand-2),transparent)}
        .card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px}
        fieldset{border:1px solid var(--line);border-radius:var(--radius);background:var(--card);padding:22px 24px 24px;margin:0 0 18px;min-width:0}legend{float:left;width:100%;font-weight:800;font-size:21px;line-height:1.3;padding:0 0 14px;margin:0 0 16px;border-bottom:1px solid var(--line)}legend+*{clear:both}h3.sub{font-size:17px;margin:18px 0 8px}.board-group{border-left:3px solid var(--brand,#1d4ed8);padding:2px 0 2px 14px;margin:0 0 12px}.board-group-title{margin:4px 0 6px}.board-group[hidden],[data-idx-none][hidden]{display:none}
        label{display:grid;align-content:start;gap:4px;font-weight:600;margin:10px 0}
        input[type=text],input[type=email],input[type=number],input[type=url],select{min-height:52px;border:1px solid var(--line);border-radius:12px;padding:0 14px;font:inherit;background:var(--bg);color:var(--ink);width:100%}
        input[type=color]{width:72px;height:52px;border:1px solid var(--line);border-radius:12px;background:var(--bg)}
        .check{display:flex;gap:10px;align-items:flex-start;font-weight:400}.check input{width:24px;height:24px;margin-top:3px;flex:none}
        .choices{display:grid;gap:8px;margin:6px 0 14px}.choices label{display:flex;gap:10px;align-items:center;margin:0;font-weight:400;border:1px solid var(--line);border-radius:12px;padding:10px 14px;background:var(--bg);min-height:52px}
        .choices input{width:22px;height:22px}
        .toolrow{display:grid;grid-template-columns:2fr 1fr auto;gap:8px;align-items:center;margin-bottom:8px}@media (max-width:640px){.toolrow{grid-template-columns:1fr 1fr}.toolrow .check{grid-column:1/-1}}
        .billing-switch{display:inline-flex;gap:4px;padding:4px;border:1px solid var(--line);border-radius:999px;background:var(--bg);margin:4px 0 14px}.billing-switch label{display:flex;align-items:center;gap:8px;margin:0;padding:8px 16px;border-radius:999px;font-weight:600;cursor:pointer}.billing-switch input{position:absolute;opacity:0;pointer-events:none}.billing-switch label:has(input:checked){background:var(--card);box-shadow:0 1px 3px rgb(6 22 63/.12);color:var(--brand-deep)}.billing-switch label:has(input:focus-visible){outline:3px solid var(--brand);outline-offset:2px}.billing-switch .save{white-space:nowrap;font-size:13px;font-weight:700;color:var(--ok);background:var(--ok-2);border-radius:999px;padding:2px 8px}
        .plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;margin:0 0 14px}.plan{position:relative;display:grid;align-content:start;gap:6px;margin:0;padding:18px;border:2px solid var(--line);border-radius:var(--radius);background:var(--card);cursor:pointer;font-weight:400}.plan input{position:absolute;top:16px;right:16px;width:22px;height:22px}.plan:has(input:checked){border-color:var(--brand);box-shadow:0 0 0 4px var(--brand-2)}.plan:has(input:focus-visible){outline:3px solid var(--brand);outline-offset:2px}.plan-name{font-weight:800;font-size:19px;padding-right:30px}.plan-price strong{font-size:30px}.plan ul{margin:6px 0 0;padding-left:18px}.plan li{margin:2px 0}
        .stepper{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;list-style:none;padding:0;margin:8px 0 10px;counter-reset:s}.stepper li{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--card);font-weight:600;font-size:15px;color:var(--muted)}.stepper li a{display:flex;align-items:center;gap:8px;color:inherit;text-decoration:none}.stepper .dot{flex:none;display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;background:var(--tint);color:var(--muted);font-size:14px}.stepper li.done{color:var(--ink)}.stepper li.done .dot{background:var(--ok-2);color:var(--ok)}.stepper li.now{border-color:var(--brand);color:var(--brand-deep);box-shadow:0 0 0 3px var(--brand-2)}.stepper li.now .dot{background:var(--brand);color:#fff}@media (max-width:720px){.stepper{grid-template-columns:repeat(2,1fr)}}
        .step-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin:6px 0 18px}
        .designs{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}.design{position:relative;display:grid;align-content:start;gap:6px;margin:0;padding:12px;border:2px solid var(--line);border-radius:var(--radius);background:var(--card);cursor:pointer;font-weight:400}.design>input{position:absolute;opacity:0;pointer-events:none}.design:has(input:checked) .design-name::after{content:'Chosen';margin-left:8px;font-size:12px;font-weight:700;color:#fff;background:var(--brand);border-radius:999px;padding:2px 9px;vertical-align:2px}.design:has(input:checked){border-color:var(--brand);box-shadow:0 0 0 4px var(--brand-2)}.design:has(input:focus-visible){outline:3px solid var(--brand);outline-offset:2px}.mini{position:relative;display:grid;grid-template-rows:auto 1fr auto;height:230px;border-radius:10px;border:1px solid var(--line);overflow:hidden}.mini-nav{position:relative;z-index:2;display:flex;align-items:center;gap:6px;font-size:11px;padding:9px 12px}.mini-nav b{flex:1;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.mini-nav i{width:16px;height:3px;border-radius:2px;background:currentColor;opacity:.45}.mini-hero{position:relative;display:grid;align-items:end;margin-top:-35px;padding:44px 12px 12px;background:var(--photo) center/cover no-repeat;color:#fff}.mini-hero::before{content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgb(0 0 0/.15),rgb(0 0 0/.6))}.mini-copy{position:relative;display:grid;gap:7px}.mini-title{font-size:17px;line-height:1.1;font-weight:600;max-width:92%;text-shadow:0 1px 6px rgb(0 0 0/.35)}.mini-search{display:flex;gap:3px;max-width:80%;padding:3px;border-radius:999px;background:rgb(255 255 255/.9)}.mini-search span:first-child{flex:1;height:12px}.mini-search span:last-child{width:38px;height:12px;border-radius:999px;background:var(--brand)}.mini-homes{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;padding:8px 10px 10px}.mini-homes i{height:38px;border-radius:5px;background:center/cover no-repeat #ddd}.m-hdr-overlay .mini-nav,.m-hdr-centered .mini-nav{color:#fff;text-shadow:0 1px 4px rgb(0 0 0/.4)}.m-hdr-solid .mini-hero{margin-top:0;padding-top:16px}.m-hdr-centered .mini-nav{justify-content:center;letter-spacing:.14em;text-transform:uppercase}.m-hdr-centered .mini-nav b{flex:none}.m-hdr-centered .mini-nav i{display:none}.m-hero-center .mini-hero{align-items:center;justify-items:center;text-align:center}.m-hero-center .mini-copy{justify-items:center;width:100%}.m-hero-center .mini-search{margin-inline:auto;width:78%;max-width:78%}.m-hero-split .mini-nav{color:inherit;text-shadow:none}.m-hero-split .mini-hero{margin-top:0;padding:16px 12px;align-items:center;background:none;color:inherit}.m-hero-split .mini-hero::before{left:52%;background:var(--photo) center/cover no-repeat}.m-hero-split .mini-copy{max-width:50%}.m-hero-split .mini-title{text-shadow:none}.m-hero-split .mini-search{max-width:100%;background:rgb(0 0 0/.08)}.m-hero-framed .mini-hero{margin:0 8px;border-radius:7px;padding:14px 10px;align-items:center}.m-hero-framed .mini-hero::before{background:linear-gradient(180deg,transparent,rgb(0 0 0/.2))}.m-hero-framed .mini-copy{background:rgb(255 255 255/.94);color:#111;padding:9px;border-radius:5px;max-width:62%}.m-hero-framed .mini-title{text-shadow:none;font-size:14px}.m-hero-framed .mini-search{background:rgb(0 0 0/.07)}.m-shape-square .mini-homes i,.m-shape-square .mini-search,.m-shape-square .mini-search span:last-child{border-radius:0}.m-shape-round .mini-homes i{border-radius:9px}#idx-boards [hidden]{display:none}.design-name{font-weight:800;font-size:17px}.tag{font-size:12px;font-weight:700;color:var(--warn);background:var(--warn-2);border-radius:999px;padding:2px 8px;vertical-align:2px}
        .review{display:grid;gap:0;margin:0 0 14px}.review div{display:grid;grid-template-columns:160px 1fr auto;gap:12px;align-items:start;padding:12px 0;border-bottom:1px solid var(--line)}.review dt{font-weight:700}.review dd{margin:0}@media (max-width:640px){.review div{grid-template-columns:1fr auto}.review dd{grid-column:1/-1;grid-row:2}}
        .pay-today{font-weight:600}.pay-today:empty{display:none}
        .slug-status{display:block;font-weight:600;min-height:1.2em}.slug-status.ok{color:var(--ok)}.slug-status.bad{color:var(--bad)}.linkish{border:0;background:none;color:var(--brand);font:inherit;font-weight:700;text-decoration:underline;cursor:pointer;padding:0}
        .two{display:grid;grid-template-columns:1fr 1fr;gap:12px;align-items:start}@media (max-width:640px){.two{grid-template-columns:1fr}}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:56px;padding:0 26px;border-radius:14px;border:0;background:var(--brand);color:#fff;font-family:inherit;font-size:19px;font-weight:700;line-height:1;cursor:pointer;text-decoration:none}
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
        .hp{position:absolute;left:-5000px;width:1px;height:1px;overflow:hidden}
        .actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center}
    </style>
    @include('marketing::partials.chrome-css')
    <style>footer.site{margin-top:56px}</style>
    @stack('head')
</head>
<body>
<a class="skip" href="#main">{{ \Modules\Billing\HealthCheck\HealthCopy::t($L, 'skip') }}</a>
{{-- SITE2: the same header and footer as rightally.app. --}}
@php($chrome = \Modules\Marketing\SiteChrome::for($L, url()->current(), $alternates ?? []))
@include('marketing::partials.site-header', $chrome)
<main id="main">
    @yield('main')
</main>
@include('marketing::partials.site-footer', $chrome)
</body>
</html>

{{--
    RightAlly's public website (SG1, specs/marketing/01): server-rendered, readable without JavaScript, clear text and
    big targets for every reader, built to the SEO and AEO standard (specs/websites/04). SITE1: sizes follow common B2B
    SaaS practice (17px body, headings up to 34px, headline up to 52px).
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
    <meta name="theme-color" content="#04163F">
    <link rel="icon" href="/favicon.ico?v=br1" sizes="any">
    <link rel="icon" href="/favicon.svg?v=br1" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=br1">
    <script type="application/ld+json">{!! $jsonld !!}</script>
    <style>
        /* BR3: RightAlly's website. Deep navy and the logo's blue; calm, readable type. */
        :root{--ink:#0A1330;--muted:#4E5B76;--line:#E3E8F2;--bg:#FFFFFF;--tint:#F4F6FB;--card:#FFFFFF;--brand:#155DFC;--brand-deep:#06369E;--navy:#04163F;--gold:#E2B65C;--radius:18px;
            --shadow:0 1px 2px rgb(6 22 63/.04),0 14px 36px -18px rgb(6 22 63/.22)}
        *{box-sizing:border-box}html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 17px/1.65 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;-webkit-font-smoothing:antialiased}
        a{color:var(--brand-deep)}:focus-visible{outline:3px solid var(--brand);outline-offset:3px;border-radius:6px}
        .wrap{max-width:1180px;margin:0 auto;padding:0 20px}
        .skip{position:absolute;left:-999px}.skip:focus{left:16px;top:8px;background:#fff;padding:8px 12px;z-index:99}
        h1{font-size:clamp(34px,4.4vw,52px);line-height:1.08;letter-spacing:-.03em;margin:0 0 18px;font-weight:700}
        h2{font-size:clamp(24px,2.6vw,34px);line-height:1.18;letter-spacing:-.02em;margin:0 0 12px;font-weight:700}
        h3{margin:0 0 6px;font-size:19px;letter-spacing:-.01em;font-weight:650}
        section{padding:64px 0}section.tinted{background:var(--tint)}
        .lead{color:var(--muted);font-size:19px;max-width:60ch;margin:0 0 28px}.muted{color:var(--muted)}.small{font-size:15px}
        /* SITE1: section headings and card groups are centred; paragraphs and questions stay left-aligned to read easily. */
        .head{max-width:760px;margin:0 auto 36px;text-align:center}.head .lead{margin-left:auto;margin-right:auto}
        .hero{position:relative;overflow:hidden;background:var(--navy);color:#fff;padding:96px 0 88px}
        .hero::before{content:"";position:absolute;inset:0;opacity:.13;background-image:linear-gradient(to right,#8FB3FF 1px,transparent 1px),linear-gradient(to bottom,#8FB3FF 1px,transparent 1px);background-size:48px 48px;-webkit-mask-image:radial-gradient(ellipse at 20% 30%,#000 15%,transparent 70%);mask-image:radial-gradient(ellipse at 20% 30%,#000 15%,transparent 70%)}
        .hero::after{content:"";position:absolute;right:-180px;top:-200px;width:720px;height:720px;border-radius:50%;background:var(--brand);opacity:.45;filter:blur(140px)}
        .hero .wrap{position:relative;z-index:1}.hero:not(.split) .wrap{text-align:center}.hero:not(.split) .lead{margin-left:auto;margin-right:auto}
        .hero.split .wrap{display:grid;gap:56px;align-items:center}
        @media (min-width:1000px){.hero.split .wrap{grid-template-columns:1.05fr .95fr}}
        .hero .lead{color:#C9D6F5}
        .hero .btn.ghost{color:#fff;border-color:rgb(255 255 255/.28);background:rgb(255 255 255/.06)}
        .hero .btn.ghost:hover{background:rgb(255 255 255/.12)}
        .facts{display:flex;flex-wrap:wrap;gap:10px 22px;margin:34px 0 0;padding:0;list-style:none;color:#AFC0E6;font-size:16px}
        .facts li{display:flex;gap:8px;align-items:center}.facts li::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--gold)}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:54px;padding:0 26px;border-radius:14px;border:1px solid transparent;background:var(--brand);color:#fff;font-family:inherit;font-weight:650;font-size:17px;line-height:1;text-decoration:none;margin:6px 10px 0 0;box-shadow:0 1px 0 rgb(255 255 255/.18) inset,0 10px 24px -10px rgb(21 93 252/.75);transition:background .15s}
        .btn:hover{background:#0F4FDB}
        .btn.ghost{background:transparent;color:var(--brand-deep);border-color:var(--line);box-shadow:none}
        .btn.small{min-height:44px;padding:0 18px;font-size:16px;margin:0}
        /* The product preview beside the home page headline: an illustration of the daily brief. */
        .preview-app{background:#fff;color:var(--ink);border-radius:22px;padding:18px;box-shadow:0 40px 80px -30px rgb(0 0 0/.6),0 0 0 1px rgb(255 255 255/.08);transform:perspective(1600px) rotateY(-6deg) rotateX(2deg)}
        .preview-app .band{border-radius:14px;background:var(--navy);color:#fff;padding:18px 20px;position:relative;overflow:hidden}
        .preview-app .band::after{content:"";position:absolute;right:-60px;top:-80px;width:220px;height:220px;border-radius:50%;background:var(--brand);opacity:.55;filter:blur(50px)}
        .preview-app .band b{display:block;font-size:22px;letter-spacing:-.02em;position:relative}.preview-app .band span{font-size:13px;color:#AFC0E6;position:relative}
        .preview-app ul{list-style:none;margin:14px 0 0;padding:0;display:grid;gap:10px}
        .preview-app li{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center;border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:15px;line-height:1.35}
        .preview-app li i{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-style:normal;font-weight:700;font-size:13px}
        .preview-app li small{display:block;color:var(--muted);font-size:13px}
        .preview-app .tag{font-size:12px;font-weight:700;border-radius:999px;padding:4px 10px;background:#EAF0FF;color:#0B3FB8}
        @media (max-width:999px){.preview-app{transform:none}}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:18px}
        .card{background:var(--card);border:1px solid rgb(6 22 63/.07);border-radius:var(--radius);padding:26px;box-shadow:var(--shadow)}
        .card h3{font-size:19px}
        .feature .ico{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(180deg,#2A6BFF,#0B3FB8);color:#fff;margin-bottom:16px;box-shadow:0 8px 18px -8px rgb(21 93 252/.8)}
        .feature .ico svg{width:22px;height:22px}.modules .card{padding:22px}.modules .card p{margin:6px 0 0;font-size:16px;color:var(--muted)}
        .answer{background:linear-gradient(180deg,#F4F7FF,#fff);border:1px solid #DCE5FB;border-radius:var(--radius);padding:30px 32px}
        .answer{max-width:880px;margin:0 auto}.answer h2{font-size:clamp(22px,2.2vw,28px)}
        main details{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px 22px;margin:0 0 12px}
        main details[open]{box-shadow:var(--shadow)}main summary{font-weight:650;cursor:pointer;min-height:32px;font-size:17px}
        main details p{margin:12px 0 0;color:var(--muted)}
        table{width:100%;border-collapse:separate;border-spacing:0;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
        td,th{padding:14px 16px;border-top:1px solid var(--line);text-align:left;vertical-align:top}thead th{background:var(--tint);border-top:0}
        .tablewrap{overflow-x:auto}
        .price{font-size:38px;font-weight:750;letter-spacing:-.03em;margin:8px 0 2px}.price span{letter-spacing:0;font-weight:500;font-size:16px}
        .plans{grid-template-columns:repeat(auto-fit,minmax(260px,360px));justify-content:center}.price span.per{display:block;font-size:16px;margin-top:2px}.plans .card{display:flex;flex-direction:column}.plans .card .btn{margin-top:auto;width:100%}
        .plans .card.best{border:2px solid var(--brand);position:relative}
        .cta-band .btn,.hero:not(.split) .btn{margin:6px 5px 0}
        section:has(>.prose)+section:has(>.prose){padding-top:0}.prose h2{font-size:clamp(20px,2vw,24px)}.prose{margin:0 auto}
        .modules .grid{display:flex;flex-wrap:wrap;justify-content:center}.modules .grid>.card{flex:0 1 calc((100% - 54px)/4);min-width:240px}
        @media (max-width:640px){.modules .grid>.card{flex-basis:100%}}.plans+.small{text-align:center;margin-top:18px}
        .plans .card.best::before{content:attr(data-best);position:absolute;top:-14px;left:24px;background:var(--brand);color:#fff;font-size:13px;font-weight:700;border-radius:999px;padding:4px 12px}
        .doors .door{display:grid;gap:10px;text-decoration:none;color:var(--ink);padding:34px;transition:border-color .15s,transform .15s}
        .doors .door:hover,.doors .door:focus-visible{border-color:var(--brand);transform:translateY(-2px)}.doors .door h3{font-size:24px}
        .doors .door .go{color:var(--brand-deep);font-weight:700}
        ol.steps{counter-reset:s;list-style:none;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:18px}
        ol.steps li{counter-increment:s;background:var(--card);border:1px solid rgb(6 22 63/.07);border-radius:var(--radius);padding:26px;box-shadow:var(--shadow)}
        ol.steps li::before{content:counter(s);width:40px;height:40px;border-radius:12px;background:var(--navy);color:#fff;display:grid;place-items:center;font-weight:800;margin-bottom:16px}
        blockquote{margin:0;font-size:18px}.prose{max-width:72ch}.prose img{max-width:100%;height:auto}
        .cta-band{position:relative;overflow:hidden;background:var(--navy);color:#fff;border-radius:26px;padding:56px 48px;text-align:center}.cta-band .lead{margin-left:auto;margin-right:auto}
        .cta-band::after{content:"";position:absolute;right:-120px;bottom:-200px;width:520px;height:520px;border-radius:50%;background:var(--brand);opacity:.5;filter:blur(120px)}
        .cta-band>*{position:relative;z-index:1}.cta-band .lead{color:#C9D6F5}
        /* SITE1: the "what do you pay today" boxes line up: label on top, every input on the same line. */
        .savings{max-width:980px;margin:0 auto;text-align:center}.savings .lead{margin-left:auto;margin-right:auto}
        .savings .grid{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));align-items:stretch;text-align:left;margin:8px 0 20px}
        .savings label{display:flex;flex-direction:column;justify-content:space-between;gap:8px;line-height:1.35;font-weight:600;font-size:15px}
        .savings input{border:1px solid #C9D2E3;border-radius:10px;background:#fff;font-family:inherit;width:100%;font-size:17px;padding:10px 12px;min-height:48px}
        .savings [data-savings-result]{margin:0 auto;font-weight:600;color:var(--ink)}
        .plans .includes{list-style:none;margin:14px 0 18px;padding:14px 0 0;border-top:1px solid var(--line);display:grid;gap:8px;font-size:15px;color:var(--ink)}
        .plans .includes li{display:grid;grid-template-columns:18px 1fr;gap:8px;line-height:1.4}
        .plans .includes li::before{content:"";width:18px;height:18px;margin-top:1px;border-radius:50%;background:#E6EEFF url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23155DFC'%3E%3Cpath d='M8.2 13.6 4.9 10.3l1.2-1.2 2.1 2.1 5.7-5.7 1.2 1.2z'/%3E%3C/svg%3E") center/14px no-repeat}
        .plans .includes .more{color:var(--muted)}.plans .includes .more::before{background:none}
        /* SITE1: language selector. */
        .preview{background:#fef3c7;color:#78350f;text-align:center;padding:8px;font-weight:700}
        @media (prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
    </style>
    @include('marketing::partials.chrome-css')
</head>
<body>
<a class="skip" href="#main">{{ $L === 'es' ? 'Ir al contenido' : 'Skip to content' }}</a>
@if ($preview)<div class="preview" role="status">{{ $L === 'es' ? 'Vista previa privada: aún no se publica' : 'Private preview: not published yet' }}</div>@endif
@include('marketing::partials.site-header')
<main id="main">
    @yield('content')
</main>
@include('marketing::partials.site-footer')
</body>
</html>
